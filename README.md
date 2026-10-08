# SmartThings für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.2 (Build 7)](https://img.shields.io/badge/Modul--Version-1.2_(Build_7)-informational.svg)](library.json)
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
5. [Urlaubsbetrieb](#5-urlaubsbetrieb)
6. [Kachel](#6-kachel)
7. [Variablen und Darstellungen](#7-variablen-und-darstellungen)
8. [PHP-Befehle](#8-php-befehle)
9. [Sicherheit und Geschwindigkeit](#9-sicherheit-und-geschwindigkeit)
10. [Entwicklung und Tests](#10-entwicklung-und-tests)
11. [Changelog](#11-changelog)
12. [Lizenz](#12-lizenz)

## 1. Funktionsumfang

- **SmartThings Konto:** Anmeldung über eine eigene OAuth-App – bleibt dauerhaft angemeldet, der Zugang wird automatisch erneuert. Zum schnellen Ausprobieren alternativ ein persönlicher Zugriffstoken (neue Tokens gelten bei SmartThings nur noch 24 Stunden).
- **SmartThings Konfigurator:** alle Geräte des Kontos mit Raum, Art (Kühlschrank, Handy, Uhr …) und Modell; Anlegen mit einem Klick
- **SmartThings Gerät:** liest alle Komponenten eines Geräts und legt für bekannte Werte Variablen an:
  - Kühlschrank: Temperatur und Solltemperatur von Kühl- und Gefrierteil (und Flexzone), Tür, Türalarm nach einstellbarer Zeit, Power Cool, Power Freeze, Eiswürfelbereiter, Leistung, Energie, Wasserfilter
  - Handy: Anwesenheit, Akku
  - Uhr: Akku (mehr gibt SmartThings für Uhren meist nicht heraus)
  - Online/offline jedes Geräts
- Solltemperaturen, Power Cool/Freeze und Schalter direkt aus Symcon bedienen; Werte im Gerätebereich begrenzt
- **Urlaubsbetrieb (Kühlschrank, ab Werk aus):** folgt dem Urlaubsschalter des Hauses – Eiswürfelbereiter und Power Cool/Freeze aus, Kühlteil sparsam; bei Rückkehr wird alles zurückgestellt
- Fähigkeiten ohne eigene Variable werden im Formular genannt; „Fähigkeiten des Geräts anzeigen“ listet alle Komponenten und Fähigkeiten, „Rohdaten anzeigen“ zeigt alles, was das Gerät meldet; `STH_SendCommand` schickt jeden Befehl
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
2. Im Feld **„1. Diese Adresse kopieren …“** steht jetzt die Anmeldeadresse. Kopieren (ins Feld klicken, Strg+A, Strg+C), im Browser einfügen und öffnen. Anmelden, Standort wählen, **Zulassen**.
3. Der Browser landet auf `httpbin.org/get` und zeigt eine Seite mit `"code": "…"`. Die **Adresse aus der Adresszeile** (oder den ganzen Seiteninhalt) kopieren.
4. In **„2. Adresse einfügen …“** einfügen und **„3. Anmeldung abschließen“** wählen. Fertig – das Konto bleibt angemeldet.

Eine andere Redirect-URI geht ebenso (etwa die eigene Symcon-Connect-Adresse); sie muss nur in App und Formular gleich sein.

### Geräte anlegen

Im **SmartThings Konfigurator** Kühlschrank, Handy und Uhr markieren und **Erstellen** wählen. In der Geräte-Instanz lassen sich Abfrageintervall (Standard 60 Sekunden) und Türalarm (Standard 2 Minuten) einstellen.

## 5. Urlaubsbetrieb

> Samsungs eigener Urlaubsmodus (in der SmartThings-App beim Kühlschrank) lässt sich über die öffentliche SmartThings-Schnittstelle nicht schalten – eine Fähigkeit dafür ist nicht dokumentiert. Das Modul bildet ihn deshalb mit den dokumentierten Fähigkeiten nach, die es ohnehin nutzt (Solltemperatur, Schalter, Power Cool/Freeze). Der Urlaubsmodus in der Samsung-App bleibt davon unberührt.

Steht der Urlaubsschalter des Hauses auf Urlaub, stellt Symcon den Kühlschrank in einen sparsamen Urlaubsbetrieb; bei Rückkehr wird alles zurückgestellt. Einstellungen in der Geräte-Instanz unter **Urlaubsbetrieb** (nur bei Kühlschränken, sonst steht dort ein Hinweis):

| Einstellung | Standard | Wirkung |
| :-- | :-- | :-- |
| Urlaubsbetrieb | Aus | An: dem Urlaubsschalter des Hauses folgen. Aus: keine Wirkung (ein noch gesicherter Stand wird beim Ausschalten zurückgestellt) |
| Urlaubsschalter des Hauses | – | Boolean (an = Urlaub) oder Integer (≠ 0 = Urlaub), z. B. derselbe Schalter wie bei der Markisensteuerung |
| Invertiert (aus = Urlaub) | aus | dreht die Bedeutung des Schalters um |
| Kühlteil-Sollwert im Urlaub | 99 | 99 (oder jeder Wert über dem Gerätebereich) = Höchstwert des Kühlteils, z. B. 7 °C; 0 = Sollwert nicht ändern. Der Bereich des Geräts steht darunter |
| Eiswürfelbereiter abschalten | an | schaltet `icemaker` und `icemaker-02` über `switch` aus |
| Power Cool / Power Freeze abschalten | an | Power Cool und Power Freeze bzw. Schnellkühlen/Schnellgefrieren aus |

Das Gefrierteil bleibt unverändert.

- **Sichern und zurückstellen:** Beim Wechsel auf Urlaub merkt sich das Modul für jeden Wert, den es ändert, den Stand vorher und den gesetzten Wert. Werte, die schon passen (z. B. Power Cool war aus), fasst es nicht an. Bei Rückkehr stellt es nur zurück, was noch auf dem gesetzten Wert steht – wer im Urlaub selbst etwas ändert (etwa den Eiswürfelbereiter wieder einschaltet), behält seine Einstellung. Danach wird die Sicherung gelöscht.
- **Auslöser:** jede Änderung des Urlaubsschalters, außerdem jeder Abruf und der Start von Symcon (falls der Schalter umgestellt wurde, während Symcon aus war).
- **Gerät offline:** Befehle, die nicht ankommen, versucht das Modul beim nächsten Abruf erneut – einmal pro Abruf, ohne Schleife. Solange das Gerät offline ist, sendet es nichts.
- **Anzeige:** Variable **Urlaubsbetrieb** (Normalbetrieb/Urlaub, nur bei eingeschaltetem Urlaubsbetrieb), in der Kachel ein kurzer Hinweis („Urlaubsbetrieb aktiv“, gelb „wartet auf das Gerät“), im Formular der gesicherte Stand.

**Eigene Urlaubs-Fähigkeit prüfen:** Unter **Fähigkeiten des Geräts anzeigen** (unten im Formular) stehen alle Komponenten und Fähigkeiten, die das Gerät meldet. Taucht dort eine Fähigkeit mit „vacation“ im Namen auf, ist sie gelb hervorgehoben und steht auch im Debug (`Vacation capability` mit ihren Werten). Geschaltet wird sie nicht, weil ihre Befehle nicht dokumentiert sind – mit den Rohdaten und `STH_SendCommand` lässt sie sich aber ausprobieren.

## 6. Kachel

- **Kühlschrank:** je Fach eine Karte mit großer Ist-Temperatur, Türzustand und Solltemperatur mit − und +. Mehrere Klicks werden gesammelt und einmal gesendet. Darunter Power Cool, Power Freeze und Eiswürfelbereiter als Schalter sowie Leistung, Energie und Wasserfilter.
- **Türalarm:** roter Hinweis mit laufender Zeit, das Fach färbt sich gelb.
- **Urlaubsbetrieb:** blauer Hinweis „Urlaubsbetrieb aktiv“; gelb, solange ein Befehl noch nicht beim Gerät angekommen ist.
- **Handy und Uhr:** Anwesenheit und Akku; Akku unter 15 % rot.
- **Farbschema der Kachel:** Symcon-Design (Farben der Visualisierung), Dunkel oder Hell. Kleine Kacheln zeigen beim Kühlschrank nur die Fächer.

## 7. Variablen und Darstellungen

Idents: Komponente `main` ohne Vorsatz, sonst mit Vorsatz (`cooler_`, `freezer_`, `cvroom_`, `icemaker_` …).

| Ident (Beispiel) | Name | Typ | Darstellung |
| :-- | :-- | :-- | :-- |
| `Online` | Online | Boolean | Wertanzeige online/offline |
| `Vacation` | Urlaubsbetrieb | Boolean | Wertanzeige Normalbetrieb/Urlaub (nur bei eingeschaltetem Urlaubsbetrieb) |
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

## 8. PHP-Befehle

| Befehl | Beschreibung |
| :-- | :-- |
| `STH_Update(int $id): bool` | Gerät sofort abfragen |
| `STH_SendCommand(int $id, string $component, string $capability, string $command, string $argumentsJson): bool` | Beliebiger Befehl, z. B. `STH_SendCommand($id, 'cooler', 'thermostatCoolingSetpoint', 'setCoolingSetpoint', '[3]')` |
| `STH_GetRawStatus(int $id): string` | Alle Werte des Geräts als JSON |
| `STH_GetDevices(int $kontoID): string` | Alle Geräte des Kontos als JSON |
| `STH_GetAuthorizeURL(int $kontoID): string` | Anmeldeadresse |
| `STH_Authorize(int $kontoID, string $response): bool` | Anmeldung mit Code oder Weiterleitungsadresse abschließen |
| `STH_RefreshToken(int $kontoID): bool` | Zugang erneuern, falls er bald abläuft (intern: Ziel des stündlichen Timers, normalerweise nicht selbst aufrufen) |

Bedienbare Variablen lassen sich wie gewohnt mit `RequestAction` schalten, z. B. `RequestAction($powerCoolID, true);`.

## 9. Sicherheit und Geschwindigkeit

- Nur HTTPS mit Zertifikatsprüfung und Zeitlimits (Verbindung 5 s, gesamt 15 s), keine Weiterleitungen.
- Client-Secret und Token stehen in Passwortfeldern bzw. Attributen; ins Debug kommen nur Adresse, Statuscode und Dauer.
- Anmeldung mit zufälligem `state` gegen untergeschobene Anmeldungen; die Erneuerung ist gegen gleichzeitige Aufrufe gesperrt.
- Die vorgeschlagene Redirect-URI `https://httpbin.org/get` ist ein fremder Dienst: Der einmalige Anmeldecode geht dabei an diesen Dienst; ohne Client-Secret nützt er allein nichts und gilt nur kurz. Wer das nicht möchte, nimmt eine eigene Adresse (z. B. Symcon Connect) als Redirect-URI.
- Netz- und Serverfehler (Zeitüberschreitung, HTTP 5xx, 429) lassen das Konto aktiv; die Geräte versuchen es beim nächsten Abruf einfach wieder. Nur eine abgelehnte Anmeldung setzt das Konto auf Fehler.
- Das Konto reicht für Kind-Instanzen nur `GET`/`POST` auf `devices`, `locations` und `rooms` durch.
- Die Kachel setzt alle Texte per `textContent`; Kacheldaten werden mit `JSON_HEX_*` eingebettet.
- Je Gerät zwei Anfragen pro Abruf (Zustand und Online-Status). Bei offener Tür wird höchstens alle 30 Sekunden nachgesehen, damit der Türalarm pünktlich kommt. Werte und Kachel werden nur bei Änderung geschrieben.
- SmartThings begrenzt die Zahl der Anfragen; bei „HTTP 429“ das Intervall erhöhen.
- Urlaubsbetrieb: Befehle nur beim Wechsel des Urlaubsschalters (bzw. einmal pro Abruf für noch nicht angekommene), keine zusätzlichen Abfragen.

## 10. Entwicklung und Tests

```
php tests/structure.php
git clone --depth 1 https://github.com/symcon/SymconStubs.git ../SymconStubs
php tests/stubs.php ../SymconStubs
```

`tests/stubs.php` startet eine nachgebaute SmartThings-Cloud (`tests/fixtures/cloud.php`) und prüft Anmeldung mit State und rotierendem Refresh-Token, Wiederholung nach 401, Folgeseiten, Konfigurator, Kühlschrank mit allen Werten, abgeschaltete Fähigkeiten, Befehle, Türalarm, Handy, Uhr, unbekannte Geräte und die Kachel. Der Workflow führt alles mit PHP 8.3 und 8.5 aus.

## 11. Changelog

| Version | Build | Datum | Beschreibung |
| :-- | --: | :-- | :-- |
| 1.2 | 7 | 08.10.2026 | Urlaubsbetrieb für Kühlschränke (ab Werk aus): folgt dem Urlaubsschalter des Hauses, schaltet Eiswürfelbereiter und Power Cool/Freeze ab und stellt das Kühlteil sparsam (Standard: Höchstwert); sichert den Stand und stellt bei Rückkehr nur zurück, was nicht manuell geändert wurde; Wiederholung beim nächsten Abruf, wenn das Gerät offline ist; Variable „Urlaubsbetrieb“ und Hinweis in der Kachel. Neu im Formular: „Fähigkeiten des Geräts anzeigen“ mit allen Komponenten und Fähigkeiten, Urlaubs-Fähigkeiten hervorgehoben |
| 1.1 | 6 | 07.10.2026 | Kurzer Netz- oder Serverausfall legt die Geräte nicht mehr bis zur nächsten Token-Erneuerung still (Konto bleibt aktiv); schnelle Abfrage bei offener Tür bleibt erhalten; Geräte-ID ohne Leerzeichen in Adressen; Leistung/Energie aus powerMeter/energyMeter haben Vorrang vor dem Verbrauchsbericht (kein Springen der Werte); Kachel zeigt nach abgelehntem Befehl wieder den echten Zustand; Hinweis zu httpbin.org im README |
| 1.0 | 5 | 07.10.2026 | Kachel: mehr Abstand zum Symcon-Titel, Fachnamen lesbar (Tür in schmalen Fächern nur als Symbol), interne Modellkennungen ausgeblendet |
| 1.0 | 4 | 07.10.2026 | Kachel lässt oben Platz für Titel und Symbole der Symcon-App (keine Überlagerung mehr), eigener Name entfällt |
| 1.0 | 3 | 07.10.2026 | Abgelehnte Befehle (z. B. Fernseher im Standby einschalten) als Warnung mit Grund statt „Fatal error“; Fehlerdetails von SmartThings im Text |
| 1.0 | 2 | 07.10.2026 | Anmeldeadresse als Feld zum Kopieren statt Link-Knopf (Windows meldete „Holen Sie sich eine App“); Adresse bleibt bis zur Anmeldung gleich |
| 1.0 | 1 | 07.10.2026 | Erste Version: Konto mit OAuth, Konfigurator, Gerät mit Kühlschrank, Handy und Uhr, Türalarm, Kachel |

## 12. Lizenz

MIT – siehe [LICENSE](LICENSE). Copyright (c) 2026 Armin Frohwerk.
