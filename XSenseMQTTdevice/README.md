# X Sense MQTT Device
Dieses Modul verarbeitet die State-Topics eines X-Sense Geräts (Home Assistant MQTT Discovery), legt Variablen an und aktualisiert den Status.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Darstellungen](#5-statusvariablen-und-darstellungen)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Versionshistorie](#8-versionshistorie)
9. [MQTT-Topics und Payload-Beispiele](#9-mqtt-topics-und-payload-beispiele)

### 1. Funktionsumfang

- Verarbeitet State-Topics pro Entity eines Geräts
- **Config-driven**: Variablenname, Typ und Ident werden automatisch aus der Discovery-Config abgeleitet (`name`, `device_class`, `payload_on`/`payload_off`)

### 2. Voraussetzungen

- IP-Symcon ab Version 8.1
- X-Sense MQTT Bridge Instanz als Gateway

### 3. Software-Installation

- Über den Module Store das Modul `X-Sense MQTT` installieren.
- Alternativ über das Module Control die Repository-URL hinzufügen.
https://github.com/da8ter/x-sense-mqtt/tree/main

### 4. Einrichten der Instanzen in IP-Symcon

Die Instanz wird üblicherweise über den `X Sense MQTT Konfigurator` angelegt.

__Konfigurationsseite__:

Name | Beschreibung
-----|-------------
Device ID | Gerätekennung aus dem Discovery Topic bzw. `device.identifiers[0]`
Debug | Erweiterte Debug-Ausgaben im Meldungslog aktivieren

### 5. Statusvariablen und Darstellungen

Die Statusvariablen werden automatisch angelegt. Das Löschen einzelner Variablen kann zu Fehlfunktionen führen.
Alle Variablen verwenden die neuen [Darstellungen](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/) (ab IP-Symcon 8.0) statt Legacy-Profile.

#### Geräteinformationen

Name | Typ | Darstellung | Beschreibung
-----|-----|-------------|-------------
Manufacturer | string | Wertanzeige | Hersteller
Model | string | Wertanzeige | Modell
Firmware | string | Wertanzeige | Firmware-Version
LastSeen | integer | Datum/Uhrzeit | Zeitpunkt der letzten gültigen Statusnachricht

Entity-Variablen werden automatisch aus der MQTT Discovery-Config angelegt. Der Variablenname wird aus dem Config-Feld `name` übernommen, der Ident aus `device_class` oder dem sanitisierten Namen. Boolean-Variablen erhalten eine Wertanzeige mit dem `payload_on`/`payload_off`-Text als Beschriftung (z.B. "Cleared", "Online").

### 6. Visualisierung

Das Modul stellt keine eigene Visualisierung bereit.

### 7. PHP-Befehlsreferenz

`void XSND_UpdateDiscovery(int $InstanzID, string $Json);`  
Interne Schnittstelle für den Konfigurator, um Entity-Metadaten zu aktualisieren.

### 8. Versionshistorie

- 0.2: Aufräumen und Best-Practices-Anpassungen
  - Unbenutzte `BRIDGE_TX_GUID` entfernt
  - `Destroy()` ergänzt
  - Status-Codes 201 (Bridge inactive) und 202 (Device ID empty) eingeführt
  - `applyBoolPresentation()` mit Diff-Check (setzt Presentation nur bei Änderung)
  - Toten Code entfernt (`maintainBoolean()`, leere `getSuffixMap()`)
  - JSON-Round-Trip in `processConfig()` beseitigt
  - Variablentyp-Heuristik um gängige numerische Einheiten erweitert (ppm, V, A, W, Hz, hPa, lx, …)
  - Magic Numbers durch Konstanten ersetzt
- 0.1: Initiale Version

### 9. MQTT-Topics und Payload-Beispiele

**State Topic** (aus Discovery):
`homeassistant/binary_sensor/{deviceId}/{uniqueId}/state`

Beispiel-Payload:
```json
{ "status": "Online" }
```

Vergleich erfolgt mit `payload_on`/`payload_off` aus der Discovery-Config. Unterstützte `value_template`-Variante: `{{ value_json.status }}`.