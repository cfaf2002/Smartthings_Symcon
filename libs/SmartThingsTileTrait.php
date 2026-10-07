<?php

declare(strict_types=1);

/**
 * SmartThings – IP-Symcon-Modul für Geräte in Samsung SmartThings
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */

/**
 * Kachel des SmartThings-Geräts (HTML-SDK). Beim Kühlschrank zeigt sie Kühl- und Gefrierteil mit
 * Temperatur, Solltemperatur und Tür; bei anderen Geräten (Handy, Uhr …) Schalter und Werte.
 * Die Kachel bekommt nur Daten (JSON) und baut alles mit textContent auf – kein HTML aus Variablen.
 */
trait SmartThingsTileTrait
{
    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/../SmartThingsGeraet/tile.html');
        $json = (string) json_encode($this->TileData(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*INITIAL_DATA*/null', $json, $html);
    }

    /**
     * Schickt geänderte Kacheldaten. $Force: auch unverändert senden und vorab angezeigte Werte
     * der Kachel verwerfen (nach einem fehlgeschlagenen Befehl).
     */
    private function PushTile(bool $Force = false): void
    {
        $data = $this->TileData();
        $json = (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json === $this->ReadAttributeString('TileData') && !$Force) {
            return;
        }
        $this->WriteAttributeString('TileData', $json);
        if ($this->ReadPropertyBoolean('UseTile')) {
            $this->UpdateVisualizationValue($Force
                ? (string) json_encode($data + ['resync' => true], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                : $json);
        }
    }

    private function TileData(): array
    {
        $device = $this->DeviceInfo();
        $meta = $this->Meta();
        $value = fn (string $ident): mixed => $this->VariableExists($ident) ? $this->GetValue($ident) : null;

        $compartments = [];
        $toggles = [];
        $values = [];
        foreach ($meta as $ident => $item) {
            $component = (string) $item['component'];
            $label = $this->Translate((string) $item['label']);
            $prefix = (string) $item['prefix'] !== '' ? $this->Translate((string) $item['prefix']) : '';
            switch ($item['kind']) {
                case 'temperature':
                case 'setpoint':
                    $compartments[$component] ??= [
                        'title' => $prefix !== '' ? $prefix : ((string) ($device['label'] ?? '') !== '' ? $this->Translate('Temperature') : ''),
                        'temp' => null, 'set' => null, 'setIdent' => '', 'unit' => '', 'min' => null, 'max' => null, 'door' => null,
                        'freezer' => $component === 'freezer',
                    ];
                    if ($item['kind'] === 'temperature') {
                        $compartments[$component]['temp'] = $value($ident);
                        $compartments[$component]['unit'] = (string) $item['unit'];
                    } else {
                        $compartments[$component]['set'] = $value($ident);
                        $compartments[$component]['setIdent'] = (string) $ident;
                        $compartments[$component]['min'] = $item['min'] ?? null;
                        $compartments[$component]['max'] = $item['max'] ?? null;
                        $compartments[$component]['unit'] = $compartments[$component]['unit'] ?: (string) $item['unit'];
                    }
                    break;
                case 'switch':
                case 'onoff':
                case 'activated':
                    $toggles[] = ['ident' => (string) $ident, 'label' => $prefix !== '' && $item['kind'] === 'switch' ? $prefix : $label, 'on' => (bool) $value($ident)];
                    break;
                case 'alarm':
                    break;
                default:
                    $v = $value($ident);
                    $values[] = [
                        'ident' => (string) $ident,
                        'component' => $component,
                        'kind'  => (string) $item['kind'],
                        'label' => ($prefix !== '' ? $prefix . ' · ' : '') . $label,
                        'text'  => $this->TileText((string) $item['kind'], $v, (string) $item['unit']),
                        'tone'  => $this->TileTone((string) $item['kind'], $v),
                    ];
            }
        }
        // Tür dem Fach zuordnen (Kühlschrank: Tür am Kühl- bzw. Gefrierteil)
        foreach ($values as $i => $row) {
            if ($row['kind'] === 'contact' && isset($compartments[$row['component']])) {
                $compartments[$row['component']]['door'] = $value((string) $row['ident']);
                unset($values[$i]);
            }
        }

        $error = '';
        if (!$this->ValidDeviceID()) {
            $error = $this->Translate('Please choose a device (use the SmartThings configurator)');
        } elseif ($this->GetStatus() === 202) {
            $error = $this->Translate('Device not found in SmartThings');
        } elseif ($this->GetStatus() === 201) {
            $error = $this->Translate('SmartThings not reachable – check the account instance');
        }

        $online = $value('Online');
        return [
            'theme'        => $this->ReadPropertyInteger('TileTheme'),
            'name'         => (string) ($device['label'] ?? $device['name'] ?? 'SmartThings'),
            'model'        => $this->ReadableModel((string) ($device['ocf']['modelNumber'] ?? '')),
            'online'       => $online === null ? null : (bool) $online,
            'error'        => $error,
            'compartments' => array_values($compartments),
            'toggles'      => $toggles,
            'values'       => array_values($values),
            'doorAlarm'    => (bool) ($value('DoorAlarm') ?? false),
            'openFor'      => (int) $this->GetBuffer('OpenFor'),
        ];
    }

    /**
     * Modellnummer nur anzeigen, wenn sie lesbar ist (z. B. RB38C7B6AS9); interne Kennungen wie
     * „25K_REF_LCD_FHUB10.0|7067144…“ bleiben weg.
     */
    private function ReadableModel(string $model): string
    {
        $model = trim($model);
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/-]{2,23}$/', $model) && !str_contains($model, '_') ? $model : '';
    }

    private function TileText(string $kind, mixed $v, string $unit): string
    {
        if ($v === null) {
            return '–';
        }
        return match ($kind) {
            'contact'  => $this->Translate($v ? 'open' : 'closed'),
            'presence' => $this->Translate($v ? 'present' : 'away'),
            'battery', 'percent', 'humidity' => number_format((float) $v, 0, ',', '.') . ' %',
            'power'    => number_format((float) $v, 0, ',', '.') . ' W',
            'energy'   => number_format((float) $v, 2, ',', '.') . ' kWh',
            'text'     => (string) $v,
            default    => number_format((float) $v, 1, ',', '.') . ($unit !== '' ? ' ' . $unit : ''),
        };
    }

    private function TileTone(string $kind, mixed $v): string
    {
        return match (true) {
            $kind === 'contact' && $v === true => 'warn',
            $kind === 'presence' && $v === true => 'ok',
            $kind === 'battery' && is_numeric($v) && $v <= 15 => 'bad',
            $kind === 'percent' && is_numeric($v) && $v >= 90 => 'warn',
            default => '',
        };
    }
}
