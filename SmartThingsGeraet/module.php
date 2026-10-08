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

    // Urlaubsbetrieb: Komponenten eines Kühlschranks, Eiswürfelbereiter und Schnellkühlen/-gefrieren
    private const FRIDGE_COMPONENTS = ['cooler', 'freezer', 'icemaker', 'icemaker-02'];
    private const ICEMAKERS = ['icemaker', 'icemaker-02'];
    private const BOOST = ['samsungce.powerCool', 'samsungce.powerFreeze', 'refrigeration'];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('Interval', 60);
        $this->RegisterPropertyInteger('DoorAlarmMinutes', 2);

        $this->RegisterPropertyBoolean('UseTile', true);
        $this->RegisterPropertyInteger('TileTheme', 0);

        // Urlaubsbetrieb (ab Werk aus)
        $this->RegisterPropertyInteger('VacationMode', 0);
        $this->RegisterPropertyInteger('VacationVariableID', 0);
        $this->RegisterPropertyBoolean('VacationInvert', false);
        $this->RegisterPropertyFloat('VacationFridgeSetpoint', 99.0);
        $this->RegisterPropertyBoolean('VacationIcemakerOff', true);
        $this->RegisterPropertyBoolean('VacationPowerOff', true);

        $this->RegisterAttributeString('Meta', '{}');
        $this->RegisterAttributeString('Signature', '');
        $this->RegisterAttributeString('Device', '{}');
        $this->RegisterAttributeString('Unmapped', '[]');
        $this->RegisterAttributeString('OpenSince', '{}');
        $this->RegisterAttributeString('TileData', '{}');
        $this->RegisterAttributeString('Capabilities', '{}');
        $this->RegisterAttributeString('Vacation', '{}');

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

        // Urlaubsbetrieb: Urlaubsschalter überwachen, Status nur bei eingeschaltetem Urlaubsbetrieb
        $vacationMode = $this->ReadPropertyInteger('VacationMode') === 1;
        foreach ($this->GetMessageList() as $sender => $messages) {
            if ($sender !== 0 && in_array(VM_UPDATE, $messages, true)) {
                $this->UnregisterMessage($sender, VM_UPDATE);
            }
        }
        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        $vacationID = $this->ReadPropertyInteger('VacationVariableID');
        if ($vacationMode && $vacationID > 0 && IPS_VariableExists($vacationID)) {
            $this->RegisterMessage($vacationID, VM_UPDATE);
            $this->RegisterReference($vacationID);
        }
        $this->MaintainVariable('Vacation', $this->Translate('Vacation mode'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'plane',
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => $this->Translate('normal operation'), 'IconActive' => true, 'IconValue' => 'house', 'ColorActive' => false, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => $this->Translate('vacation'), 'IconActive' => true, 'IconValue' => 'plane', 'ColorActive' => true, 'ColorValue' => 0x4B8EF0],
            ]),
        ], 2, $vacationMode);
        if ($vacationMode) {
            $this->SetValueIfChanged('Vacation', (bool) ($this->VacationState()['active']));
        }

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
            // Update gleicht auch den Urlaubsbetrieb ab
            $this->LoadDevice();
            $this->Update();
        }
        if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('VacationVariableID')) {
            $this->VacationSync();
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
            case 'Capabilities':
                // Formular: Fähigkeiten neu abfragen und Liste auffrischen
                $this->Update();
                $this->UpdateFormField('CapabilityList', 'values', (string) json_encode($this->CapabilityRows()));
                return;
            case 'Step':
                // Kachel: Solltemperatur um einen Schritt ändern ("Ident:+1")
                [$ident, $delta] = array_pad(explode(':', (string) $Value, 2), 2, '0');
                $meta = $this->Meta()[$ident] ?? null;
                if ($meta !== null && $meta['kind'] === 'setpoint' && $this->VariableExists($ident)
                    && !$this->Command($ident, (float) $this->GetValue($ident) + ((float) $delta > 0 ? 1 : -1))) {
                    $this->PushTile(true);
                }
                return;
        }
        if (!$this->Command($Ident, $Value)) {
            // Kachel zeigt den Wert schon vorab: echten Stand zurückschicken
            $this->PushTile(true);
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
        $this->VacationForm($form);
        $rows = $this->CapabilityRows();
        $this->InjectProperty($form['actions'], 'CapabilityList', 'values', $rows);
        if ($rows === []) {
            $this->InjectProperty($form['actions'], 'CapabilityEmpty', 'visible', true);
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
        $id = $this->DeviceID();
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

        // Normales Intervall vor dem Auswerten: der Türalarm verkürzt es bei offener Tür wieder
        $this->SetTimerInterval('Update', max(15, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->Apply($status['Data']['components']);
        if ($this->GetStatus() !== 102) {
            $this->SetStatus(102);
        }
        // Urlaubsbetrieb abgleichen; was zuvor nicht ankam (Gerät offline), wird hier erneut versucht
        $this->VacationSync();
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
        $status = $this->Api('GET', 'devices/' . $this->DeviceID() . '/status');
        return (string) json_encode($status['Success'] ? $status['Data'] : ['error' => $status['Error']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Zustand auswerten
    // ------------------------------------------------------------------

    private function Apply(array $components): void
    {
        $found = [];
        $unmapped = [];
        $inventory = [];
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
                if (preg_match('/^[A-Za-z0-9._-]{1,80}$/', $capability)) {
                    $inventory[$component][$capability] = in_array($capability, $disabled, true) ? 'disabled' : (isset(self::MAP[$capability]) ? 'mapped' : '');
                    if (stripos($capability, 'vacation') !== false) {
                        // Eigene Urlaubs-Fähigkeit des Geräts? Nur melden – die Befehle dazu sind nicht dokumentiert
                        $inventory[$component][$capability] = 'vacation';
                        $this->SendDebug('Vacation capability', $component . '/' . $capability . ' ' . json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
                    }
                }
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
                        // Gleiche Idents wie powerMeter/energyMeter: dort gemessene Werte haben Vorrang
                        if (isset($capabilities['powerMeter']) && !in_array('powerMeter', $disabled, true)) {
                            unset($report['power']);
                        }
                        if (isset($capabilities['energyMeter']) && !in_array('energyMeter', $disabled, true)) {
                            unset($report['energy']);
                        }
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
        $inventoryJson = (string) json_encode($inventory);
        if ($inventoryJson !== $this->ReadAttributeString('Capabilities')) {
            $this->WriteAttributeString('Capabilities', $inventoryJson);
        }
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
    // Urlaubsbetrieb
    // ------------------------------------------------------------------

    /**
     * Gleicht den Urlaubsbetrieb mit dem Urlaubsschalter ab. Beim Wechsel auf Urlaub wird gesichert,
     * was das Modul umstellt (Wert vorher und gesetzter Wert), bei Rückkehr nur das zurückgestellt,
     * was noch auf dem gesetzten Wert steht – manuelle Änderungen im Urlaub bleiben. Befehle, die nicht
     * ankommen (Gerät offline), wartet das Modul bis zum nächsten Abruf ab; es gibt keine Schleife.
     */
    private function VacationSync(): void
    {
        $state = $this->VacationState();
        $wanted = $this->ReadPropertyInteger('VacationMode') === 1 && $this->VacationSwitch();
        if (!$wanted && !$state['active'] && $state['items'] === []) {
            $this->SetValueIfChanged('Vacation', false);
            return;
        }
        if (!$this->ValidDeviceID()) {
            return;
        }
        $before = (string) json_encode($state);
        $meta = $this->Meta();
        if ($wanted && !$state['active']) {
            $state = ['active' => true, 'since' => time(), 'planned' => false, 'items' => $state['items']];
            $this->SendDebug('Vacation', 'Urlaub beginnt', 0);
        }
        if ($wanted && !$state['planned'] && $meta !== []) {
            foreach ($this->VacationPlan($meta) as $ident => $target) {
                // Eintrag aus einer unterbrochenen Rückkehr behält seinen gesicherten Wert
                $state['items'][$ident] ??= ['before' => $this->GetValue($ident), 'target' => $target, 'done' => false];
            }
            $state['planned'] = true;
            $this->SendDebug('Vacation', 'Gesichert: ' . json_encode($state['items']), 0);
        }
        if (!$wanted && $state['active']) {
            $state['active'] = false;
            $this->SendDebug('Vacation', 'Rückkehr aus dem Urlaub', 0);
        }

        // Gerät offline: nichts senden, beim nächsten Abruf erneut versuchen
        $online = !$this->VariableExists('Online') || $this->GetValue('Online') !== false;
        foreach ($state['items'] as $ident => $item) {
            $exists = isset($meta[$ident]) && $this->VariableExists($ident);
            if ($wanted) {
                if ($item['done'] || !$exists || !$online) {
                    continue;
                }
                if ($this->Command($ident, $item['target'])) {
                    $state['items'][$ident]['done'] = true;
                } else {
                    $this->SendDebug('Vacation', $ident . ': nicht angekommen, nächster Versuch beim nächsten Abruf', 0);
                }
                continue;
            }
            if (!$item['done'] || !$exists) {
                // nie umgestellt (oder Fähigkeit weg): nichts zurückzustellen
                unset($state['items'][$ident]);
                continue;
            }
            if (!$this->SameValue($this->GetValue($ident), $item['target'])) {
                $this->SendDebug('Vacation', $ident . ': im Urlaub manuell geändert, bleibt so', 0);
                unset($state['items'][$ident]);
                continue;
            }
            if (!$online) {
                continue;
            }
            if ($this->Command($ident, $item['before'])) {
                unset($state['items'][$ident]);
            } else {
                $this->SendDebug('Vacation', $ident . ': Zurückstellen nicht angekommen, nächster Versuch beim nächsten Abruf', 0);
            }
        }

        $json = $state['active'] || $state['items'] !== [] ? (string) json_encode($state) : '{}';
        if ($json !== $this->ReadAttributeString('Vacation')) {
            $this->WriteAttributeString('Vacation', $json);
        }
        if ($json === '{}' && $before !== $json) {
            $this->SendDebug('Vacation', 'Normalbetrieb wiederhergestellt', 0);
        }
        $this->SetValueIfChanged('Vacation', $state['active']);
        $this->PushTile();
    }

    /**
     * Was im Urlaub umgestellt wird: Ident => Zielwert. Nur Werte, die sich wirklich ändern;
     * das Gefrierteil bleibt unverändert.
     */
    private function VacationPlan(array $meta): array
    {
        $plan = [];
        $setpoint = $this->ReadPropertyFloat('VacationFridgeSetpoint');
        foreach ($meta as $ident => $item) {
            if (!$this->VariableExists((string) $ident)) {
                continue;
            }
            $current = $this->GetValue((string) $ident);
            if ($item['kind'] === 'setpoint' && $item['component'] === 'cooler') {
                if ($setpoint == 0.0) {
                    continue;
                }
                // Werte über dem Gerätebereich (Standard 99) = Höchstwert des Kühlteils
                $target = max((float) ($item['min'] ?? -50), min((float) ($item['max'] ?? 50), round($setpoint)));
                if (!$this->SameValue($current, $target)) {
                    $plan[$ident] = $target;
                }
            } elseif ($item['kind'] === 'switch' && in_array($item['component'], self::ICEMAKERS, true)) {
                if ($this->ReadPropertyBoolean('VacationIcemakerOff') && $current === true) {
                    $plan[$ident] = false;
                }
            } elseif (in_array($item['capability'], self::BOOST, true) && in_array($item['kind'], ['activated', 'onoff'], true)) {
                if ($this->ReadPropertyBoolean('VacationPowerOff') && $current === true) {
                    $plan[$ident] = false;
                }
            }
        }
        return $plan;
    }

    /**
     * Urlaubsschalter des Hauses: Boolean an bzw. Integer ≠ 0 = Urlaub, auf Wunsch invertiert.
     */
    private function VacationSwitch(): bool
    {
        $id = $this->ReadPropertyInteger('VacationVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return false;
        }
        $raw = GetValue($id);
        $on = is_bool($raw) ? $raw : (is_numeric($raw) && (int) $raw !== 0);
        return $on !== $this->ReadPropertyBoolean('VacationInvert');
    }

    /**
     * Gespeicherter Urlaubsstand: active, since, planned, items (Ident => before, target, done).
     */
    private function VacationState(): array
    {
        $state = json_decode($this->ReadAttributeString('Vacation'), true);
        $state = is_array($state) ? $state : [];
        return [
            'active'  => (bool) ($state['active'] ?? false),
            'since'   => (int) ($state['since'] ?? 0),
            'planned' => (bool) ($state['planned'] ?? false),
            'items'   => is_array($state['items'] ?? null) ? $state['items'] : [],
        ];
    }

    private function SameValue(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return $a === $b;
        }
        return is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 0.05;
    }

    /**
     * Kurzer Hinweis für die Kachel; null, solange kein Urlaub ist.
     */
    private function VacationTile(): ?array
    {
        $state = $this->VacationState();
        if (!$state['active'] && $state['items'] === []) {
            return null;
        }
        $pending = count(array_filter($state['items'], static fn (array $i): bool => $state['active'] ? !$i['done'] : true));
        return [
            'on'      => $state['active'],
            'pending' => $pending > 0,
            'text'    => $this->Translate(match (true) {
                $state['active'] && $pending === 0 => 'Vacation mode active',
                $state['active']                   => 'Vacation mode: waiting for the device',
                default                            => 'Back from vacation: waiting for the device',
            }),
        ];
    }

    /**
     * Formular: Abschnitt Urlaubsbetrieb nur bei Kühlschränken, dazu aktueller Stand.
     */
    private function VacationForm(array &$form): void
    {
        $inventory = json_decode($this->ReadAttributeString('Capabilities'), true) ?: [];
        if ($inventory !== [] && array_intersect(array_keys($inventory), self::FRIDGE_COMPONENTS) === []) {
            foreach (['VacationMode', 'VacationVariableID', 'VacationInvert', 'VacationFridgeSetpoint', 'VacationIcemakerOff', 'VacationPowerOff'] as $name) {
                $this->InjectProperty($form['elements'], $name, 'visible', false);
            }
            $this->InjectProperty($form['elements'], 'VacationNotFridge', 'visible', true);
            return;
        }
        $setpoint = $this->Meta()['cooler_Setpoint'] ?? null;
        if (is_array($setpoint) && isset($setpoint['min'], $setpoint['max'])) {
            $this->InjectProperty($form['elements'], 'VacationRange', 'caption', sprintf(
                $this->Translate('Fridge range of this device: %s to %s %s'),
                number_format((float) $setpoint['min'], 0),
                number_format((float) $setpoint['max'], 0),
                (string) $setpoint['unit']
            ));
            $this->InjectProperty($form['elements'], 'VacationRange', 'visible', true);
        }
        if ($this->ReadPropertyInteger('VacationMode') !== 1) {
            return;
        }
        $state = $this->VacationState();
        $id = $this->ReadPropertyInteger('VacationVariableID');
        $lines = [];
        $lines[] = $id > 0 && IPS_VariableExists($id)
            ? sprintf($this->Translate('Vacation switch now: %s → %s'), (string) @GetValueFormatted($id), $this->Translate($this->VacationSwitch() ? 'vacation' : 'no vacation'))
            : $this->Translate('Please choose the vacation switch of the house.');
        if ($state['active']) {
            $lines[] = sprintf($this->Translate('Vacation mode active since %s'), date('d.m.Y H:i', $state['since']));
        }
        $meta = $this->Meta();
        foreach ($state['items'] as $ident => $item) {
            $m = $meta[$ident] ?? null;
            $label = $m === null ? (string) $ident : (($m['prefix'] !== '' ? $this->Translate((string) $m['prefix']) . ': ' : '') . $this->Translate((string) $m['label']));
            $lines[] = sprintf(
                '· %s: %s → %s%s',
                $label,
                $this->VacationText($item['before'], $m),
                $this->VacationText($item['target'], $m),
                $item['done'] === true ? '' : ' (' . $this->Translate('waiting for the device') . ')'
            );
        }
        $this->InjectProperty($form['elements'], 'VacationInfo', 'caption', implode("\n", $lines));
        $this->InjectProperty($form['elements'], 'VacationInfo', 'visible', true);
    }

    private function VacationText(mixed $value, ?array $meta): string
    {
        if (is_bool($value)) {
            return $this->Translate($value ? 'on' : 'off');
        }
        return number_format((float) $value, 0) . ($meta !== null && (string) $meta['unit'] !== '' ? ' ' . $meta['unit'] : '');
    }

    /**
     * Alle Komponenten und Fähigkeiten aus der letzten Statusabfrage für die Liste im Formular.
     */
    private function CapabilityRows(): array
    {
        $inventory = json_decode($this->ReadAttributeString('Capabilities'), true) ?: [];
        $notes = [
            'mapped'   => 'variable',
            'disabled' => 'switched off by the device',
            'vacation' => 'possible vacation capability – commands unknown, not switched',
        ];
        $rows = [];
        foreach ($inventory as $component => $capabilities) {
            $name = self::COMPONENTS[$component] ?? '';
            foreach ((array) $capabilities as $capability => $note) {
                $row = [
                    'component'  => (string) $component . ($name !== '' ? ' (' . $this->Translate($name) . ')' : ''),
                    'capability' => (string) $capability,
                    'note'       => isset($notes[$note]) ? $this->Translate($notes[$note]) : '',
                ];
                if ($note === 'vacation') {
                    $row['rowColor'] = '#FFE9A8';
                }
                $rows[] = $row;
            }
        }
        return $rows;
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
        $result = $this->Api('POST', 'devices/' . $this->DeviceID() . '/commands', [
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
        $result = $this->Api('GET', 'devices/' . $this->DeviceID());
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

    private function DeviceID(): string
    {
        return trim($this->ReadPropertyString('DeviceID'));
    }

    private function ValidDeviceID(): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9-]{8,64}$/', $this->DeviceID());
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
