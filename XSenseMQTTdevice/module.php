<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/XSenseMQTTHelper.php';

class XSenseMQTTDevice extends IPSModuleStrict
{
    use XSenseMQTTHelper;
    private const BRIDGE_MODULE_GUID = '{5F2B7D91-8C43-4E0A-A6D5-1297B3C84E60}';
    private const BRIDGE_RX_GUID = '{D5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}'; // Bridge→Device

    private const STATUS_ACTIVE = 102;
    private const STATUS_NO_PARENT = 104;
    private const STATUS_PARENT_INACTIVE = 201;
    private const STATUS_DEVICE_ID_EMPTY = 202;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterAttributeString('Entities', '{}');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterTimer('RetryActivate', 0, 'XSND_RetryActivate($_IPS[\'TARGET\']);');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->ApplyChanges();
        }
    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetReceiveDataFilter('.*');

        $parentId = $this->getParentId();
        if ($parentId <= 0) {
            $this->SetTimerInterval('RetryActivate', 5000);
            $this->SetStatus(self::STATUS_NO_PARENT);
            return;
        }

        $parentStatus = 0;
        try {
            $parentStatus = (int)(@IPS_GetInstance($parentId)['InstanceStatus'] ?? 0);
        } catch (Throwable $e) {
            $parentStatus = 0;
        }
        if ($parentStatus !== self::STATUS_ACTIVE) {
            $this->SetTimerInterval('RetryActivate', 5000);
            $this->SetStatus(self::STATUS_PARENT_INACTIVE);
            return;
        }

        if (trim($this->ReadPropertyString('DeviceId')) === '') {
            $this->SetTimerInterval('RetryActivate', 0);
            $this->SetStatus(self::STATUS_DEVICE_ID_EMPTY);
            return;
        }

        $this->SetTimerInterval('RetryActivate', 0);
        $this->SetStatus(self::STATUS_ACTIVE);
        $this->updateReceiveDataFilter();
        $this->SetSummary($this->ReadPropertyString('DeviceId'));
        $this->maintainAllVariables();
        $this->debug('ApplyChanges', sprintf('Status=102, DeviceId=%s, ParentId=%d', $this->ReadPropertyString('DeviceId'), $parentId));
        $this->requestDiscovery();
        $this->updateOverallStatus();
    }

    public function RetryActivate(): void
    {
        $this->ApplyChanges();
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::BRIDGE_RX_GUID) {
            return '';
        }

        $topic = (string)($data['Topic'] ?? '');
        if ($topic === '') {
            return '';
        }

        $payload = $this->decodePayload($data['Payload'] ?? '');
        $this->debug('ReceiveData', sprintf($this->t('Topic=%s Payload=%s'), $topic, $payload));

        if ($this->isConfigTopic($topic)) {
            $this->processConfig($topic, $payload);
            return '';
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            $this->debug('State', $this->t('Invalid JSON payload'));
            return '';
        }

        $entities = $this->readEntities();
        $matches = $this->findEntitiesByTopic($topic, $entities);
        if (empty($matches)) {
            $this->debug('State', sprintf('No entity for topic=%s (entities=%d)', $topic, count($entities)));
            return '';
        }

        $updated = false;
        foreach ($matches as $entry) {
            $value = $this->extractValue($data, $entry);
            if ($value === null) {
                $this->debug('State', sprintf('No value for %s', $entry['ident'] ?? '?'));
                continue;
            }
            $this->processStatus($entry, $value);
            $updated = true;
        }

        if ($updated) {
            $this->SetValue('LastSeen', time());
            $this->updateOverallStatus();
        }

        return '';
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'    => 'connect',
            'moduleIDs' => [self::BRIDGE_MODULE_GUID]
        ]);
    }

    public function UpdateDiscovery(string $json): void
    {
        $this->debug('UpdateDiscovery', sprintf('Received: %s', substr($json, 0, 200)));

        $entry = json_decode($json, true);
        if (!is_array($entry)) {
            $this->debug('UpdateDiscovery', $this->t('Invalid JSON'));
            return;
        }
        $this->applyDiscoveryEntry($entry);
    }

    private function applyDiscoveryEntry(array $entry): void
    {
        $deviceId = $this->getTopicToken((string)($entry['state_topic'] ?? ''), 3);
        if ($deviceId === '') {
            $deviceId = $this->extractDeviceIdFromEntry($entry);
        }
        if ($deviceId !== '') {
            $entry['device']['id'] = $deviceId;
        }
        $this->debug('UpdateDiscovery', sprintf('Extracted DeviceId=%s from entry', $deviceId));

        if ($deviceId === '') {
            $this->debug('UpdateDiscovery', $this->t('DeviceId missing'));
            return;
        }

        $propertyDeviceId = trim($this->ReadPropertyString('DeviceId'));
        $this->debug('UpdateDiscovery', sprintf('PropertyDeviceId=%s, EntryDeviceId=%s', $propertyDeviceId, $deviceId));

        if ($propertyDeviceId === '' || strcasecmp($propertyDeviceId, $deviceId) !== 0) {
            $this->debug('UpdateDiscovery', sprintf('%s (property=%s, entry=%s)', $this->t('DeviceId mismatch'), $propertyDeviceId, $deviceId));
            return;
        }

        $uniqueId = (string)($entry['unique_id'] ?? '');
        $stateTopic = (string)($entry['state_topic'] ?? '');
        if ($uniqueId === '' || $stateTopic === '') {
            $this->debug('UpdateDiscovery', $this->t('unique_id/state_topic missing'));
            return;
        }

        $entry['suffix'] = $entry['suffix'] ?? $this->extractSuffix($uniqueId);
        $entry['ident'] = $entry['ident'] ?? $this->getIdentForEntry($entry);

        $entities = $this->readEntities();
        $entities[$uniqueId] = $entry;
        $this->writeEntities($entities);

        $this->maintainDeviceVariables();
        $this->maintainEntityVariable($entry);
        $this->updateDeviceInfo($entry['device'] ?? []);
        $this->updateReceiveDataFilter();
    }

    private function updateReceiveDataFilter(): void
    {
        $deviceId = trim($this->ReadPropertyString('DeviceId'));

        $sep = '(?:\\\\/|\\/)';

        if ($deviceId === '') {
            $this->SetReceiveDataFilter('.*"Topic"\s*:\s*".*' . $sep . 'config".*');
            return;
        }

        $escaped = preg_quote($deviceId, '/');
        $filter = '.*"Topic"\s*:\s*".*' . $sep . $escaped . $sep . '[^"]+' . $sep . '(config|state)".*';
        $this->SetReceiveDataFilter($filter);
    }

    private function requestDiscovery(): void
    {
        $parentId = $this->getParentId();
        if ($parentId <= 0) {
            $this->debug('requestDiscovery', 'No parent');
            return;
        }
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($deviceId === '') {
            $this->debug('requestDiscovery', 'DeviceId not set, skipping');
            return;
        }
        $this->debug('requestDiscovery', sprintf('Reading cache from Bridge %d for DeviceId=%s', $parentId, $deviceId));

        try {
            $raw = @XSNB_GetDiscoveryCache($parentId);
        } catch (Throwable $e) {
            $this->debug('requestDiscovery', 'GetDiscoveryCache failed: ' . $e->getMessage());
            return;
        }
        if (!is_string($raw) || $raw === '') {
            $this->debug('requestDiscovery', 'Cache empty');
            return;
        }

        $cache = json_decode($raw, true);
        if (!is_array($cache)) {
            return;
        }

        $count = 0;
        foreach ($cache as $topic => $payload) {
            if (!is_string($topic) || !str_ends_with($topic, '/config')) {
                continue;
            }
            if (!is_string($payload) || $payload === '') {
                continue;
            }
            if ($deviceId !== '') {
                $topicDeviceId = $this->getTopicToken($topic, 3);
                if (strcasecmp($topicDeviceId, $deviceId) !== 0) {
                    continue;
                }
            }
            $decoded = $this->decodePayload($payload);
            $this->processConfig($topic, $decoded);
            $count++;
        }
        $this->debug('requestDiscovery', sprintf('Processed %d config entries', $count));
    }

    private function getParentId(): int
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        return is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
    }

    private function extractDeviceIdFromEntry(array $entry): string
    {
        if (isset($entry['device']['id']) && is_string($entry['device']['id'])) {
            return trim($entry['device']['id']);
        }
        if (isset($entry['device'])) {
            return $this->getDeviceIdentifier($entry['device']);
        }
        return '';
    }

    private function processConfig(string $topic, string $payload): void
    {
        if ($payload === '') {
            return;
        }

        $cfg = json_decode($payload, true);
        if (!is_array($cfg)) {
            return;
        }

        $uniqueId = trim((string)($cfg['unique_id'] ?? $this->getTopicToken($topic, 2)));
        $device = isset($cfg['device']) && is_array($cfg['device']) ? $cfg['device'] : [];
        $deviceId = $this->getTopicToken($topic, 3);
        if ($deviceId === '') {
            $deviceId = $this->getDeviceIdentifier($device);
        }
        if ($uniqueId === '' || $deviceId === '') {
            return;
        }

        $stateTopic = trim((string)($cfg['state_topic'] ?? ''));
        if ($stateTopic === '') {
            return;
        }

        $component = $this->getTopicToken($topic, 4);

        $entry = [
            'unique_id'      => $uniqueId,
            'name'           => (string)($cfg['name'] ?? ''),
            'component'      => $component,
            'state_topic'    => $stateTopic,
            'device_class'   => (string)($cfg['device_class'] ?? ''),
            'payload_on'     => (string)($cfg['payload_on'] ?? ''),
            'payload_off'    => (string)($cfg['payload_off'] ?? ''),
            'unit_of_measurement' => (string)($cfg['unit_of_measurement'] ?? ''),
            'value_template' => (string)($cfg['value_template'] ?? ''),
            'suffix'         => $this->extractSuffix($uniqueId),
            'device'         => [
                'id'           => $deviceId,
                'name'         => (string)($device['name'] ?? $deviceId),
                'manufacturer' => (string)($device['manufacturer'] ?? ''),
                'model'        => (string)($device['model'] ?? ''),
                'sw_version'   => (string)($device['sw_version'] ?? '')
            ]
        ];

        $this->applyDiscoveryEntry($entry);
    }

    private function processStatus(array $entry, $status): void
    {
        $ident = (string)($entry['ident'] ?? $this->getIdentForEntry($entry));
        if ($ident === '') {
            return;
        }

        $varType = $this->resolveVariableType($entry);
        $statusStr = (string)$status;

        if ($varType === 0) {
            $payloadOn = (string)($entry['payload_on'] ?? '');
            $payloadOff = (string)($entry['payload_off'] ?? '');
            if ($payloadOn !== '' && $statusStr === $payloadOn) {
                $this->debug('State', sprintf('%s=%s → true', $ident, $statusStr));
                $this->SetValue($ident, true);
                return;
            }
            if ($payloadOff !== '' && $statusStr === $payloadOff) {
                $this->debug('State', sprintf('%s=%s → false', $ident, $statusStr));
                $this->SetValue($ident, false);
                return;
            }
            $this->debug('State', sprintf($this->t('Unknown status for %s: %s'), $ident, $statusStr));
            return;
        }

        if ($varType === 2) {
            $this->debug('State', sprintf('%s=%s → float', $ident, $statusStr));
            $this->SetValue($ident, (float)$status);
            return;
        }
        if ($varType === 1) {
            $this->debug('State', sprintf('%s=%s → int', $ident, $statusStr));
            $this->SetValue($ident, (int)$status);
            return;
        }
        $this->debug('State', sprintf('%s=%s', $ident, $statusStr));
        $this->SetValue($ident, $statusStr);
    }

    private function extractValue(array $data, array $entry)
    {
        $key = $this->parseTemplateKey((string)($entry['value_template'] ?? ''));
        if ($key !== '' && array_key_exists($key, $data)) {
            return $data[$key];
        }
        if (array_key_exists('status', $data)) {
            return $data['status'];
        }
        return null;
    }

    private function parseTemplateKey(string $template): string
    {
        if (preg_match('/value_json\.([\w]+)/', $template, $m)) {
            return $m[1];
        }
        if (preg_match("/value_json\['([\w]+)'\]/", $template, $m)) {
            return $m[1];
        }
        return '';
    }

    private function maintainAllVariables(): void
    {
        $entities = $this->readEntities();
        if (empty($entities)) {
            return;
        }
        $this->maintainDeviceVariables();
        foreach ($entities as $entry) {
            if (is_array($entry)) {
                $this->maintainEntityVariable($entry);
            }
        }
        // Versions up to 0.3 mapped both lifeend and smokefault to "Problem".
        // Remove the ambiguous legacy variable after the distinct variables exist.
        $this->MaintainVariable('Problem', 'Problem', 0, '', 99, false);
    }

    private function maintainDeviceVariables(): void
    {
        $this->maintainString('Manufacturer', 'Manufacturer', 1);
        $this->maintainString('Model', 'Model', 2);
        $this->maintainString('Firmware', 'Firmware', 3);
        $this->maintainInteger('LastSeen', 'Last Seen', 4, $this->getDateTimePresentation());
        $this->maintainString('OverallStatus', 'Gesamtzustand', 5);
        $this->MaintainVariable('OverallAlarm', 'Sammelalarm', 0, '', 6, true);
    }

    private function updateDeviceInfo(array $device): void
    {
        $this->SetValue('Manufacturer', (string)($device['manufacturer'] ?? ''));
        $this->SetValue('Model', (string)($device['model'] ?? ''));
        $this->SetValue('Firmware', (string)($device['sw_version'] ?? ''));
    }

    private function maintainEntityVariable(array $entry): void
    {
        $ident = (string)($entry['ident'] ?? $this->getIdentForEntry($entry));
        if ($ident === '') {
            return;
        }
        $name = $this->resolveVariableName($entry);
        $position = $this->resolvePosition($entry);
        $varType = $this->resolveVariableType($entry);

        $this->MaintainVariable($ident, $name, $varType, '', $position, true);
        $this->applyBoolPresentation($ident, $entry, $varType);
    }

    private function applyBoolPresentation(string $ident, array $entry, int $varType): void
    {
        if ($varType !== 0) {
            return;
        }
        $payloadOn = (string)($entry['payload_on'] ?? '');
        $payloadOff = (string)($entry['payload_off'] ?? '');
        if ($payloadOn === '' && $payloadOff === '') {
            return;
        }
        $varId = @$this->GetIDForIdent($ident);
        if ($varId === false) {
            return;
        }
        $option = static function (bool $value, string $caption): array {
            return [
                'Value'               => $value,
                'Caption'             => $caption,
                'IconActive'          => false,
                'IconValue'           => '',
                'ColorActive'         => false,
                'ColorValue'          => -1,
                'ColorDisplay'        => -1,
                'ContentColorActive'  => false,
                'ContentColorValue'   => -1,
                'ContentColorDisplay' => -1
            ];
        };
        $desiredOptions = json_encode([
            $option(false, $payloadOff ?: 'Off'),
            $option(true, $payloadOn ?: 'On')
        ]);
        $current = @IPS_GetVariable($varId);
        $currentPresentation = is_array($current) ? ($current['VariableCustomPresentation'] ?? []) : [];
        if (is_array($currentPresentation)
            && ($currentPresentation['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION
            && ($currentPresentation['OPTIONS'] ?? '') === $desiredOptions) {
            return;
        }
        IPS_SetVariableCustomPresentation($varId, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => $desiredOptions
        ]);
    }

    private function resolveVariableName(array $entry): string
    {
        $configName = trim((string)($entry['name'] ?? ''));
        if ($configName !== '') {
            return $configName;
        }
        $suffix = (string)($entry['suffix'] ?? '');
        if ($suffix !== '') {
            return ucfirst($suffix);
        }
        return $this->t('Entity');
    }

    private function resolvePosition(array $entry): int
    {
        return match ((string)($entry['suffix'] ?? '')) {
            'online'     => 10,
            'smokealarm' => 11,
            'smokefault' => 12,
            'battery'    => 13,
            'lifeend'    => 14,
            default      => 20
        };
    }

    private function resolveVariableType(array $entry): int
    {
        $payloadOn = (string)($entry['payload_on'] ?? '');
        $payloadOff = (string)($entry['payload_off'] ?? '');
        if ($payloadOn !== '' || $payloadOff !== '') {
            return 0; // boolean
        }
        $component = (string)($entry['component'] ?? '');
        if ($component === 'binary_sensor') {
            return 0; // boolean
        }
        $unit = (string)($entry['unit_of_measurement'] ?? '');
        $floatUnits = ['°C', '°F', '%', 'ppm', 'ppb', 'V', 'mV', 'A', 'mA', 'W', 'kW', 'kWh', 'Wh', 'Hz', 'dB', 'dBm', 'hPa', 'mbar', 'bar', 'Pa', 'lx', 'lm', 'm', 'cm', 'mm', 'km', 'mph', 'km/h', 'm/s', '°', 'µg/m³', 'mg/m³'];
        if (in_array($unit, $floatUnits, true)) {
            return 2; // float
        }
        if ($unit === '' && $component === 'sensor') {
            return 2; // float (sensor without unit is typically numeric)
        }
        return 3; // string (safe default)
    }

    private function getIdentForEntry(array $entry): string
    {
        $suffix = strtolower((string)($entry['suffix'] ?? ''));
        $knownIdents = [
            'online'     => 'Connectivity',
            'battery'    => 'Battery',
            'lifeend'    => 'LifeEnd',
            'smokealarm' => 'Smoke',
            'smokefault' => 'SmokeFault'
        ];
        if (isset($knownIdents[$suffix])) {
            return $knownIdents[$suffix];
        }

        $deviceClass = (string)($entry['device_class'] ?? '');
        if ($deviceClass !== '') {
            return $this->sanitizeIdent(ucfirst($deviceClass));
        }
        $name = (string)($entry['name'] ?? '');
        if ($name !== '') {
            return $this->sanitizeIdent($name);
        }
        $suffix = (string)($entry['suffix'] ?? '');
        $uniqueId = (string)($entry['unique_id'] ?? $suffix);
        return $this->sanitizeIdent('Entity_' . $uniqueId);
    }

    private function updateOverallStatus(): void
    {
        $online = $this->readBooleanValue('Connectivity', false);
        $smoke = $this->readBooleanValue('Smoke', false);
        $fault = $this->readBooleanValue('SmokeFault', false);
        $battery = $this->readBooleanValue('Battery', false);
        $lifeEnd = $this->readBooleanValue('LifeEnd', false);

        $alarm = $smoke || $fault || $battery || $lifeEnd || !$online;
        $status = match (true) {
            $smoke   => 'Rauch erkannt',
            $fault   => 'Rauchmelder gestört',
            $lifeEnd => 'Lebensdauer erreicht',
            $battery => 'Batterie schwach',
            !$online => 'Offline',
            default  => 'OK'
        };

        if (@$this->GetIDForIdent('OverallAlarm') !== false) {
            $this->SetValue('OverallAlarm', $alarm);
        }
        if (@$this->GetIDForIdent('OverallStatus') !== false) {
            $this->SetValue('OverallStatus', $status);
        }
    }

    private function readBooleanValue(string $ident, bool $default): bool
    {
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId === false || !@IPS_VariableExists($variableId)) {
            return $default;
        }
        return (bool)@GetValue($variableId);
    }

    private function readEntities(): array
    {
        $raw = $this->ReadAttributeString('Entities');
        $entities = json_decode($raw, true);
        return is_array($entities) ? $entities : [];
    }

    private function writeEntities(array $entities): void
    {
        $this->WriteAttributeString('Entities', json_encode($entities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function findEntitiesByTopic(string $topic, array $entities): array
    {
        $matches = [];
        foreach ($entities as $entry) {
            if (($entry['state_topic'] ?? '') === $topic) {
                $matches[] = $entry;
            }
        }
        return $matches;
    }

    private function sanitizeIdent(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '_', $value);
        $clean = trim((string)$clean, '_');
        if ($clean === '') {
            $clean = 'Entity';
        }
        return $clean;
    }

    private function maintainString(string $ident, string $name, int $position, string|array $presentation = '', bool $keep = true): void
    {
        $this->MaintainVariable($ident, $name, 3, $presentation, $position, $keep);
    }

    private function maintainInteger(string $ident, string $name, int $position, string|array $presentation = '', bool $keep = true): void
    {
        $this->MaintainVariable($ident, $name, 1, $presentation, $position, $keep);
    }

    private function getDateTimePresentation(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME];
    }

}
