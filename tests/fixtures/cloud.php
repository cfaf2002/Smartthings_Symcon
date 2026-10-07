<?php

declare(strict_types=1);

/*
 * Nachgebaute SmartThings-Cloud für die Tests (Router für den eingebauten PHP-Server).
 * /oauth/authorize, /oauth/token (Code und Refresh mit rotierendem Refresh-Token), /v1/devices (zwei Seiten),
 * /v1/devices/{id}, …/status, …/health, …/commands und /v1/locations/{id}/rooms.
 * Zustand in der Datei aus der Umgebungsvariable CLOUD_STATE.
 *
 * SPDX-License-Identifier: MIT
 */

$file = (string) getenv('CLOUD_STATE');
$state = json_decode((string) @file_get_contents($file), true) ?: [];
$state += ['access' => '', 'refresh' => '', 'issued' => 0, 'commands' => [], 'cooler' => 4, 'freezer' => -18, 'powerCool' => false, 'door' => 'closed', 'doorSince' => '', 'offline' => false];
$save = static function () use ($file, &$state): void {
    file_put_contents($file, json_encode($state));
};
$json = static function (mixed $data, int $code = 200): bool {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    return true;
};

$uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$base = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/v1/';

// --- OAuth ---------------------------------------------------------------
if ($uri === '/oauth/token' && $method === 'POST') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Basic ' . base64_encode('client-123:secret-456')) {
        return $json(['error' => 'invalid_client'], 401);
    }
    parse_str((string) file_get_contents('php://input'), $form);
    $ok = ($form['grant_type'] ?? '') === 'authorization_code' && ($form['code'] ?? '') === 'GOODCODE' && ($form['redirect_uri'] ?? '') === 'https://httpbin.org/get'
        || ($form['grant_type'] ?? '') === 'refresh_token' && ($form['refresh_token'] ?? '') === $state['refresh'] && $state['refresh'] !== '';
    if (!$ok) {
        return $json(['error' => 'invalid_grant', 'error_description' => 'Invalid grant'], 400);
    }
    $state['issued']++;
    $state['access'] = 'access-' . $state['issued'];
    $state['refresh'] = 'refresh-' . $state['issued'];
    $save();
    return $json(['access_token' => $state['access'], 'refresh_token' => $state['refresh'], 'expires_in' => 86399, 'token_type' => 'bearer', 'scope' => 'r:devices:* x:devices:*']);
}

// --- API -------------------------------------------------------------------
if (!str_starts_with($uri, '/v1/')) {
    return $json(['error' => 'not found'], 404);
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $state['access'] || $state['access'] === '') {
    return $json(['error' => ['code' => 'UnauthorizedError', 'message' => 'Unauthorized']], 401);
}
$path = substr($uri, 4);

$fridge = [
    'deviceId' => 'aaaaaaaa-1111-2222-3333-fridge000001', 'name' => 'Samsung-Refrigerator', 'label' => 'Kühlschrank',
    'locationId' => 'loc-12345678', 'roomId' => 'room-kitchen', 'deviceTypeName' => 'Samsung OCF Refrigerator',
    'ocf' => ['ocfDeviceType' => 'oic.d.refrigerator', 'modelNumber' => 'RB38C7B6AS9', 'manufacturerName' => 'Samsung Electronics'],
    'components' => [['id' => 'main', 'categories' => [['name' => 'Refrigerator']]]],
];
$phone = [
    'deviceId' => 'bbbbbbbb-1111-2222-3333-phone0000001', 'name' => 'Galaxy S24', 'label' => 'Armins Handy',
    'locationId' => 'loc-12345678', 'roomId' => '', 'ocf' => ['ocfDeviceType' => 'oic.d.smartphone', 'modelNumber' => 'SM-S921B'],
    'components' => [['id' => 'main', 'categories' => [['name' => 'SmartPhone']]]],
];
$watch = [
    'deviceId' => 'cccccccc-1111-2222-3333-watch0000001', 'name' => 'Galaxy Watch6', 'label' => 'Uhr',
    'locationId' => 'loc-12345678', 'ocf' => ['ocfDeviceType' => 'oic.d.wearable'],
    'components' => [['id' => 'main', 'categories' => [['name' => 'Watch']]]],
];
$devices = [$fridge['deviceId'] => $fridge, $phone['deviceId'] => $phone, $watch['deviceId'] => $watch];

