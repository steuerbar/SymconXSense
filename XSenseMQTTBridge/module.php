<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/XSenseMQTTHelper.php';

class XSenseMQTTBridge extends IPSModuleStrict
{
    use XSenseMQTTHelper;
    private const MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const MQTT_DATA_GUID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    private const BRIDGE_RX_GUID = '{B0E6A3C7-5D91-4F28-8A4B-CE726190D53F}'; // Bridge→Device (SendDataToChildren)

    private const STATUS_ACTIVE = 102;
    private const STATUS_NO_PARENT = 104;
    private const STATUS_PARENT_INACTIVE = 201;
    private const STATUS_TOPIC_ROOT_EMPTY = 202;
    private const RETRY_MAX = 10;
    private const RETRY_INTERVAL_MS = 500;
    private const DISCOVERY_CACHE_MAX = 500;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('TopicRoot', 'homeassistant');
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterAttributeString('DiscoveryCache', '{}');
        $this->RegisterAttributeInteger('RetryCount', 0);
        $this->RegisterTimer('RetryActivate', 0, 'XSNB_RetryActivate($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->WriteAttributeInteger('RetryCount', 0);
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

        $root = $this->normalizeTopicRoot($this->ReadPropertyString('TopicRoot'));
        if ($root !== '') {
            $escaped = preg_quote($root, '/');
            $sep = '(?:\\\\/|\\/)';
            $this->SetReceiveDataFilter('.*"Topic"\s*:\s*"' . $escaped . $sep . '.*".*');
        } else {
            $this->SetReceiveDataFilter('.*');
        }

        $connID = 0;
        try {
            $connID = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        } catch (Throwable $e) {
            $connID = 0;
        }

        if ($connID === 0) {
            $this->scheduleRetry();
            $this->SetStatus(self::STATUS_NO_PARENT);
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->scheduleRetry();
            $this->SetStatus(self::STATUS_PARENT_INACTIVE);
            return;
        }

        if ($root === '') {
            $this->SetStatus(self::STATUS_TOPIC_ROOT_EMPTY);
            return;
        }

        $this->SetTimerInterval('RetryActivate', 0);
        $this->WriteAttributeInteger('RetryCount', 0);
        $this->SetStatus(self::STATUS_ACTIVE);
        $this->SetSummary($root);
        $this->subscribeTopic($root . '/+/+/+/config');
        $this->subscribeTopic($root . '/+/+/+/state');
    }

    public function RetryActivate(): void
    {
        $count = $this->ReadAttributeInteger('RetryCount');
        if ($count >= self::RETRY_MAX) {
            $this->SetTimerInterval('RetryActivate', 0);
            return;
        }
        $this->WriteAttributeInteger('RetryCount', $count + 1);
        $this->ApplyChanges();
    }

    private function scheduleRetry(): void
    {
        $count = $this->ReadAttributeInteger('RetryCount');
        if ($count >= self::RETRY_MAX) {
            $this->SetTimerInterval('RetryActivate', 0);
            return;
        }
        $this->SetTimerInterval('RetryActivate', self::RETRY_INTERVAL_MS);
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::MQTT_DATA_GUID) {
            return '';
        }

        $topic = (string)($data['Topic'] ?? '');
        if ($topic === '') {
            return '';
        }

        $payload = $data['Payload'] ?? '';
        $this->debug('ReceiveData', sprintf($this->t('Topic=%s'), $topic));

        $root = $this->normalizeTopicRoot($this->ReadPropertyString('TopicRoot'));
        if (!str_starts_with($topic, $root . '/')) {
            return '';
        }

        if (str_ends_with($topic, '/config')) {
            // Decode payload if it's base64 encoded (from MQTT Server)
            $payloadStr = '';
            if (is_string($payload)) {
                $payloadStr = $payload;
            } elseif (is_array($payload)) {
                // MQTT Server sends payload as byte array
                $payloadStr = implode('', array_map('chr', $payload));
            }
            $this->debug('Config', sprintf('Topic=%s PayloadLen=%d', $topic, strlen($payloadStr)));
            $this->updateDiscoveryCache($topic, $payloadStr);
        }

        $bridgeData = [
            'DataID' => self::BRIDGE_RX_GUID,
            'Topic' => $topic,
            'Payload' => $payload
        ];

        $this->SendDataToChildren(json_encode($bridgeData));

        return '';
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::MQTT_SERVER_GUID]]);
    }

    public function ForwardToChildren(string $topic, string $payload): void
    {
        $bridgeData = [
            'DataID' => self::BRIDGE_RX_GUID,
            'Topic' => $topic,
            'Payload' => $payload
        ];
        $this->SendDataToChildren(json_encode($bridgeData));
    }

    public function GetDiscoveryCache(): string
    {
        $cache = $this->ReadAttributeString('DiscoveryCache');
        $this->debug('GetDiscoveryCache', sprintf('Returning cache length=%d', strlen($cache)));
        return $cache;
    }

    public function ReplayDiscovery(string $deviceId = ''): void
    {
        $cache = $this->readDiscoveryCache();
        $this->debug('ReplayDiscovery', sprintf('Cache has %d entries, filter DeviceId=%s', count($cache), $deviceId));
        
        if (empty($cache)) {
            $this->debug('ReplayDiscovery', 'Cache is empty');
            return;
        }

        $sentCount = 0;
        foreach ($cache as $topic => $payload) {
            if (!is_string($topic) || !is_string($payload)) {
                continue;
            }
            if ($deviceId !== '' && !$this->matchesDeviceId($deviceId, $topic, $payload)) {
                $this->debug('ReplayDiscovery', sprintf('Skipping %s (no match)', $topic));
                continue;
            }
            $this->debug('ReplayDiscovery', sprintf('Forwarding %s', $topic));
            $this->ForwardToChildren($topic, $payload);
            $sentCount++;
        }
        
        $this->debug('ReplayDiscovery', sprintf('Sent %d entries', $sentCount));
    }

    private function normalizeTopicRoot(string $root): string
    {
        return trim($root, '/');
    }

    private function updateDiscoveryCache(string $topic, string $payload): void
    {
        $cache = $this->readDiscoveryCache();
        if ($payload === '') {
            if (isset($cache[$topic])) {
                unset($cache[$topic]);
            }
        } else {
            $cache[$topic] = $payload;
            while (count($cache) > self::DISCOVERY_CACHE_MAX) {
                array_shift($cache);
            }
        }
        $this->WriteAttributeString('DiscoveryCache', json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function readDiscoveryCache(): array
    {
        $raw = $this->ReadAttributeString('DiscoveryCache');
        $cache = json_decode($raw, true);
        return is_array($cache) ? $cache : [];
    }

    private function matchesDeviceId(string $deviceId, string $topic, string $payload): bool
    {
        $topicDevice = $this->getTopicToken($topic, 3);
        if ($topicDevice !== '' && strcasecmp($topicDevice, $deviceId) === 0) {
            return true;
        }

        $cfg = json_decode($payload, true);
        if (!is_array($cfg)) {
            return false;
        }
        $device = isset($cfg['device']) && is_array($cfg['device']) ? $cfg['device'] : [];
        $ident = $this->getDeviceIdentifier($device);
        return $ident !== '' && strcasecmp($ident, $deviceId) === 0;
    }

    private function subscribeTopic(string $topic): void
    {
        $parentId = $this->getParentId();
        if ($parentId <= 0 || $topic === '') {
            return;
        }
        if (function_exists('MQTT_Subscribe')) {
            @MQTT_Subscribe($parentId, $topic, 0);
        }
    }

    private function getParentId(): int
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        return is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
    }

}
