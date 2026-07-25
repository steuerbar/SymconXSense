# XSenseMQTTkonfigurator
Dieses Modul stellt die gefundenen X-Sense Geräte in einer Liste dar, über die Device-Instanzen erzeugt werden können.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Versionshistorie](#8-versionshistorie)
9. [MQTT-Discovery Beispiele](#9-mqtt-discovery-beispiele)

### 1. Funktionsumfang

- Liest den Discovery-Cache der Bridge aus
- Zeigt gefundene Geräte in einer Konfigurator-Liste an
- Ermöglicht das manuelle Erstellen von Device-Instanzen

### 2. Voraussetzungen

- IP-Symcon ab Version 8.1
- X Sense MQTT Bridge Instanz als Gateway

### 3. Software-Installation

- Über den Module Store das Modul`X-Sense MQTT` installieren.
- Alternativ über das Module Control die Repository-URL hinzufügen. 
https://github.com/da8ter/x-sense-mqtt.git

### 4. Einrichten der Instanzen in IP-Symcon

Unter „Instanz hinzufügen“ kann das Modul per Schnellfilter gefunden werden.

1. `X Sense MQTT Konfigurator`-Instanz anlegen
2. Konfigurationsseite öffnen

Wenn Discovery-Configs empfangen werden, erscheinen die Geräte in der Liste. Über die Liste können `X Sense MQTT Device`-Instanzen manuell erstellt werden. Dabei verbindet sich das Device automatisch mit der vorhandenen Bridge und liest deren Discovery-Cache, um Variablen anzulegen.

__Konfigurationsseite__:

Name | Beschreibung
-----|-------------
Debug | Erweiterte Debug-Ausgaben im Meldungslog aktivieren

### 5. Statusvariablen und Profile

Dieses Modul legt keine eigenen Statusvariablen an.

### 6. Visualisierung

Das Modul stellt keine eigene Visualisierung bereit.

### 7. PHP-Befehlsreferenz

`void XSNK_SyncDiscoveryToDevices(int $InstanzID);`

Überträgt den aktuellen Discovery-Cache an bestehende Device-Instanzen.

`void XSNK_RefreshCache(int $InstanzID);`

Fordert die Bridge auf, alle gecachten Discovery-Configs erneut zu senden und lädt das Formular neu. Wird auch vom Refresh-Button im Konfigurator-Formular aufgerufen.

### 8. Versionshistorie

- 0.2: Aufräumen und Best-Practices-Anpassungen
  - `ReceiveData` und ungenutzte `BRIDGE_RX_GUID`-Konstante entfernt
  - Formular aus `form.json` geladen (statt hartcodiert)
  - Refresh-Button im Formular ergänzt (`XSNK_RefreshCache`)
  - Status-Codes mit Konstanten
- 0.1: Eigener Cache entfernt, liest jetzt Bridge-Cache / Initiale Version

### 9. MQTT-Discovery Beispiele

**Config Topic**:
`homeassistant/binary_sensor/{deviceId}/{uniqueId}/config`

Beispiel-Payload:
```json
{
  "unique_id": "xsense_123_smokealarm",
  "name": "Smoke Alarm",
  "state_topic": "homeassistant/binary_sensor/xsense_123/xsense_123_smokealarm/state",
  "payload_on": "ON",
  "payload_off": "OFF",
  "value_template": "{{ value_json.status }}",
  "device": {
    "identifiers": ["xsense_123"],
    "name": "XSense Wohnzimmer",
    "manufacturer": "X-Sense",
    "model": "XS01",
    "sw_version": "1.0.0"
  }
}
```