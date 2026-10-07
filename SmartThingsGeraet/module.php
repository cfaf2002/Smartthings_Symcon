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

require_once __DIR__ . '/../libs/STH.php';
require_once __DIR__ . '/../libs/SmartThingsTileTrait.php';

/**
 * SmartThings Gerät: liest den Zustand eines Geräts (alle Komponenten, z. B. Kühl- und Gefrierteil)
 * und legt für bekannte Fähigkeiten Variablen mit Darstellungen an. Bedienbare Werte schalten das Gerät.
 */
class SmartThingsGeraet extends IPSModuleStrict
{
    use SmartThingsTileTrait;

    /**
     * Bekannte Fähigkeiten: capability => attribute => [Art, Schlüssel, Beschriftung, Position]
     */
    private const MAP = [
        'switch'                         => ['switch' => ['switch', 'Switch', 'Power', 10]],
        'temperatureMeasurement'         => ['temperature' => ['temperature', 'Temperature', 'Temperature', 20]],
        'thermostatCoolingSetpoint'      => ['coolingSetpoint' => ['setpoint', 'Setpoint', 'Target temperature', 21]],
        'relativeHumidityMeasurement'    => ['humidity' => ['humidity', 'Humidity', 'Humidity', 22]],
        'contactSensor'                  => ['contact' => ['contact', 'Door', 'Door', 30]],
        'samsungce.powerCool'            => ['activated' => ['activated', 'PowerCool', 'Power Cool', 40]],
        'samsungce.powerFreeze'          => ['activated' => ['activated', 'PowerFreeze', 'Power Freeze', 41]],
        'refrigeration'                  => [
            'rapidCooling'  => ['onoff', 'RapidCooling', 'Rapid cooling', 42],
            'rapidFreezing' => ['onoff', 'RapidFreezing', 'Rapid freezing', 43],
        ],
        'presenceSensor'                 => ['presence' => ['presence', 'Presence', 'Presence', 50]],
        'battery'                        => ['battery' => ['battery', 'Battery', 'Battery', 51]],
        'powerMeter'                     => ['power' => ['power', 'Power', 'Power consumption', 60]],
        'energyMeter'                    => ['energy' => ['energy', 'Energy', 'Energy', 61]],
        'powerConsumptionReport'         => ['powerConsumption' => ['report', 'Report', '', 62]],
        'custom.waterFilter'             => [
            'waterFilterUsage'  => ['percent', 'FilterUsage', 'Water filter used', 70],
            'waterFilterStatus' => ['text', 'FilterStatus', 'Water filter', 71],
        ],
    ];

    // Lesbare Namen der Komponenten (Kühlschrank)
    private const COMPONENTS = [
        'main'        => '',
        'cooler'      => 'Fridge',
        'freezer'     => 'Freezer',
        'cvroom'      => 'Flex zone',
        'onedoor'     => 'Door',
        'icemaker'    => 'Ice maker',
        'icemaker-02' => 'Ice maker 2',
    ];

    // Befehle je Art: [Befehl für true, Befehl für false, Argument statt Befehl]
    private const COMMANDS = [
        'switch'    => ['on', 'off'],
        'activated' => ['activate', 'deactivate'],
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('Interval', 60);
        $this->RegisterPropertyInteger('DoorAlarmMinutes', 2);

        $this->RegisterPropertyBoolean('UseTile', true);
        $this->RegisterPropertyInteger('TileTheme', 0);

        $this->RegisterAttributeString('Meta', '{}');
        $this->RegisterAttributeString('Signature', '');
        $this->RegisterAttributeString('Device', '{}');
        $this->RegisterAttributeString('Unmapped', '[]');
        $this->RegisterAttributeString('OpenSince', '{}');
        $this->RegisterAttributeString('TileData', '{}');

        $this->RegisterTimer('Update', 0, 'STH_Update($_IPS[\'TARGET\']);');
    }

