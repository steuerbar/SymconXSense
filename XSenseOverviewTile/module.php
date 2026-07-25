<?php

declare(strict_types=1);

class XSenseRauchmelderKachel extends IPSModule
{
    private const DEVICE_MODULE_GUID = '{C81A4E36-2B95-47D0-8F62-73A5CE190B44}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('RefreshInterval', 30);
        $this->RegisterPropertyInteger('GridColumns', 4);
        $this->RegisterPropertyBoolean('ShowDeviceId', false);
        $this->RegisterAttributeString('SubscribedVariables', '[]');
        $this->RegisterTimer('RefreshTile', 30000, 'XSOT_Refresh($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval(
            'RefreshTile',
            max(5, min(300, $this->ReadPropertyInteger('RefreshInterval'))) * 1000
        );
        $this->RefreshSubscriptions();
        $this->Refresh();
    }

    public function MessageSink($timeStamp, $senderID, $message, $data)
    {
        parent::MessageSink($timeStamp, $senderID, $message, $data);
        if ($message === IPS_KERNELMESSAGE && ($data[0] ?? null) === KR_READY) {
            $this->RefreshSubscriptions();
            $this->Refresh();
            return;
        }
        if ($message === VM_UPDATE) {
            $this->Refresh();
        }
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $image = file_get_contents(__DIR__ . '/assets/smoke-detector-comic-256.png');
        return str_replace(
            ['__INITIAL_STATE__', '__PRODUCT_IMAGE__'],
            [$this->StateJSON(), base64_encode($image)],
            $html
        );
    }

    public function Refresh(): void
    {
        $state = $this->BuildState();
        $this->SetStatus(count($state['devices']) > 0 ? 102 : 104);
        $this->UpdateVisualizationValue((string)json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        ));
    }

    private function RefreshSubscriptions(): void
    {
        $oldIds = json_decode($this->ReadAttributeString('SubscribedVariables'), true);
        if (is_array($oldIds)) {
            foreach ($oldIds as $variableId) {
                if (is_int($variableId) && @IPS_VariableExists($variableId)) {
                    $this->UnregisterMessage($variableId, VM_UPDATE);
                }
            }
        }

        $newIds = [];
        foreach ($this->GetDeviceIds() as $deviceId) {
            foreach (['Connectivity', 'Smoke', 'SmokeFault', 'Battery', 'LifeEnd', 'LastSeen', 'OverallStatus'] as $ident) {
                $variableId = @IPS_GetObjectIDByIdent($ident, $deviceId);
                if ($variableId !== false && @IPS_VariableExists($variableId)) {
                    $this->RegisterMessage($variableId, VM_UPDATE);
                    $newIds[] = $variableId;
                }
            }
        }
        $this->WriteAttributeString('SubscribedVariables', json_encode(array_values(array_unique($newIds))));
    }

    private function BuildState(): array
    {
        $devices = [];
        foreach ($this->GetDeviceIds() as $instanceId) {
            $instance = @IPS_GetInstance($instanceId);
            $object = @IPS_GetObject($instanceId);
            $configuration = json_decode((string)@IPS_GetConfiguration($instanceId), true);
            $deviceId = is_array($configuration) ? (string)($configuration['DeviceId'] ?? '') : '';
            $name = is_array($object) ? trim((string)($object['ObjectName'] ?? '')) : '';

            $devices[] = [
                'instanceId' => $instanceId,
                'deviceId'   => $deviceId,
                'name'       => $name,
                'active'     => is_array($instance) && (int)($instance['InstanceStatus'] ?? 0) === 102,
                'online'     => $this->ReadBool($instanceId, 'Connectivity'),
                'smoke'      => $this->ReadBool($instanceId, 'Smoke'),
                'fault'      => $this->ReadBool($instanceId, 'SmokeFault'),
                'battery'    => $this->ReadBool($instanceId, 'Battery'),
                'lifeEnd'    => $this->ReadBool($instanceId, 'LifeEnd'),
                'lastSeen'   => $this->ReadInt($instanceId, 'LastSeen'),
                'status'     => $this->ReadString($instanceId, 'OverallStatus', 'Unbekannt')
            ];
        }

        usort($devices, static fn(array $a, array $b): int => strnatcasecmp($a['deviceId'], $b['deviceId']));
        foreach ($devices as $index => &$device) {
            if ($device['name'] === '' || preg_match('/^Smoke Alarm\s*\(/i', $device['name']) === 1) {
                $device['name'] = 'Rauchmelder ' . ($index + 1);
            }
        }
        unset($device);

        $counts = [
            'total'   => count($devices),
            'offline' => 0,
            'smoke'   => 0,
            'fault'   => 0,
            'battery' => 0,
            'lifeEnd' => 0
        ];
        foreach ($devices as $device) {
            $counts['offline'] += $device['online'] ? 0 : 1;
            $counts['smoke'] += $device['smoke'] ? 1 : 0;
            $counts['fault'] += $device['fault'] ? 1 : 0;
            $counts['battery'] += $device['battery'] ? 1 : 0;
            $counts['lifeEnd'] += $device['lifeEnd'] ? 1 : 0;
        }

        $alarm = $counts['offline'] + $counts['smoke'] + $counts['fault'] + $counts['battery'] + $counts['lifeEnd'] > 0;
        $headline = match (true) {
            $counts['smoke'] > 0   => 'Rauchalarm erkannt',
            $counts['fault'] > 0   => 'Gerätestörung erkannt',
            $counts['lifeEnd'] > 0 => 'Lebensdauer erreicht',
            $counts['battery'] > 0 => 'Batteriewarnung',
            $counts['offline'] > 0 => 'Rauchmelder offline',
            $counts['total'] > 0   => 'Alle Rauchmelder OK',
            default                => 'Keine Rauchmelder gefunden'
        };

        return [
            'devices'      => $devices,
            'counts'       => $counts,
            'alarm'        => $alarm,
            'headline'     => $headline,
            'columns'      => max(2, min(6, $this->ReadPropertyInteger('GridColumns'))),
            'showDeviceId' => $this->ReadPropertyBoolean('ShowDeviceId'),
            'updated'      => time()
        ];
    }

    private function GetDeviceIds(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID);
        return is_array($ids) ? $ids : [];
    }

    private function ReadBool(int $instanceId, string $ident): bool
    {
        $id = @IPS_GetObjectIDByIdent($ident, $instanceId);
        return $id !== false && @IPS_VariableExists($id) ? (bool)@GetValue($id) : false;
    }

    private function ReadInt(int $instanceId, string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $instanceId);
        return $id !== false && @IPS_VariableExists($id) ? (int)@GetValue($id) : 0;
    }

    private function ReadString(int $instanceId, string $ident, string $default): string
    {
        $id = @IPS_GetObjectIDByIdent($ident, $instanceId);
        return $id !== false && @IPS_VariableExists($id) ? (string)@GetValue($id) : $default;
    }

    private function StateJSON(): string
    {
        return (string)json_encode(
            $this->BuildState(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        );
    }
}
