# SmartThings für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.0 (Build 1)](https://img.shields.io/badge/Modul--Version-1.0_(Build_1)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/Smartthings_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Smartthings_Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprachen: Deutsch | Englisch](https://img.shields.io/badge/Sprachen-Deutsch_%7C_Englisch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Datenquelle: SmartThings-API](https://img.shields.io/badge/Datenquelle-SmartThings--API_(Cloud)-lightgrey.svg)](https://developer.smartthings.com/docs/api/public)

IP-Symcon-Modul für Geräte in Samsung SmartThings – gebaut für den Samsung-Kühlschrank (Kühl- und Gefrierteil mit Temperatur, Solltemperatur, Tür, Power Cool/Freeze, Eiswürfelbereiter, Verbrauch, Wasserfilter) sowie Handy und Uhr (Anwesenheit, Akku). Andere SmartThings-Geräte zeigt es mit allen Werten, die es kennt.

> Kein offizielles Produkt von Samsung. Das Modul nutzt die offizielle SmartThings-Schnittstelle über die Cloud; Samsung-Fernseher steuert das Modul [Samsung TV](https://github.com/cfaf2002/SamsungTV_Symcon) schneller und direkt im Heimnetz.

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen und Technik](#2-voraussetzungen-und-technik)
3. [Installation](#3-installation)
4. [Einrichtung](#4-einrichtung)
5. [Kachel](#5-kachel)
6. [Variablen und Darstellungen](#6-variablen-und-darstellungen)
7. [PHP-Befehle](#7-php-befehle)
8. [Sicherheit und Geschwindigkeit](#8-sicherheit-und-geschwindigkeit)
9. [Entwicklung und Tests](#9-entwicklung-und-tests)
10. [Changelog](#10-changelog)
11. [Lizenz](#11-lizenz)

## 1. Funktionsumfang

- **SmartThings Konto:** Anmeldung über eine eigene OAuth-App – bleibt dauerhaft angemeldet, der Zugang wird automatisch erneuert. Zum schnellen Ausprobieren alternativ ein persönlicher Zugriffstoken (neue Tokens gelten bei SmartThings nur noch 24 Stunden).
- **SmartThings Konfigurator:** alle Geräte des Kontos mit Raum, Art (Kühlschrank, Handy, Uhr …) und Modell; Anlegen mit einem Klick
- **SmartThings Gerät:** liest alle Komponenten eines Geräts und legt für bekannte Werte Variablen an:
  - Kühlschrank: Temperatur und Solltemperatur von Kühl- und Gefrierteil (und Flexzone), Tür, Türalarm nach einstellbarer Zeit, Power Cool, Power Freeze, Eiswürfelbereiter, Leistung, Energie, Wasserfilter
  - Handy: Anwesenheit, Akku
  - Uhr: Akku (mehr gibt SmartThings für Uhren meist nicht heraus)
  - Online/offline jedes Geräts
- Solltemperaturen, Power Cool/Freeze und Schalter direkt aus Symcon bedienen; Werte im Gerätebereich begrenzt
- Fähigkeiten ohne eigene Variable werden im Formular genannt; „Rohdaten anzeigen“ zeigt alles, was das Gerät meldet; `STH_SendCommand` schickt jeden Befehl
- Eigene Kachel: Fächer mit großer Temperatur, Tür und Soll-Tasten, Türalarm mit laufender Zeit, Schalter und Werte – passt sich an Kühlschrank, Handy und Uhr an
- Deutsch und Englisch, Darstellungen statt Profilen, Basisklasse IPSModuleStrict, Tests mit nachgebauter SmartThings-Cloud

## 2. Voraussetzungen und Technik

- IP-Symcon ab 8.1, optimiert für 9.0
- Samsung-Konto mit den Geräten in der SmartThings-App
- Für die OAuth-App einmalig die [SmartThings-CLI](https://github.com/SmartThingsCommunity/smartthings-cli) (Windows, macOS, Linux)

| Weg | Wofür |
| :-- | :-- |
| `https://api.smartthings.com/v1/` | Geräte, Räume, Zustand, Online-Status, Befehle |
| `https://auth-global.api.smartthings.com/oauth/token` | Anmeldung und Erneuerung (OAuth 2.0) |

Seit Ende 2024 gelten neu erstellte persönliche Zugriffstoken bei SmartThings nur noch 24 Stunden. Dauerhaft geht es nur mit einer eigenen OAuth-App: Der Zugang gilt 24 Stunden und wird vom Modul stündlich geprüft und rechtzeitig erneuert; dabei gibt SmartThings jedes Mal einen neuen Refresh-Token aus, den das Modul sofort speichert.

## 3. Installation

Im Symcon-Konsolenfenster unter **Kerninstanzen → Module Control** die Adresse hinzufügen:

```
https://github.com/cfaf2002/Smartthings_Symcon
```

Danach eine Instanz **SmartThings Konfigurator** anlegen – das **SmartThings Konto** wird dabei mit angelegt.

## 4. Einrichtung

### OAuth-App anlegen (einmalig)

```
npm install -g @smartthings/cli      # oder Installer von GitHub
smartthings apps:create
```

| Frage | Antwort |
| :-- | :-- |
| What kind of app? | **OAuth-In App** |
| Display Name | z. B. `Symcon` |
| Description | beliebig |
| Icon / Target URL | leer lassen |
| Scopes | `r:devices:*`, `x:devices:*`, `r:locations:*` |
| Redirect URI | `https://httpbin.org/get` |

Die CLI zeigt danach **OAuth Client Id** und **OAuth Client Secret** – beide gut aufheben, das Secret wird nur einmal angezeigt.

### Konto verbinden

1. In der Instanz **SmartThings Konto** Client-ID und Client-Secret eintragen und übernehmen.
2. **„1. Bei SmartThings anmelden“** öffnet die Anmeldeseite von Samsung. Anmelden, Standort wählen, **Zulassen**.
3. Der Browser landet auf `httpbin.org/get` und zeigt eine Seite mit `"code": "…"`. Die **Adresse aus der Adresszeile** (oder den ganzen Seiteninhalt) kopieren.
4. In **„2. Adresse einfügen …“** einfügen und **„3. Anmeldung abschließen“** wählen. Fertig – das Konto bleibt angemeldet.

Eine andere Redirect-URI geht ebenso (etwa die eigene Symcon-Connect-Adresse); sie muss nur in App und Formular gleich sein.

### Geräte anlegen

Im **SmartThings Konfigurator** Kühlschrank, Handy und Uhr markieren und **Erstellen** wählen. In der Geräte-Instanz lassen sich Abfrageintervall (Standard 60 Sekunden) und Türalarm (Standard 2 Minuten) einstellen.

## 5. Kachel

- **Kühlschrank:** je Fach eine Karte mit großer Ist-Temperatur, Türzustand und Solltemperatur mit − und +. Mehrere Klicks werden gesammelt und einmal gesendet. Darunter Power Cool, Power Freeze und Eiswürfelbereiter als Schalter sowie Leistung, Energie und Wasserfilter.
- **Türalarm:** roter Hinweis mit laufender Zeit, das Fach färbt sich gelb.
- **Handy und Uhr:** Anwesenheit und Akku; Akku unter 15 % rot.
- **Farbschema der Kachel:** Symcon-Design (Farben der Visualisierung), Dunkel oder Hell. Kleine Kacheln zeigen beim Kühlschrank nur die Fächer.

## 6. Variablen und Darstellungen

Idents: Komponente `main` ohne Vorsatz, sonst mit Vorsatz (`cooler_`, `freezer_`, `cvroom_`, `icemaker_` …).

| Ident (Beispiel) | Name | Typ | Darstellung |
| :-- | :-- | :-- | :-- |
| `Online` | Online | Boolean | Wertanzeige online/offline |
| `cooler_Temperature` | Kühlteil: Temperatur | Float | Wertanzeige °C |
| `cooler_Setpoint` | Kühlteil: Solltemperatur | Float | Schieberegler im Gerätebereich (z. B. 1–7 °C) |
| `freezer_Temperature` / `freezer_Setpoint` | Gefrierteil: … | Float | wie oben (z. B. −23 bis −15 °C) |
| `Door`, `cooler_Door` | Tür | Boolean | Wertanzeige zu/offen |
| `DoorAlarm` | Tür zu lange offen | Boolean | Wertanzeige OK/Alarm |
| `PowerCool`, `PowerFreeze` | Power Cool / Power Freeze | Boolean | Schalter |
| `icemaker_Switch` | Eiswürfelbereiter: Ein/Aus | Boolean | Schalter |
| `Power` / `Energy` | Leistung / Energie | Float | W / kWh |
| `FilterUsage` / `FilterStatus` | Wasserfilter | Integer / String | % / Text |
| `Presence` | Anwesenheit | Boolean | anwesend/abwesend |
| `Battery` | Akku | Integer | % |

Abgeschaltete Fähigkeiten (`custom.disabledCapabilities`) und leere Werte bekommen keine Variable. Variablen werden nur angelegt oder entfernt, wenn sich die Fähigkeiten des Geräts ändern.

## 7. PHP-Befehle

| Befehl | Beschreibung |
| :-- | :-- |
| `STH_Update(int $id): bool` | Gerät sofort abfragen |
| `STH_SendCommand(int $id, string $component, string $capability, string $command, string $argumentsJson): bool` | Beliebiger Befehl, z. B. `STH_SendCommand($id, 'cooler', 'thermostatCoolingSetpoint', 'setCoolingSetpoint', '[3]')` |
| `STH_GetRawStatus(int $id): string` | Alle Werte des Geräts als JSON |
| `STH_GetDevices(int $kontoID): string` | Alle Geräte des Kontos als JSON |
| `STH_GetAuthorizeURL(int $kontoID): string` | Anmeldeadresse |
| `STH_Authorize(int $kontoID, string $response): bool` | Anmeldung mit Code oder Weiterleitungsadresse abschließen |
| `STH_RefreshToken(int $kontoID): bool` | Zugang erneuern, falls er bald abläuft |

Bedienbare Variablen lassen sich wie gewohnt mit `RequestAction` schalten, z. B. `RequestAction($powerCoolID, true);`.

## 8. Sicherheit und Geschwindigkeit

- Nur HTTPS mit Zertifikatsprüfung und Zeitlimits (Verbindung 5 s, gesamt 15 s), keine Weiterleitungen.
- Client-Secret und Token stehen in Passwortfeldern bzw. Attributen; ins Debug kommen nur Adresse, Statuscode und Dauer.
- Anmeldung mit zufälligem `state` gegen untergeschobene Anmeldungen; die Erneuerung ist gegen gleichzeitige Aufrufe gesperrt.
- Das Konto reicht für Kind-Instanzen nur `GET`/`POST` auf `devices`, `locations` und `rooms` durch.
- Die Kachel setzt alle Texte per `textContent`; Kacheldaten werden mit `JSON_HEX_*` eingebettet.
- Je Gerät zwei Anfragen pro Abruf (Zustand und Online-Status). Bei offener Tür wird höchstens alle 30 Sekunden nachgesehen, damit der Türalarm pünktlich kommt. Werte und Kachel werden nur bei Änderung geschrieben.
- SmartThings begrenzt die Zahl der Anfragen; bei „HTTP 429“ das Intervall erhöhen.

## 9. Entwicklung und Tests

```
php tests/structure.php
git clone --depth 1 https://github.com/symcon/SymconStubs.git ../SymconStubs
php tests/stubs.php ../SymconStubs
```

`tests/stubs.php` startet eine nachgebaute SmartThings-Cloud (`tests/fixtures/cloud.php`) und prüft Anmeldung mit State und rotierendem Refresh-Token, Wiederholung nach 401, Folgeseiten, Konfigurator, Kühlschrank mit allen Werten, abgeschaltete Fähigkeiten, Befehle, Türalarm, Handy, Uhr, unbekannte Geräte und die Kachel. Der Workflow führt alles mit PHP 8.3 und 8.5 aus.

## 10. Changelog

| Version | Build | Datum | Beschreibung |
| :-- | --: | :-- | :-- |
| 1.0 | 1 | 07.10.2026 | Erste Version: Konto mit OAuth, Konfigurator, Gerät mit Kühlschrank, Handy und Uhr, Türalarm, Kachel |

## 11. Lizenz

MIT – siehe [LICENSE](LICENSE). Copyright (c) 2026 Armin Frohwerk.
