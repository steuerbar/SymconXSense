# X Sense MQTT Bridge

Dieses Modul ist ein IP-Symcon **Splitter** zwischen dem MQTT Server und den X-Sense Modulen.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Versionshistorie](#8-versionshistorie)

### 1. Funktionsumfang

- Abonniert automatisch MQTT Topics für Home Assistant MQTT Discovery:
  - `{TopicRoot}/+/+/+/config` (z.B. `homeassistant/binary_sensor/+/+/config`, `homeassistant/sensor/+/+/config`)
  - `{TopicRoot}/+/+/+/state`
- Unterstützt alle Component-Typen: `binary_sensor`, `sensor`, `switch`, `number` etc.
- Leitet alle empfangenen Nachrichten an die Child-Instanzen weiter (Konfigurator und Devices)
- Hält Discovery-Configs im Cache, damit neu erstellte Device-Instanzen per Replay initialisiert werden können

### 2. Voraussetzungen

- IP-Symcon ab Version 8.1
- MQTT Server Instanz (als Schnittstelle)

### 3. Software-Installation

- Über den Module Store das Modul`X-Sense MQTT` installieren.
- Alternativ über das Module Control die Repository-URL hinzufügen.
https://github.com/da8ter/x-sense-mqtt/tree/main

### 4. Einrichten der Instanzen in IP-Symcon

1. MQTT-Server-Instanz anlegen
2. `X Sense MQTT Bridge`-Instanz anlegen
3. Bridge mit dem MQTT-Server verbinden
4. `TopicRoot` setzen (Standard: `homeassistant`)

__Konfigurationsseite__:

Name | Beschreibung
-----|-------------
Topic root | MQTT Topic-Root für Discovery und State Topics
Debug | Erweiterte Debug-Ausgaben im Meldungslog aktivieren

### 5. Statusvariablen und Profile

Dieses Modul legt keine Statusvariablen an.

### 6. Visualisierung

Dieses Modul stellt keine eigene Visualisierung bereit.

### 7. PHP-Befehlsreferenz

`void XSNB_ReplayDiscovery(int $InstanzID, string $DeviceId);`

Sendet alle im Bridge-Cache gespeicherten Discovery-Configs erneut an die Child-Instanzen. Wenn `DeviceId` leer ist, werden alle Einträge gesendet.

### 8. Versionshistorie

- 0.2: Aufräumen und Best-Practices-Anpassungen
  - Tote `BRIDGE_TX_GUID` / `ForwardData()` entfernt
  - `module.json` `implemented`/`parentRequirements` korrigiert (BRIDGE_RX_GUID statt TX)
  - `Destroy()` ergänzt
  - Status-Codes 201 (MQTT Server inactive) und 202 (Topic root empty) eingeführt
  - `ReceiveDataFilter` auf TopicRoot eingeschränkt (Performance)
  - Discovery-Cache auf max. 500 Einträge begrenzt (FIFO)
  - Magic Numbers durch Konstanten ersetzt
- 0.1: Discovery-Cache Replay für neue Device-Instanzen / Initiale Version