    public function GetCompatibleParents(): string
    {
        return (string) json_encode(['type' => 'connect', 'moduleIDs' => [STH::MODUL_KONTO]]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->SetVisualizationType($this->ReadPropertyBoolean('UseTile') ? 1 : 0);

        $this->MaintainVariable('Online', $this->Translate('Online'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'wifi',
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => $this->Translate('offline'), 'IconActive' => true, 'IconValue' => 'wifi-slash', 'ColorActive' => true, 'ColorValue' => 0xE5484D],
                ['Value' => true, 'Caption' => $this->Translate('online'), 'IconActive' => true, 'IconValue' => 'wifi', 'ColorActive' => true, 'ColorValue' => 0x34B36B],
            ]),
        ], 1, true);

        // Variablen beim nächsten Abruf neu anlegen (Übersetzung, Türalarm)
        $this->WriteAttributeString('Signature', '');

        if (!$this->ValidDeviceID()) {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            $this->PushTile();
            return;
        }
        $this->SetTimerInterval('Update', max(15, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->SetStatus(102);
        if (IPS_GetKernelRunlevel() === KR_READY && $this->HasActiveParent()) {
            $this->LoadDevice();
            $this->Update();
        } else {
            $this->PushTile();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED && $this->ValidDeviceID()) {
            $this->LoadDevice();
            $this->Update();
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Refresh':
                $this->Update();
                return;
            case 'Step':
                // Kachel: Solltemperatur um einen Schritt ändern ("Ident:+1")
                [$ident, $delta] = array_pad(explode(':', (string) $Value, 2), 2, '0');
                $meta = $this->Meta()[$ident] ?? null;
                if ($meta !== null && $meta['kind'] === 'setpoint' && $this->VariableExists($ident)) {
                    $this->Command($ident, (float) $this->GetValue($ident) + ((float) $delta > 0 ? 1 : -1));
                }
                return;
        }
        if (!$this->Command($Ident, $Value)) {
            // Warnung statt Abbruch: Bedienung in Kachel und Visualisierung bleibt ruhig, der Grund steht im Meldungsfenster
            trigger_error($this->CommandError($Ident), E_USER_WARNING);
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $device = $this->DeviceInfo();
        $caption = $device === []
            ? $this->Translate('Device not loaded yet.')
            : sprintf('%s · %s', (string) ($device['label'] ?? $device['name'] ?? ''), (string) ($device['ocf']['modelNumber'] ?? $device['deviceTypeName'] ?? ''));
        $this->InjectProperty($form['elements'], 'DeviceLabel', 'caption', $caption);
        $unmapped = json_decode($this->ReadAttributeString('Unmapped'), true) ?: [];
        if ($unmapped !== []) {
            $this->InjectProperty($form['elements'], 'UnmappedLabel', 'caption', $this->Translate('Further capabilities without variable') . ': ' . implode(', ', $unmapped));
            $this->InjectProperty($form['elements'], 'UnmappedLabel', 'visible', true);
        }
        return (string) json_encode($form);
    }

    // ------------------------------------------------------------------
    // Öffentliche Befehle (STH_…)
    // ------------------------------------------------------------------

    /**
     * Liest den Zustand des Geräts und aktualisiert Variablen und Kachel.
     */
    public function Update(): bool
    {
        if (!$this->ValidDeviceID()) {
            return false;
        }
        $id = $this->ReadPropertyString('DeviceID');
        $status = $this->Api('GET', 'devices/' . $id . '/status');
        if (!$status['Success'] || !is_array($status['Data']['components'] ?? null)) {
            $this->SetStatus($status['Code'] === 404 ? 202 : 201);
            $this->PushTile();
            return false;
        }
        if ($this->DeviceInfo() === []) {
            $this->LoadDevice();
        }
        $health = $this->Api('GET', 'devices/' . $id . '/health');
        $online = !$health['Success'] || strtoupper((string) ($health['Data']['state'] ?? 'ONLINE')) !== 'OFFLINE';
        $this->SetValueIfChanged('Online', $online);

        $this->Apply($status['Data']['components']);
        if ($this->GetStatus() !== 102) {
            $this->SetStatus(102);
        }
        $this->SetTimerInterval('Update', max(15, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->PushTile();
        return true;
    }

    /**
     * Schickt einen beliebigen Befehl an das Gerät, z. B. ('cooler', 'thermostatCoolingSetpoint', 'setCoolingSetpoint', '[3]').
     */
    public function SendCommand(string $Component, string $Capability, string $Command, string $Arguments): bool
    {
        $arguments = $Arguments === '' ? [] : json_decode($Arguments, true);
        if (!is_array($arguments) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $Component . $Capability . $Command)) {
            return false;
        }
        return $this->Execute($Component, $Capability, $Command, array_values($arguments));
    }

    /**
     * Rohdaten des Geräts (alle Komponenten und Fähigkeiten) als JSON – zum Erkunden neuer Geräte.
     */
    public function GetRawStatus(): string
    {
        if (!$this->ValidDeviceID()) {
            return '{}';
        }
        $status = $this->Api('GET', 'devices/' . $this->ReadPropertyString('DeviceID') . '/status');
        return (string) json_encode($status['Success'] ? $status['Data'] : ['error' => $status['Error']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Zustand auswerten
    // ------------------------------------------------------------------

    private function Apply(array $components): void
    {
        $found = [];
        $unmapped = [];
        $order = array_keys(self::COMPONENTS);

        foreach ($components as $component => $capabilities) {
            if (!is_array($capabilities) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $component)) {
                continue;
            }
            $component = (string) $component;
            $disabled = (array) ($capabilities['custom.disabledCapabilities']['disabledCapabilities']['value'] ?? []);
            $index = array_search($component, $order, true);
            $base = ($index === false ? count($order) + count($found) : $index) * 100;

            foreach ($capabilities as $capability => $attributes) {
                $capability = (string) $capability;
                if (in_array($capability, $disabled, true) || !is_array($attributes)) {
                    continue;
                }
                if (!isset(self::MAP[$capability])) {
                    if (!str_starts_with($capability, 'custom.disabled') && $capability !== 'healthCheck' && $capability !== 'ocf' && $capability !== 'execute') {
                        $unmapped[$capability] = true;
                    }
                    continue;
                }
                // Schnellkühlen doppelt (refrigeration und samsungce.powerCool): nur einmal anzeigen
                if ($capability === 'refrigeration' && (isset($capabilities['samsungce.powerCool']) || isset($capabilities['samsungce.powerFreeze']))) {
                    continue;
                }
                foreach (self::MAP[$capability] as $attribute => [$kind, $key, $label, $position]) {
                    $entry = $attributes[$attribute] ?? null;
                    if (!is_array($entry) || !array_key_exists('value', $entry) || $entry['value'] === null) {
                        continue;
                    }
                    if ($kind === 'report') {
                        // powerConsumptionReport: {power (W), energy (Wh)}
                        $report = is_array($entry['value']) ? $entry['value'] : [];
                        if (is_numeric($report['power'] ?? null)) {
                            $found[STH::Ident($component, 'Power')] = $this->Entry($component, 'powerConsumptionReport', 'powerConsumption', 'power', 'Power consumption', $base + 60, (float) $report['power'], 'W');
                        }
                        if (is_numeric($report['energy'] ?? null)) {
                            $found[STH::Ident($component, 'Energy')] = $this->Entry($component, 'powerConsumptionReport', 'powerConsumption', 'energy', 'Energy', $base + 61, round((float) $report['energy'] / 1000, 3), 'kWh');
                        }
                        continue;
                    }
                    $item = $this->Entry($component, $capability, $attribute, $kind, $label, $base + $position, $entry['value'], (string) ($entry['unit'] ?? ''));
                    if ($item === null) {
                        continue;
                    }
                    if ($kind === 'setpoint') {
                        [$item['min'], $item['max']] = $this->SetpointRange($component, $capabilities, $item['unit']);
                    }
                    if ($kind === 'contact') {
                        $item['since'] = $this->Timestamp((string) ($entry['timestamp'] ?? ''));
                    }
                    $found[STH::Ident($component, $key)] = $item;
                }
            }
        }

        $this->DoorAlarm($found);
        $this->SyncVariables($found);
        $unmappedList = array_keys($unmapped);
        sort($unmappedList);
        $this->WriteAttributeString('Unmapped', (string) json_encode($unmappedList));
    }

    /**
     * Eintrag für eine Variable: Wert umgerechnet, Typ und Darstellung.
     */
    private function Entry(string $component, string $capability, string $attribute, string $kind, string $label, int $position, mixed $raw, string $unit): ?array
    {
        $value = match ($kind) {
            'switch', 'onoff' => is_string($raw) ? strtolower($raw) === 'on' : null,
            'activated'       => is_bool($raw) ? $raw : (is_string($raw) ? in_array(strtolower($raw), ['true', 'on', 'activated'], true) : null),
            'contact'         => is_string($raw) ? strtolower($raw) === 'open' : null,
            'presence'        => is_string($raw) ? strtolower($raw) === 'present' : null,
            'battery', 'percent' => is_numeric($raw) ? (int) round((float) $raw) : null,
            'text'            => is_scalar($raw) ? mb_substr((string) $raw, 0, 120) : null,
            'energy'          => is_numeric($raw) ? (strtolower($unit) === 'wh' ? round((float) $raw / 1000, 3) : (float) $raw) : null,
            default           => is_numeric($raw) ? (float) $raw : null,
        };
        if ($value === null) {
            return null;
        }
        if ($kind === 'energy') {
            $unit = 'kWh';
        }
        $prefix = self::COMPONENTS[$component] ?? $component;
        return [
            'component'  => $component,
            'capability' => $capability,
            'attribute'  => $attribute,
            'kind'       => $kind,
            'label'      => $label,
            'prefix'     => $prefix,
            'position'   => $position,
            'value'      => $value,
            'unit'       => in_array($unit, ['C', 'F'], true) ? '°' . $unit : $unit,
        ];
    }

    private function SetpointRange(string $component, array $capabilities, string $unit): array
    {
        $control = $capabilities['custom.thermostatSetpointControl'] ?? [];
        $min = $control['minimumSetpoint']['value'] ?? null;
        $max = $control['maximumSetpoint']['value'] ?? null;
        $range = $capabilities['thermostatCoolingSetpoint']['coolingSetpointRange']['value'] ?? null;
        if (!is_numeric($min) && is_array($range)) {
            $min = $range['minimum'] ?? null;
            $max = $range['maximum'] ?? null;
        }
        if (is_numeric($min) && is_numeric($max) && (float) $min < (float) $max) {
            return [(float) $min, (float) $max];
        }
        $fahrenheit = $unit === '°F';
        return match ($component) {
            'freezer' => $fahrenheit ? [-8.0, 5.0] : [-23.0, -15.0],
            'cooler'  => $fahrenheit ? [34.0, 44.0] : [1.0, 7.0],
            default   => $fahrenheit ? [-8.0, 50.0] : [-23.0, 10.0],
        };
    }

    /**
     * Türalarm: eine Tür länger als die eingestellte Zeit offen.
     */
    private function DoorAlarm(array &$found): void
    {
        $minutes = $this->ReadPropertyInteger('DoorAlarmMinutes');
        $doors = array_filter($found, static fn (array $i): bool => $i['kind'] === 'contact');
        if ($minutes <= 0 || $doors === []) {
            return;
        }
        $since = json_decode($this->ReadAttributeString('OpenSince'), true) ?: [];
        $alarm = false;
        $longest = 0;
        foreach ($doors as $ident => $door) {
            if ($door['value']) {
                // Zeitpunkt aus SmartThings, sonst seit dem ersten Abruf mit offener Tür
                $since[$ident] = $door['since'] > 0 ? $door['since'] : ($since[$ident] ?? time());
                $longest = max($longest, time() - $since[$ident]);
                $alarm = $alarm || time() - $since[$ident] >= $minutes * 60;
            } else {
                unset($since[$ident]);
            }
        }
        $this->WriteAttributeString('OpenSince', (string) json_encode($since));
        $found['DoorAlarm'] = [
            'component' => 'main', 'capability' => '', 'attribute' => '', 'kind' => 'alarm', 'label' => 'Door open too long',
            'prefix' => '', 'position' => 35, 'value' => $alarm, 'unit' => '', 'openFor' => $longest,
        ];
        // Tür offen: schneller nachsehen, damit der Alarm pünktlich kommt
        if ($longest > 0) {
            $this->SetTimerInterval('Update', max(15, min($this->ReadPropertyInteger('Interval'), 30)) * 1000);
        }
    }

    /**
     * Legt Variablen nur bei geänderter Struktur an bzw. entfernt sie; Werte nur bei Änderung.
     */
    private function SyncVariables(array $found): void
    {
        $meta = [];
        foreach ($found as $ident => $item) {
            $meta[$ident] = array_diff_key($item, ['value' => 0, 'since' => 0, 'openFor' => 0]);
        }
        $signature = md5((string) json_encode($meta));
        if ($signature !== $this->ReadAttributeString('Signature')) {
            $old = $this->Meta();
            foreach ($meta as $ident => $item) {
                $name = ($item['prefix'] !== '' ? $this->Translate($item['prefix']) . ': ' : '') . $this->Translate($item['label']);
                $this->MaintainVariable($ident, $name, $this->VariableType($item['kind']), $this->Presentation($item), $item['position'], true);
                if ($this->Writable($item['kind'])) {
                    $this->EnableAction($ident);
                }
            }
            foreach (array_diff_key($old, $meta) as $ident => $item) {
                $this->MaintainVariable($ident, '', VARIABLETYPE_STRING, '', 0, false);
            }
            $this->WriteAttributeString('Meta', (string) json_encode($meta));
            $this->WriteAttributeString('Signature', $signature);
        }
        foreach ($found as $ident => $item) {
            $this->SetValueIfChanged($ident, $item['value']);
        }
        $this->SetBuffer('OpenFor', (string) ($found['DoorAlarm']['openFor'] ?? 0));
    }

    private function VariableType(string $kind): int
    {
        return match ($kind) {
            'switch', 'onoff', 'activated', 'contact', 'presence', 'alarm' => VARIABLETYPE_BOOLEAN,
            'battery', 'percent' => VARIABLETYPE_INTEGER,
            'text'               => VARIABLETYPE_STRING,
            default              => VARIABLETYPE_FLOAT,
        };
    }

    private function Writable(string $kind): bool
    {
        return in_array($kind, ['switch', 'onoff', 'activated', 'setpoint'], true);
    }

    private function Presentation(array $item): array
    {
        $value = VARIABLE_PRESENTATION_VALUE_PRESENTATION;
        $flag = function (string $off, string $on, string $iconOff, string $iconOn, int $colorOff, int $colorOn): array {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'OPTIONS'      => json_encode([
                    ['Value' => false, 'Caption' => $this->Translate($off), 'IconActive' => true, 'IconValue' => $iconOff, 'ColorActive' => $colorOff >= 0, 'ColorValue' => $colorOff],
                    ['Value' => true, 'Caption' => $this->Translate($on), 'IconActive' => true, 'IconValue' => $iconOn, 'ColorActive' => $colorOn >= 0, 'ColorValue' => $colorOn],
                ]),
            ];
        };
        $snowflake = in_array($item['component'], ['freezer'], true) || $item['attribute'] === 'rapidFreezing';
        return match ($item['kind']) {
            'switch', 'onoff', 'activated' => [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
                'ICON_TRUE'    => $snowflake ? 'snowflake' : ($item['kind'] === 'switch' ? 'power-off' : 'temperature-arrow-down'),
                'ICON_FALSE'   => $snowflake ? 'snowflake' : ($item['kind'] === 'switch' ? 'power-off' : 'temperature-arrow-down'),
                'USAGE_TYPE'   => 0,
            ],
            'contact'  => $flag('closed', 'open', 'door-closed', 'door-open', -1, 0xE2A63B),
            'alarm'    => $flag('OK', 'Alarm', 'circle-check', 'triangle-exclamation', 0x34B36B, 0xE5484D),
            'presence' => $flag('away', 'present', 'house-person-leave', 'house-person-return', -1, 0x34B36B),
            'temperature' => ['PRESENTATION' => $value, 'ICON' => $snowflake ? 'snowflake' : 'temperature-half', 'SUFFIX' => ' ' . $item['unit'], 'DIGITS' => 1],
            'setpoint' => [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'ICON'         => 'temperature-arrow-down',
                'MIN'          => $item['min'] ?? -25,
                'MAX'          => $item['max'] ?? 10,
                'STEP_SIZE'    => 1,
                'DIGITS'       => 0,
                'SUFFIX'       => ' ' . $item['unit'],
                'USAGE_TYPE'   => 0,
            ],
            'humidity' => ['PRESENTATION' => $value, 'ICON' => 'droplet', 'SUFFIX' => ' %', 'DIGITS' => 0],
            'battery'  => ['PRESENTATION' => $value, 'ICON' => 'battery-half', 'SUFFIX' => ' %', 'DIGITS' => 0],
            'percent'  => ['PRESENTATION' => $value, 'ICON' => 'filter', 'SUFFIX' => ' %', 'DIGITS' => 0],
            'power'    => ['PRESENTATION' => $value, 'ICON' => 'bolt', 'SUFFIX' => ' W', 'DIGITS' => 0],
            'energy'   => ['PRESENTATION' => $value, 'ICON' => 'plug', 'SUFFIX' => ' kWh', 'DIGITS' => 2],
            'text'     => ['PRESENTATION' => $value, 'ICON' => 'filter'],
            default    => ['PRESENTATION' => $value, 'DIGITS' => 1, 'SUFFIX' => $item['unit'] !== '' ? ' ' . $item['unit'] : ''],
        };
    }

    // ------------------------------------------------------------------
    // Befehle
    // ------------------------------------------------------------------

    private function Command(string $ident, mixed $value): bool
    {
        $meta = $this->Meta()[$ident] ?? null;
        if ($meta === null || !$this->Writable($meta['kind'])) {
            return false;
        }
        switch ($meta['kind']) {
            case 'switch':
            case 'activated':
                [$on, $off] = self::COMMANDS[$meta['kind']];
                $ok = $this->Execute($meta['component'], $meta['capability'], (bool) $value ? $on : $off, []);
                $value = (bool) $value;
                break;
            case 'onoff':
                $command = 'set' . ucfirst($meta['attribute']);
                $ok = $this->Execute($meta['component'], $meta['capability'], $command, [(bool) $value ? 'on' : 'off']);
                $value = (bool) $value;
                break;
            case 'setpoint':
                $value = max((float) ($meta['min'] ?? -50), min((float) ($meta['max'] ?? 50), round((float) $value)));
                $ok = $this->Execute($meta['component'], $meta['capability'], 'setCoolingSetpoint', [$value]);
                break;
            default:
                return false;
        }
        if ($ok) {
            $this->SetValueIfChanged($ident, $value);
            $this->PushTile();
            // Bestätigten Zustand kurz danach nachlesen
            $this->SetTimerInterval('Update', 5000);
        }
        return $ok;
    }

    private function Execute(string $component, string $capability, string $command, array $arguments): bool
    {
        $result = $this->Api('POST', 'devices/' . $this->ReadPropertyString('DeviceID') . '/commands', [
            'commands' => [[
                'component'  => $component,
                'capability' => $capability,
                'command'    => $command,
                'arguments'  => $arguments,
            ]],
        ]);
        $ok = $result['Success'];
        $error = $result['Error'];
        // Antwort 200, aber vom Gerät abgelehnt
        $status = strtoupper((string) ($result['Data']['results'][0]['status'] ?? 'ACCEPTED'));
        if ($ok && in_array($status, ['FAILED', 'REJECTED'], true)) {
            $ok = false;
            $error = $status;
        }
        $this->SetBuffer('CommandError', $ok ? '' : $error);
        $this->SendDebug('Command', $component . '/' . $capability . '.' . $command . (string) json_encode($arguments) . ' → ' . ($ok ? 'OK' : $error), 0);
        return $ok;
    }

    /**
     * Verständliche Meldung, warum ein Befehl nicht ankam.
     */
    private function CommandError(string $ident): string
    {
        $meta = $this->Meta()[$ident] ?? null;
        $label = $meta === null ? $ident : (($meta['prefix'] !== '' ? $this->Translate($meta['prefix']) . ': ' : '') . $this->Translate($meta['label']));
        if ($meta === null || !$this->Writable((string) $meta['kind'])) {
            return sprintf($this->Translate('%s cannot be switched.'), $label);
        }
        $reason = $this->GetBuffer('CommandError');
        $offline = $this->VariableExists('Online') && $this->GetValue('Online') === false;
        if ($offline || stripos($reason, 'offline') !== false) {
            return sprintf($this->Translate('%s: SmartThings cannot reach the device (offline). A TV in standby can usually only be switched on locally, e.g. with the Samsung TV module and Wake-on-LAN.'), $label);
        }
        return sprintf($this->Translate('%s: command was not accepted by SmartThings (%s).'), $label, $reason !== '' ? $reason : '?');
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function Api(string $method, string $endpoint, array $body = []): array
    {
        if (!$this->HasActiveParent()) {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'account not connected'];
        }
        $result = STH::Response(@$this->SendDataToParent(STH::Request($method, $endpoint, $body)));
        if (!$result['Success']) {
            $this->SendDebug('Error', $method . ' ' . $endpoint . ': ' . $result['Error'], 0);
        }
        return $result;
    }

    private function LoadDevice(): void
    {
        $result = $this->Api('GET', 'devices/' . $this->ReadPropertyString('DeviceID'));
        if ($result['Success'] && is_array($result['Data'])) {
            $device = array_intersect_key($result['Data'], array_flip(['deviceId', 'name', 'label', 'manufacturerName', 'deviceTypeName', 'ocf', 'components']));
            if (isset($device['ocf']) && is_array($device['ocf'])) {
                $device['ocf'] = array_intersect_key($device['ocf'], array_flip(['ocfDeviceType', 'modelNumber', 'manufacturerName']));
            }
            $device['categories'] = [];
            foreach ($result['Data']['components'] ?? [] as $component) {
                foreach ($component['categories'] ?? [] as $category) {
                    $device['categories'][] = (string) ($category['name'] ?? '');
                }
            }
            unset($device['components']);
            $this->WriteAttributeString('Device', (string) json_encode($device));
        }
    }

    private function DeviceInfo(): array
    {
        return json_decode($this->ReadAttributeString('Device'), true) ?: [];
    }

    private function Meta(): array
    {
        return json_decode($this->ReadAttributeString('Meta'), true) ?: [];
    }

    private function ValidDeviceID(): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9-]{8,64}$/', trim($this->ReadPropertyString('DeviceID')));
    }

    private function Timestamp(string $iso): int
    {
        if ($iso === '') {
            return 0;
        }
        $time = strtotime($iso);
        return $time === false ? 0 : $time;
    }

    private function VariableExists(string $ident): bool
    {
        return @$this->GetIDForIdent($ident) !== false;
    }

    private function SetValueIfChanged(string $ident, mixed $value): void
    {
        if ($this->VariableExists($ident) && $this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function InjectProperty(array &$elements, string $name, string $key, mixed $value): void
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element[$key] = $value;
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->InjectProperty($element['items'], $name, $key, $value);
            }
        }
    }
}
