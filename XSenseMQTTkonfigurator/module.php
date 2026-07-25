<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/XSenseMQTTHelper.php';

class XSenseMQTTKonfigurator extends IPSModuleStrict
{
    use XSenseMQTTHelper;
    private const BRIDGE_MODULE_GUID = '{3B3A2F6D-7E9B-4F2A-9C6A-1F2E3D4C5B6A}';
    private const DEVICE_MODULE_GUID = '{C523B0B6-870E-9726-778A-0FF5C6E9656E}';

    private const STATUS_ACTIVE = 102;
    private const STATUS_NO_BRIDGE = 104;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterTimer('UpdateOverview', 30000, 'XSNK_UpdateOverview($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->maintainOverviewVariables();

        $bridgeId = $this->getBridgeId();
        if ($bridgeId <= 0) {
            $this->SetStatus(self::STATUS_NO_BRIDGE);
            $this->UpdateOverview();
            return;
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        $this->UpdateOverview();
    }

    public function UpdateOverview(): void
    {
        $counts = [
            'devices'  => 0,
            'online'   => 0,
            'smoke'    => 0,
            'fault'    => 0,
            'battery'  => 0,
            'lifeEnd'  => 0
        ];

        $instances = @IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID);
        if (!is_array($instances)) {
            $instances = [];
        }

        foreach ($instances as $instanceId) {
            if (!@IPS_InstanceExists($instanceId)) {
                continue;
            }
            $counts['devices']++;
            $counts['online'] += $this->readDeviceBoolean($instanceId, 'Connectivity') ? 1 : 0;
            $counts['smoke'] += $this->readDeviceBoolean($instanceId, 'Smoke') ? 1 : 0;
            $counts['fault'] += $this->readDeviceBoolean($instanceId, 'SmokeFault') ? 1 : 0;
            $counts['battery'] += $this->readDeviceBoolean($instanceId, 'Battery') ? 1 : 0;
            $counts['lifeEnd'] += $this->readDeviceBoolean($instanceId, 'LifeEnd') ? 1 : 0;
        }

        $offline = max(0, $counts['devices'] - $counts['online']);
        $alarm = $counts['smoke'] > 0
            || $counts['fault'] > 0
            || $counts['battery'] > 0
            || $counts['lifeEnd'] > 0
            || $offline > 0;

        $status = match (true) {
            $counts['smoke'] > 0   => sprintf('Rauchalarm: %d', $counts['smoke']),
            $counts['fault'] > 0   => sprintf('Störung: %d', $counts['fault']),
            $counts['lifeEnd'] > 0 => sprintf('Lebensdauer erreicht: %d', $counts['lifeEnd']),
            $counts['battery'] > 0 => sprintf('Batterie schwach: %d', $counts['battery']),
            $offline > 0           => sprintf('Offline: %d', $offline),
            $counts['devices'] > 0 => sprintf('Alle %d Rauchmelder OK', $counts['devices']),
            default                => 'Keine Rauchmelder angelegt'
        };

        $this->SetValue('SystemStatus', $status);
        $this->SetValue('AnyAlarm', $alarm);
        $this->SetValue('DeviceCount', $counts['devices']);
        $this->SetValue('OfflineCount', $offline);
        $this->SetValue('SmokeCount', $counts['smoke']);
        $this->SetValue('FaultCount', $counts['fault']);
        $this->SetValue('BatteryCount', $counts['battery']);
        $this->SetValue('LifeEndCount', $counts['lifeEnd']);
        $this->SetValue('LastOverviewUpdate', time());
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::BRIDGE_MODULE_GUID]]);
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $bridgeId = $this->getBridgeId();
        $cacheCount = count($this->readCache());
        $form['elements'][0]['caption'] = $bridgeId > 0
            ? sprintf($this->t('Bridge #%d, Cache: %d entries'), $bridgeId, $cacheCount)
            : $this->t('No Bridge found');

        $form['elements'][2]['values'] = $this->buildDeviceValues();

        return json_encode($form);
    }
    
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'RefreshCache') {
            $this->RefreshCache();
        }
    }

    public function RefreshCache(): void
    {
        $bridgeId = $this->getBridgeId();
        if ($bridgeId > 0) {
            @XSNB_ReplayDiscovery($bridgeId, '');
        }
        $this->ReloadForm();
    }


    public function SyncDiscoveryToDevices(): void
    {
        $cache = $this->readCache();
        $this->debug('SyncDiscovery', sprintf('Cache has %d entries', count($cache)));
        
        if (empty($cache)) {
            $this->debug('SyncDiscovery', 'Cache is empty - nothing to sync');
            return;
        }

        $instances = IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID);
        $this->debug('SyncDiscovery', sprintf('Found %d device instances', count($instances)));
        
        foreach ($instances as $instanceId) {
            $deviceId = trim((string)@IPS_GetProperty($instanceId, 'DeviceId'));
            $this->debug('SyncDiscovery', sprintf('Instance %d has DeviceId=%s', $instanceId, $deviceId));
            
            if ($deviceId === '') {
                continue;
            }

            $matchCount = 0;
            foreach ($cache as $uniqueId => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entryDeviceId = $this->getTopicToken((string)($entry['state_topic'] ?? ''), 3);
                if ($entryDeviceId === '') {
                    $entryDeviceId = (string)($entry['device']['id'] ?? '');
                }
                
                $this->debug('SyncDiscovery', sprintf('Entry %s has DeviceId=%s (comparing to %s)', $uniqueId, $entryDeviceId, $deviceId));
                
                if ($entryDeviceId === '' || strcasecmp($entryDeviceId, $deviceId) !== 0) {
                    continue;
                }
                
                $matchCount++;
                $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->debug('SyncDiscovery', sprintf('Sending to instance %d: %s', $instanceId, substr($json, 0, 150)));
                @XSND_UpdateDiscovery($instanceId, $json);
            }
            
            $this->debug('SyncDiscovery', sprintf('Sent %d entries to instance %d', $matchCount, $instanceId));
        }
    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
    }

    private function maintainOverviewVariables(): void
    {
        $this->MaintainVariable('SystemStatus', 'Systemzustand', 3, '', 1, true);
        $this->MaintainVariable('AnyAlarm', 'Gesamtalarm', 0, '', 2, true);
        $this->MaintainVariable('DeviceCount', 'Rauchmelder gesamt', 1, '', 3, true);
        $this->MaintainVariable('OfflineCount', 'Rauchmelder offline', 1, '', 4, true);
        $this->MaintainVariable('SmokeCount', 'Rauchalarm', 1, '', 5, true);
        $this->MaintainVariable('FaultCount', 'Störungen', 1, '', 6, true);
        $this->MaintainVariable('BatteryCount', 'Batterie schwach', 1, '', 7, true);
        $this->MaintainVariable('LifeEndCount', 'Lebensdauer erreicht', 1, '', 8, true);
        $this->MaintainVariable(
            'LastOverviewUpdate',
            'Übersicht aktualisiert',
            1,
            ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME],
            9,
            true
        );
    }

    private function readDeviceBoolean(int $instanceId, string $ident): bool
    {
        $variableId = @IPS_GetObjectIDByIdent($ident, $instanceId);
        if ($variableId === false || !@IPS_VariableExists($variableId)) {
            return false;
        }
        return (bool)@GetValue($variableId);
    }

    private function getBridgeId(): int
    {
        $parentId = $this->getParentId();
        if ($this->isBridgeInstance($parentId)) {
            return $parentId;
        }

        $parentId = $this->connectToBridgeParent();
        if ($parentId > 0) {
            return $parentId;
        }

        return $this->findBridgeInstance();
    }

    private function getParentId(): int
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        return is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
    }

    private function connectToBridgeParent(): int
    {
        $bridgeId = $this->findBridgeInstance();
        if ($bridgeId <= 0 || !function_exists('IPS_ConnectInstance')) {
            return 0;
        }

        if (function_exists('IPS_IsInstanceCompatible') && !@IPS_IsInstanceCompatible($this->InstanceID, $bridgeId)) {
            $this->debug('ApplyChanges', sprintf('Bridge #%d is not compatible', $bridgeId));
            return 0;
        }

        if (!@IPS_ConnectInstance($this->InstanceID, $bridgeId)) {
            $this->debug('ApplyChanges', sprintf('Could not connect to Bridge #%d', $bridgeId));
            return 0;
        }

        return $this->getParentId();
    }

    private function findBridgeInstance(): int
    {
        $bridges = @IPS_GetInstanceListByModuleID(self::BRIDGE_MODULE_GUID);
        if (!is_array($bridges) || empty($bridges)) {
            return 0;
        }
        foreach ($bridges as $bridgeId) {
            if (@IPS_InstanceExists($bridgeId)) {
                $status = (int)(@IPS_GetInstance($bridgeId)['InstanceStatus'] ?? 0);
                if ($status === self::STATUS_ACTIVE) {
                    return $bridgeId;
                }
            }
        }
        // Return first bridge even if not active
        return (int)$bridges[0];
    }

    private function isBridgeInstance(int $instanceId): bool
    {
        if ($instanceId <= 0 || !@IPS_InstanceExists($instanceId)) {
            return false;
        }
        $inst = @IPS_GetInstance($instanceId);
        return is_array($inst) && (($inst['ModuleInfo']['ModuleID'] ?? '') === self::BRIDGE_MODULE_GUID);
    }

    private function readCache(): array
    {
        $bridgeId = $this->getBridgeId();
        if ($bridgeId <= 0) {
            return [];
        }
        $raw = @XSNB_GetDiscoveryCache($bridgeId);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $bridgeCache = json_decode($raw, true);
        if (!is_array($bridgeCache)) {
            return [];
        }
        $result = [];
        foreach ($bridgeCache as $topic => $payload) {
            if (!is_string($payload) || $payload === '') {
                continue;
            }
            $decoded = $this->decodePayload($payload);
            $entry = json_decode($decoded, true);
            if (!is_array($entry)) {
                continue;
            }
            $uniqueId = (string)($entry['unique_id'] ?? '');
            if ($uniqueId === '') {
                $uniqueId = $this->getTopicToken($topic, 2);
            }
            if ($uniqueId !== '') {
                $result[$uniqueId] = $entry;
            }
        }
        $this->debug('readCache', sprintf('Bridge #%d: %d entries', $bridgeId, count($result)));
        return $result;
    }

    private function findDeviceInstance(string $deviceId): int
    {
        $instances = IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID);
        foreach ($instances as $instanceId) {
            $value = (string)@IPS_GetProperty($instanceId, 'DeviceId');
            if ($value !== '' && strcasecmp($value, $deviceId) === 0) {
                return $instanceId;
            }
        }
        return 0;
    }

    private function buildDeviceValues(): array
    {
        $cache = $this->readCache();
        $devices = [];
        foreach ($cache as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $device = isset($entry['device']) && is_array($entry['device']) ? $entry['device'] : [];
            $deviceId = $this->getTopicToken((string)($entry['state_topic'] ?? ''), 3);
            if ($deviceId === '') {
                $deviceId = (string)($device['id'] ?? '');
            }
            if ($deviceId === '') {
                continue;
            }
            if (!isset($devices[$deviceId])) {
                $devices[$deviceId] = [
                    'id'       => $deviceId,
                    'name'     => (string)($device['name'] ?? $deviceId),
                    'model'    => (string)($device['model'] ?? ''),
                    'entities' => []
                ];
            }
            // Extract entity type from unique_id (e.g., SBS50148995FA_00000001_online -> online)
            $uniqueId = (string)($entry['unique_id'] ?? '');
            $entityType = '';
            if ($uniqueId !== '') {
                $parts = explode('_', $uniqueId);
                if (count($parts) >= 3) {
                    $entityType = end($parts);
                }
            }
            if ($entityType !== '' && !in_array($entityType, $devices[$deviceId]['entities'], true)) {
                $devices[$deviceId]['entities'][] = $entityType;
            }
        }

        ksort($devices, SORT_NATURAL | SORT_FLAG_CASE);

        $values = [];
        foreach ($devices as $device) {
            $instanceId = $this->findDeviceInstance($device['id']);
            $name = $this->formatInstanceName((string)$device['name']);
            $createConfig = [
                'moduleID'      => self::DEVICE_MODULE_GUID,
                'configuration' => ['DeviceId' => $device['id']],
                'name'          => $name
            ];
            $values[] = [
                'name'       => $name,
                'deviceId'   => $device['id'],
                'model'      => $device['model'],
                'entities'   => implode(', ', $device['entities']),
                'instanceID' => $instanceId,
                'create'     => $createConfig
            ];
        }
        return $values;
    }

    private function formatInstanceName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return $name;
        }
        $pos = strpos($name, '(');
        if ($pos === false || $pos === 0) {
            return $name;
        }
        if ($name[$pos - 1] === ' ') {
            return $name;
        }
        return substr($name, 0, $pos) . ' ' . substr($name, $pos);
    }

}
