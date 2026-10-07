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

/**
 * SmartThings Konfigurator: zeigt alle Geräte des Kontos mit Raum und Art und legt sie als Instanzen an.
 */
class SmartThingsKonfigurator extends IPSModuleStrict
{
    // Gerätearten nach OCF-Typ bzw. Kategorie
    private const KINDS = [
        'oic.d.refrigerator'     => 'Refrigerator',
        'oic.d.smartphone'       => 'Phone',
        'oic.d.wearable'         => 'Watch',
        'oic.d.watch'            => 'Watch',
        'x.com.st.d.mobile.presence' => 'Phone (presence)',
        'oic.d.tv'               => 'TV',
        'oic.d.washer'           => 'Washer',
        'oic.d.dryer'            => 'Dryer',
        'oic.d.dishwasher'       => 'Dishwasher',
        'oic.d.airconditioner'   => 'Air conditioner',
        'oic.d.robotcleaner'     => 'Robot cleaner',
        'oic.d.oven'             => 'Oven',
    ];

    public function Create(): void
    {
        parent::Create();
    }

    public function GetCompatibleParents(): string
    {
        return (string) json_encode(['type' => 'require', 'moduleIDs' => [STH::MODUL_KONTO]]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);

        if (!$this->HasActiveParent()) {
            $form['actions'][0]['caption'] = $this->Translate('The account is not connected. Please set up the account instance first.');
            $form['actions'][0]['visible'] = true;
            return (string) json_encode($form);
        }

        $result = STH::Response(@$this->SendDataToParent(STH::Request('GET', 'devices')));
        $devices = $result['Success'] && is_array($result['Data']) ? ($result['Data']['items'] ?? []) : [];
        if (!$result['Success']) {
            $form['actions'][0]['caption'] = $this->Translate('Devices could not be loaded') . ': ' . $result['Error'];
            $form['actions'][0]['visible'] = true;
        }
        $rooms = $this->Rooms($devices);

        // Vorhandene Geräte-Instanzen am selben Konto
        $parent = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $existing = [];
        foreach (IPS_GetInstanceListByModuleID(STH::MODUL_GERAET) as $id) {
            if ((int) IPS_GetInstance($id)['ConnectionID'] === $parent) {
                $existing[(string) IPS_GetProperty($id, 'DeviceID')] = $id;
            }
        }

        $values = [];
        foreach ($devices as $device) {
            $deviceID = (string) ($device['deviceId'] ?? '');
            if ($deviceID === '' || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $deviceID)) {
                continue;
            }
            $name = (string) ($device['label'] ?? '') !== '' ? (string) $device['label'] : (string) ($device['name'] ?? $deviceID);
            $values[] = [
                'name'       => $name,
                'room'       => $rooms[(string) ($device['roomId'] ?? '')] ?? '',
                'kind'       => $this->Translate($this->Kind($device)),
                'model'      => (string) ($device['ocf']['modelNumber'] ?? $device['deviceManufacturerCode'] ?? ''),
                'deviceID'   => $deviceID,
                'instanceID' => $existing[$deviceID] ?? 0,
                'create'     => [
                    'moduleID'      => STH::MODUL_GERAET,
                    'name'          => $name,
                    'configuration' => ['DeviceID' => $deviceID],
                ],
            ];
            unset($existing[$deviceID]);
        }
        // Instanzen, deren Gerät es im Konto nicht mehr gibt
        foreach ($existing as $deviceID => $id) {
            $values[] = ['name' => IPS_GetName($id), 'room' => '', 'kind' => '', 'model' => '', 'deviceID' => (string) $deviceID, 'instanceID' => $id];
        }
        $form['actions'][1]['values'] = $values;
        return (string) json_encode($form);
    }

    private function Kind(array $device): string
    {
        $type = strtolower((string) ($device['ocf']['ocfDeviceType'] ?? $device['ocfDeviceType'] ?? ''));
        if (isset(self::KINDS[$type])) {
            return self::KINDS[$type];
        }
        foreach ($device['components'] ?? [] as $component) {
            foreach ($component['categories'] ?? [] as $category) {
                $name = strtolower((string) ($category['name'] ?? ''));
                if ($name === 'refrigerator') {
                    return 'Refrigerator';
                }
                if ($name === 'mobilepresence' || $name === 'mobile') {
                    return 'Phone (presence)';
                }
                if ($name === 'smartphone') {
                    return 'Phone';
                }
                if ($name === 'watch' || $name === 'wearable') {
                    return 'Watch';
                }
                if ($name !== '' && $name !== 'other') {
                    return ucfirst($name);
                }
            }
        }
        return 'Device';
    }

    /**
     * Raumnamen je Standort (eine Anfrage pro Standort).
     */
    private function Rooms(array $devices): array
    {
        $rooms = [];
        $locations = array_unique(array_filter(array_map(static fn (array $d): string => (string) ($d['locationId'] ?? ''), $devices)));
        foreach ($locations as $location) {
            if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $location)) {
                continue;
            }
            $result = STH::Response(@$this->SendDataToParent(STH::Request('GET', 'locations/' . $location . '/rooms')));
            foreach ($result['Success'] ? ($result['Data']['items'] ?? []) : [] as $room) {
                $rooms[(string) ($room['roomId'] ?? '')] = (string) ($room['name'] ?? '');
            }
        }
        return $rooms;
    }
}
