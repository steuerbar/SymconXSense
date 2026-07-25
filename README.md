# X-Sense MQTT für Symcon

Dieses Modul integriert X-Sense-Rauchmelder über Home-Assistant-MQTT-Discovery in Symcon.

Die Bibliothek basiert auf dem Projekt
[da8ter/x-sense-mqtt](https://github.com/da8ter/x-sense-mqtt) von Stephan Sprick.
Diese Variante ergänzt eine robuste Wiederverbindung, getrennte Alarmzustände und eine zentrale
Rauchmelderübersicht.

## Voraussetzungen

- Symcon ab Version 8.1
- eingerichteter MQTT-Server in Symcon
- X-Sense-Bridge mit aktivierter Home-Assistant-MQTT-Anbindung

## Installation

Im Symcon Module Control dieses Repository hinzufügen:

```text
https://github.com/steuerbar/SymconXSense.git
```

Diese Repository-Variante verwendet bewusst eigene Bibliotheks- und Modul-IDs. Dadurch kollidiert
sie nicht mit der im Symcon Module Store bekannten Originalbibliothek. Bestehende Instanzen des
Originalmoduls müssen vor dem Wechsel entfernt und anschließend mit dieser Variante neu angelegt
werden.

## Einrichtung

1. Einen MQTT-Server in Symcon anlegen.
2. `X-Sense MQTT Bridge` erstellen und mit dem MQTT-Server verbinden.
3. `X-Sense MQTT Konfigurator` erstellen und mit der X-Sense-Bridge verbinden.
4. In der X-Sense-App die Home-Assistant-Anbindung der Bridge aktivieren.
5. Im Konfigurator die gefundenen Rauchmelder anlegen.

Falls keine Geräte erscheinen, die Home-Assistant-Anbindung in der X-Sense-App kurz deaktivieren
und erneut aktivieren. Dadurch werden die Discovery-Nachrichten neu veröffentlicht.

## Bereitgestellte Daten

Jeder Rauchmelder besitzt getrennte Variablen für:

- Onlinezustand
- Rauchalarm
- Gerätestörung
- schwache Batterie
- erreichte Lebensdauer
- letzten Empfang
- Gesamtzustand
- Sammelalarm
- Hersteller, Modell und Firmware

Der Konfigurator stellt zusätzlich eine zentrale Übersicht bereit:

- Systemzustand und Gesamtalarm
- Anzahl aller und offline befindlicher Rauchmelder
- Anzahl Rauchalarme
- Anzahl Störungen
- Anzahl schwacher Batterien
- Anzahl Geräte mit erreichter Lebensdauer
- Zeitpunkt der letzten Auswertung

Die zentrale Auswertung wird alle 30 Sekunden aktualisiert.

## Visualisierungskachel

Das Modul `X-Sense Rauchmelder Kachel` zeigt alle Rauchmelder automatisch mit einem
professionellen Comic-Produktmotiv. Pro Gerät sind Gesamtzustand, letzter Empfang sowie Online-,
Rauch-, Störungs-, Batterie- und Lebensdauerstatus unmittelbar sichtbar. Im Kopfbereich werden
Gesamtzustand, Gerätezahl, Offline-Geräte und Warnungen zusammengefasst.

Für 17 Rauchmelder wird eine Kachelgröße von mindestens 4 × 4 Feldern empfohlen.

## Verbesserungen dieser Variante

- erneute Bridge-Aktivierung nach abgeschlossenem Symcon-Kernelstart
- automatische Wiederholungsprüfung der Geräte bei noch nicht aktiver Bridge
- getrennte Variablen für `lifeend` und `smokefault`
- Migration der früher mehrdeutigen Variable `Problem`
- eindeutige Gesamtbewertung jedes Rauchmelders
- zentrale Zustands- und Alarmübersicht im Konfigurator

## Architektur

```text
X-Sense-Geräte
    ↓
X-Sense-Bridge
    ↓ MQTT
Symcon MQTT Server
    ↓
X-Sense MQTT Bridge
    ↓
X-Sense MQTT Konfigurator
    ↓
X-Sense MQTT Device
```

## Versionshistorie

- **0.4**
  - Startreihenfolge und Wiederverbindung korrigiert
  - Lebensdauer und Gerätestörung getrennt
  - Gesamtzustand und Sammelalarm je Gerät ergänzt
  - zentrale Übersicht im Konfigurator ergänzt
- **0.5**
  - responsive Visualisierungskachel für alle Rauchmelder ergänzt
  - Comic-Produktmotiv und kompakte Statuskarten ergänzt
- **0.6**
  - eigener GUID-Satz für konfliktfreie Installation über Module Control
  - interne Bridge-, Geräte-, Konfigurator- und Kachelverweise angepasst
- **0.7**
  - auch die interne Bridge-/Device-Datenschnittstelle mit eigener GUID versehen
  - Bibliotheksname, Autor und Hersteller eindeutig als steuerbar-Variante gekennzeichnet
  - nur die offiziellen Symcon-MQTT-Schnittstellen unverändert beibehalten
- **0.8**
  - zentrale Anomalieprüfung aller dynamisch vorhandenen Rauchmelder
  - Gesamtalarm berücksichtigt Rauch, Offline-Status, Gerätestörung, Batterie,
    Lebensdauer, inaktive Instanzen sowie fehlende oder noch nie empfangene Statuswerte
  - neuer Fehlertext mit Raumname und konkreter Ursache
  - Aktualisierung der zentralen Prüfung alle 10 Sekunden
- **0.3**
  - Basisstand des Ursprungsmoduls

## Lizenz und Herkunft

Bitte die Lizenz- und Urheberhinweise des Ursprungsprojekts beachten. Änderungen dieser Variante
werden im Repository `steuerbar/SymconXSense` gepflegt.
