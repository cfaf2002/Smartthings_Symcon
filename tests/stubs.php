<?php

declare(strict_types=1);

/**
 * Ladetest mit den offiziellen Symcon-Stubs (https://github.com/symcon/SymconStubs).
 *
 * Lädt die Bibliothek wie Symcon und spielt mit einer nachgebauten SmartThings-Cloud
 * (tests/fixtures/cloud.php) alles durch: Anmeldung per OAuth mit rotierendem Refresh-Token,
 * Konfigurator mit Folgeseiten und Räumen, Kühlschrank mit Kühl- und Gefrierteil, Türalarm,
 * Befehle, Handy und Uhr, Kachel in allen Farbschemas.
 *
 * Aufruf: php tests/stubs.php <Pfad zu SymconStubs>
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

$tmp = sys_get_temp_dir() . '/sth-test-' . getmypid();
@mkdir($tmp . '/stubs', 0777, true);

// Die Stubs verlangen für Timer eine Testuhr (getTime). In eine Kopie die normale Uhrzeit eintragen.
foreach (glob($stubs . '/*.php') as $file) {
    $code = (string) file_get_contents($file);
    if (basename($file) === 'ModuleStrictStubs.php') {
        $code = str_replace(
            "throw new Exception('getTime needs to be implemented by module under test');\n    }\n}",
            "return time();\n    }\n}",
            $code
        );
    }
    file_put_contents($tmp . '/stubs/' . basename($file), $code);
}

// Nachgebaute Cloud
$port = 18700 + getmypid() % 1000;
$stateFile = $tmp . '/cloud.json';
file_put_contents($stateFile, '{}');
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/cloud.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['CLOUD_STATE' => $stateFile]
);
register_shutdown_function(static function () use ($tmp, $server): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e, $s, 0.1); $i++) {
    usleep(100000);
}

require $tmp . '/stubs/autoload.php';

\IPS\Kernel::reset();
\IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

$failed = 0;
function ok(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
}

function cloud(?array $change = null): array
{
    global $stateFile;
    $state = json_decode((string) file_get_contents($stateFile), true) ?: [];
    if ($change !== null) {
        $state = array_merge($state, $change);
        file_put_contents($stateFile, json_encode($state));
    }
    return $state;
}

function call(int $id, string $method, mixed ...$args): mixed
{
    $module = \IPS\InstanceManager::getInstanceInterface($id);
    $m = new ReflectionMethod($module, $method);
    return $m->invoke($module, ...$args);
}

function value(int $id, string $ident): mixed
{
    $vid = @IPS_GetObjectIDByIdent($ident, $id);
    return $vid === false ? null : GetValue($vid);
}

const KONTO = '{3EE55FDE-100A-4113-9E1A-F5B6C956BBB4}';
const KONFIGURATOR = '{1945AAC8-A5B8-457A-82CA-AE24A6143B2E}';
const GERAET = '{0E3FCD01-8B22-4987-88AE-26B272A7EA7D}';
const FRIDGE = 'aaaaaaaa-1111-2222-3333-fridge000001';
const PHONE = 'bbbbbbbb-1111-2222-3333-phone0000001';
const WATCH = 'cccccccc-1111-2222-3333-watch0000001';

try {
    echo 'SmartThings Konto' . PHP_EOL;
    $konto = IPS_CreateInstance(KONTO);
    ok(IPS_GetInstance($konto)['InstanceStatus'] === 104, 'Ohne OAuth-App Status 104');
    ok(is_array(json_decode(IPS_GetConfigurationForm($konto), true)), 'Formular ist gültiges JSON');
    call($konto, 'WriteAttributeString', 'Endpoints', json_encode([
        'api'       => 'http://127.0.0.1:' . $port . '/v1/',
        'authorize' => 'http://127.0.0.1:' . $port . '/oauth/authorize',
        'token'     => 'http://127.0.0.1:' . $port . '/oauth/token',
    ]));
    IPS_SetProperty($konto, 'ClientID', 'client-123');
    IPS_SetProperty($konto, 'ClientSecret', 'secret-456');
    IPS_ApplyChanges($konto);
    ok(IPS_GetInstance($konto)['InstanceStatus'] === 201, 'Mit OAuth-App: bitte anmelden (201)');

    $form = (string) json_encode(json_decode(IPS_GetConfigurationForm($konto), true), JSON_UNESCAPED_SLASHES);
    $url = STH_GetAuthorizeURL($konto);
    ok(str_contains($form, $url), 'Formular zeigt die Anmeldeadresse zum Kopieren');
    ok(STH_GetAuthorizeURL($konto) === $url, 'Anmeldeadresse bleibt bis zur Anmeldung gleich');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    ok($q['client_id'] === 'client-123' && $q['response_type'] === 'code' && $q['scope'] === 'r:devices:* x:devices:* r:locations:*' && strlen($q['state']) === 24, 'Anmeldeadresse mit Scopes und State');
    ok(STH_Authorize($konto, 'https://httpbin.org/get?code=GOODCODE&state=falsch') === false, 'Fremder State wird abgelehnt');
    $url = STH_GetAuthorizeURL($konto);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    ok(STH_Authorize($konto, 'BADCODE') === false, 'Falscher Code wird abgelehnt');
    // httpbin.org/get zeigt die Seite als JSON – auch das wird erkannt
    $httpbin = json_encode(['args' => ['code' => 'GOODCODE', 'state' => $q['state']], 'url' => 'https://httpbin.org/get?code=GOODCODE']);
    ok(STH_Authorize($konto, $httpbin) === true, 'Anmeldung mit der httpbin-Seite (JSON), auch nach einem Fehlversuch');
    ok(IPS_GetInstance($konto)['InstanceStatus'] === 102, 'Angemeldet: Status 102');
    ok(cloud()['access'] === 'access-1', 'Zugang erhalten');
    ok(str_contains(IPS_GetConfigurationForm($konto), date('d.m.Y', time() + 86399)), 'Formular zeigt die Gültigkeit');

    // Ablauf: Erneuerung mit rotierendem Refresh-Token
    call($konto, 'WriteAttributeInteger', 'Expires', time() + 100);
    ok(STH_RefreshToken($konto) === true && cloud()['refresh'] === 'refresh-2', 'Erneuerung kurz vor Ablauf, neuer Refresh-Token gespeichert');
    ok(STH_RefreshToken($konto) === true && cloud()['issued'] === 2, 'Kein unnötiges Erneuern bei langer Restlaufzeit');
    // Zugang serverseitig ungültig: 401 → einmal erneuern und wiederholen
    cloud(['access' => 'revoked']);
    $devices = json_decode(STH_GetDevices($konto), true);
    ok(count($devices) === 3, '401 → erneuert und wiederholt; Folgeseite angehängt (3 Geräte)');

    // Unerlaubte Anfragen der Kind-Instanzen
    $answer = json_decode(call($konto, 'ForwardData', json_encode(['Method' => 'DELETE', 'Endpoint' => 'devices/x'])), true);
    ok($answer['Success'] === false && $answer['Error'] === 'request not allowed', 'DELETE wird nicht durchgereicht');
    $answer = json_decode(call($konto, 'ForwardData', json_encode(['Method' => 'GET', 'Endpoint' => '../../etc/passwd'])), true);
    ok($answer['Success'] === false, 'Fremde Pfade werden nicht durchgereicht');

    echo 'SmartThings Konfigurator' . PHP_EOL;
    $konfig = IPS_CreateInstance(KONFIGURATOR);
    IPS_ConnectInstance($konfig, $konto);
    $form = json_decode(IPS_GetConfigurationForm($konfig), true);
    $rows = $form['actions'][1]['values'];
    ok(count($rows) === 3, 'Drei Geräte im Konfigurator');
    $byID = array_column($rows, null, 'deviceID');
    ok($byID[FRIDGE]['room'] === 'Küche' && $byID[FRIDGE]['kind'] === 'Refrigerator' && $byID[FRIDGE]['model'] === 'RB38C7B6AS9', 'Kühlschrank mit Raum, Art und Modell');
    ok($byID[PHONE]['kind'] === 'Phone' && $byID[WATCH]['kind'] === 'Watch', 'Handy und Uhr erkannt');
    ok($byID[FRIDGE]['create']['moduleID'] === GERAET && $byID[FRIDGE]['create']['configuration']['DeviceID'] === FRIDGE, 'Anlegen mit Geräte-ID');

    echo 'SmartThings Gerät – Kühlschrank' . PHP_EOL;
    $fridge = IPS_CreateInstance(GERAET);
    IPS_ConnectInstance($fridge, $konto);
    IPS_ApplyChanges($fridge);
    ok(IPS_GetInstance($fridge)['InstanceStatus'] === 104, 'Ohne Gerät Status 104');
    ok(str_contains(STH_GetVisualizationTile($fridge), 'window.handleMessage'), 'Kachel auch ohne Gerät');
    IPS_SetProperty($fridge, 'DeviceID', FRIDGE);
    IPS_ApplyChanges($fridge);
    ok(IPS_GetInstance($fridge)['InstanceStatus'] === 102, 'Mit Gerät Status 102');
    $expect = [
        'Online' => true, 'cooler_Temperature' => 4.4, 'cooler_Setpoint' => 4.0, 'freezer_Temperature' => -18.0, 'freezer_Setpoint' => -18.0,
        'Door' => false, 'cooler_Door' => false, 'PowerCool' => false, 'PowerFreeze' => false, 'Power' => 85.0, 'Energy' => 123.456,
        'FilterUsage' => 92, 'FilterStatus' => 'replace', 'icemaker_Switch' => true, 'DoorAlarm' => false,
    ];
    foreach ($expect as $ident => $v) {
        ok(value($fridge, $ident) === $v, 'Variable ' . $ident . ' = ' . var_export($v, true));
    }
    ok(value($fridge, 'freezer_Door') === null, 'Abgeschaltete Fähigkeit (Gefrierteil-Tür) ausgelassen');
    ok(value($fridge, 'RapidCooling') === null, 'Schnellkühlen nur einmal (Power Cool statt refrigeration)');
    ok(value($fridge, 'Temperature') === null, 'Leerer Wert ohne Variable');
    ok(IPS_GetName(IPS_GetObjectIDByIdent('cooler_Temperature', $fridge)) === 'Fridge: Temperature', 'Name mit Fach');
    $variable = IPS_GetVariable(IPS_GetObjectIDByIdent('cooler_Setpoint', $fridge));
    $presentation = $variable['VariablePresentation'] ?: $variable['VariableCustomPresentation'];
    ok(($presentation['MIN'] ?? null) == 1 && ($presentation['MAX'] ?? null) == 7 && ($presentation['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SLIDER, 'Schieberegler 1–7 °C aus dem Gerät' . ' ' . json_encode($presentation));
    ok(in_array('samsungce.deviceIdentification', json_decode(call($fridge, 'ReadAttributeString', 'Unmapped'), true), true), 'Unbekannte Fähigkeiten werden gemeldet');
    ok(str_contains(IPS_GetConfigurationForm($fridge), 'samsungce.deviceIdentification'), 'Formular zeigt unbekannte Fähigkeiten');

    // Befehle
    RequestAction(IPS_GetObjectIDByIdent('cooler_Setpoint', $fridge), 3);
    $last = end(cloud()['commands']);
    ok($last === ['component' => 'cooler', 'capability' => 'thermostatCoolingSetpoint', 'command' => 'setCoolingSetpoint', 'arguments' => [3]], 'Solltemperatur Kühlteil 3 °C');
    ok(value($fridge, 'cooler_Setpoint') === 3.0, 'Variable sofort nachgeführt');
    RequestAction(IPS_GetObjectIDByIdent('cooler_Setpoint', $fridge), 12);
    ok(end(cloud()['commands'])['arguments'] === [7], 'Solltemperatur auf den Gerätebereich begrenzt (7 °C)');
    IPS_RequestAction($fridge, 'Step', 'freezer_Setpoint:-1');
    ok(end(cloud()['commands'])['arguments'] === [-19] && end(cloud()['commands'])['component'] === 'freezer', 'Kachel: Gefrierteil einen Schritt kälter');
    RequestAction(IPS_GetObjectIDByIdent('PowerCool', $fridge), true);
    ok(end(cloud()['commands'])['command'] === 'activate', 'Power Cool einschalten');
    RequestAction(IPS_GetObjectIDByIdent('icemaker_Switch', $fridge), false);
    ok(end(cloud()['commands']) === ['component' => 'icemaker', 'capability' => 'switch', 'command' => 'off', 'arguments' => []], 'Eiswürfelbereiter aus');
    ok(STH_SendCommand($fridge, 'main', 'refrigeration', 'setRapidFreezing', '["on"]') === true, 'Beliebiger Befehl (STH_SendCommand)');
    ok(STH_SendCommand($fridge, 'main', 'x"; drop', 'y', '[]') === false, 'Ungültiger Befehl abgelehnt');
    STH_Update($fridge);
    ok(value($fridge, 'PowerCool') === true && value($fridge, 'freezer_Setpoint') === -19.0, 'Abruf bestätigt die Änderungen');

    // Türalarm
    cloud(['door' => 'open', 'doorSince' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 60)]);
    STH_Update($fridge);
    ok(value($fridge, 'Door') === true && value($fridge, 'DoorAlarm') === false, 'Tür seit 1 Minute offen: noch kein Alarm');
    cloud(['doorSince' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 180)]);
    STH_Update($fridge);
    ok(value($fridge, 'DoorAlarm') === true, 'Tür seit 3 Minuten offen: Alarm');
    $tile = json_decode(call($fridge, 'ReadAttributeString', 'TileData'), true);
    ok($tile['doorAlarm'] === true && $tile['openFor'] >= 170, 'Kachel zeigt den Türalarm mit Dauer');
    cloud(['door' => 'closed', 'doorSince' => '']);
    STH_Update($fridge);
    ok(value($fridge, 'DoorAlarm') === false, 'Tür zu: Alarm aus');

    // Kachel
    $tile = json_decode(call($fridge, 'ReadAttributeString', 'TileData'), true);
    ok(count($tile['compartments']) === 2 && $tile['compartments'][0]['title'] === 'Fridge' && $tile['compartments'][0]['door'] === false, 'Kachel: Kühlteil mit Tür');
    ok($tile['compartments'][1]['freezer'] === true && $tile['compartments'][1]['min'] == -23, 'Kachel: Gefrierteil mit Standardbereich');
    ok(count($tile['toggles']) === 3, 'Kachel: Power Cool, Power Freeze, Eiswürfel');
    foreach ([1, 2, 0] as $theme) {
        IPS_SetProperty($fridge, 'TileTheme', $theme);
        IPS_ApplyChanges($fridge);
        ok(str_contains(STH_GetVisualizationTile($fridge), '"theme":' . $theme), 'Kachel mit Farbschema ' . $theme);
    }
    ok(!str_contains(STH_GetVisualizationTile($fridge), '/*INITIAL_DATA*/'), 'Kachel mit Startdaten');

    // offline
    cloud(['offline' => true]);
    STH_Update($fridge);
    ok(value($fridge, 'Online') === false, 'Gerät offline erkannt');
    $warnings = [];
    set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
        if ($no === E_USER_WARNING) {
            $warnings[] = $str;
            return true;
        }
        return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
    });
    $thrown = false;
    $before = value($fridge, 'icemaker_Switch');
    try {
        RequestAction(IPS_GetObjectIDByIdent('icemaker_Switch', $fridge), !$before);
    } catch (Throwable $e) {
        $thrown = true;
    }
    restore_error_handler();
    ok(!$thrown && count($warnings) === 1 && str_contains($warnings[0], 'offline') && str_contains($warnings[0], 'Ice maker'), 'Befehl an Gerät offline: Warnung mit Grund statt Abbruch');
    ok(value($fridge, 'icemaker_Switch') === $before, 'Variable bleibt beim abgelehnten Befehl unverändert');
    cloud(['offline' => false]);

    echo 'SmartThings Gerät – Handy und Uhr' . PHP_EOL;
    $phone = IPS_CreateInstance(GERAET);
    IPS_ConnectInstance($phone, $konto);
    IPS_SetProperty($phone, 'DeviceID', PHONE);
    IPS_ApplyChanges($phone);
    ok(value($phone, 'Presence') === true && value($phone, 'Battery') === 64, 'Handy: Anwesenheit und Akku');
    ok(value($phone, 'DoorAlarm') === null, 'Handy: kein Türalarm');
    $watch = IPS_CreateInstance(GERAET);
    IPS_ConnectInstance($watch, $konto);
    IPS_SetProperty($watch, 'DeviceID', WATCH);
    IPS_ApplyChanges($watch);
    $tile = json_decode(call($watch, 'ReadAttributeString', 'TileData'), true);
    ok(value($watch, 'Battery') === 12 && $tile['values'][0]['tone'] === 'bad', 'Uhr: Akku 12 % rot markiert');

    $unknown = IPS_CreateInstance(GERAET);
    IPS_ConnectInstance($unknown, $konto);
    IPS_SetProperty($unknown, 'DeviceID', 'dddddddd-0000-0000-0000-000000000000');
    IPS_ApplyChanges($unknown);
    ok(IPS_GetInstance($unknown)['InstanceStatus'] === 202, 'Unbekanntes Gerät: Status 202');

    echo 'Konto abgemeldet' . PHP_EOL;
    IPS_RequestAction($konto, 'SignOut', 0);
    ok(IPS_GetInstance($konto)['InstanceStatus'] === 201, 'Abgemeldet: bitte anmelden');
    ok(STH_Update($fridge) === false && IPS_GetInstance($fridge)['InstanceStatus'] === 201, 'Gerät meldet fehlende Verbindung');
    // abgelehnter Refresh-Token
    call($konto, 'WriteAttributeString', 'RefreshToken', 'veraltet');
    ok(STH_RefreshToken($konto) === false && IPS_GetInstance($konto)['InstanceStatus'] === 202, 'Abgelehnter Refresh-Token: Status 202');

    // Personal Access Token
    IPS_SetProperty($konto, 'AuthMode', 1);
    IPS_ApplyChanges($konto);
    ok(IPS_GetInstance($konto)['InstanceStatus'] === 104, 'Token-Modus ohne Token: 104');

    // Alle öffentlichen Funktionen mit Typen
    $missing = [];
    foreach (['SmartThingsKonto', 'SmartThingsKonfigurator', 'SmartThingsGeraet'] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with((string) $method->getFileName(), (string) realpath(__DIR__ . '/..'))) {
                continue;
            }
            if (!$method->hasReturnType()) {
                $missing[] = $class . '::' . $method->getName() . '()';
            }
            foreach ($method->getParameters() as $p) {
                if (!$p->hasType()) {
                    $missing[] = $class . '::' . $method->getName() . ' $' . $p->getName();
                }
            }
        }
    }
    ok($missing === [], 'Öffentliche Funktionen vollständig typisiert' . ($missing ? ': ' . implode(', ', $missing) : ''));
} catch (Throwable $e) {
    ok(false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

echo PHP_EOL . ($failed === 0 ? 'Ladetest bestanden.' : $failed . ' Prüfung(en) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