if ($path === 'devices' && ($_GET['page'] ?? '') === '1') {
    return $json(['items' => [$watch], '_links' => []]);
}
if ($path === 'devices' && $method === 'GET') {
    // zwei Seiten, wie bei vielen Geräten
    return $json(['items' => [$fridge, $phone], '_links' => ['next' => ['href' => $base . 'devices?page=1']]]);
}
if (preg_match('#^locations/([^/]+)/rooms$#', $path)) {
    return $json(['items' => [['roomId' => 'room-kitchen', 'name' => 'Küche']]]);
}
if (!preg_match('#^devices/([^/]+)(?:/(status|health|commands))?$#', $path, $m) || !isset($devices[$m[1]])) {
    return $json(['error' => ['code' => 'NotFoundError', 'message' => 'Device not found']], 404);
}
$id = $m[1];
$what = $m[2] ?? '';

if ($what === '') {
    return $json($devices[$id]);
}
if ($what === 'health') {
    return $json(['deviceId' => $id, 'state' => $state['offline'] ? 'OFFLINE' : 'ONLINE']);
}
if ($what === 'commands' && $method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    foreach ($body['commands'] ?? [] as $c) {
        $state['commands'][] = $c;
        if ($c['capability'] === 'thermostatCoolingSetpoint') {
            $state[$c['component']] = $c['arguments'][0];
        }
        if ($c['capability'] === 'samsungce.powerCool') {
            $state['powerCool'] = $c['command'] === 'activate';
        }
    }
    $save();
    return $json(['results' => [['id' => 'x', 'status' => 'ACCEPTED']]]);
}

// Status
$v = static fn (mixed $value, ?string $unit = null, string $ts = '2026-10-07T06:00:00.000Z'): array => array_filter(['value' => $value, 'unit' => $unit, 'timestamp' => $ts], static fn ($x) => $x !== null);
if ($id === $fridge['deviceId']) {
    return $json(['components' => [
        'main' => [
            'contactSensor'          => ['contact' => $v($state['door'], null, $state['doorSince'] ?: '2026-10-07T06:00:00.000Z')],
            'powerConsumptionReport' => ['powerConsumption' => $v(['power' => 85, 'energy' => 123456, 'deltaEnergy' => 12])],
            'custom.waterFilter'     => ['waterFilterUsage' => $v(92, '%'), 'waterFilterStatus' => $v('replace')],
            'samsungce.powerCool'    => ['activated' => $v($state['powerCool'])],
            'samsungce.powerFreeze'  => ['activated' => $v(false)],
            'refrigeration'          => ['rapidCooling' => $v('off'), 'rapidFreezing' => $v('off')],
            'samsungce.deviceIdentification' => ['modelName' => $v('RB38')],
            'temperatureMeasurement' => ['temperature' => $v(null)],
        ],
        'cooler' => [
            'temperatureMeasurement'    => ['temperature' => $v($state['cooler'] + 0.4, 'C')],
            'thermostatCoolingSetpoint' => ['coolingSetpoint' => $v($state['cooler'], 'C')],
            'custom.thermostatSetpointControl' => ['minimumSetpoint' => $v(1, 'C'), 'maximumSetpoint' => $v(7, 'C')],
            'contactSensor'             => ['contact' => $v($state['door'], null, $state['doorSince'] ?: '2026-10-07T06:00:00.000Z')],
        ],
        'freezer' => [
            'temperatureMeasurement'    => ['temperature' => $v($state['freezer'], 'C')],
            'thermostatCoolingSetpoint' => ['coolingSetpoint' => $v($state['freezer'], 'C')],
            'custom.disabledCapabilities' => ['disabledCapabilities' => $v(['contactSensor'])],
            'contactSensor'             => ['contact' => $v('open')],
        ],
        'icemaker' => [
            'switch' => ['switch' => $v('on')],
        ],
    ]]);
}
if ($id === $phone['deviceId']) {
    return $json(['components' => ['main' => [
        'presenceSensor' => ['presence' => $v('present')],
        'battery'        => ['battery' => $v(64, '%')],
        'samsungce.findMe' => ['status' => $v('idle')],
    ]]]);
}
return $json(['components' => ['main' => [
    'battery' => ['battery' => $v(12, '%')],
]]]);
