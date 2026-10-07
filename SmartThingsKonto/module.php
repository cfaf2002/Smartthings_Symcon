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
 * SmartThings Konto: Anmeldung (OAuth 2.0 mit eigener OAuth-App oder Personal Access Token)
 * und alle Anfragen an die SmartThings-Schnittstelle. Kind-Instanzen schicken ihre Anfragen hierher.
 */
class SmartThingsKonto extends IPSModuleStrict
{
    private const MODE_OAUTH = 0;
    private const MODE_PAT = 1;

    private const STATUS_SIGN_IN = 201;
    private const STATUS_AUTH_FAILED = 202;
    private const STATUS_NO_CONNECTION = 203;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('AuthMode', self::MODE_OAUTH);
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('RedirectURI', 'https://httpbin.org/get');
        $this->RegisterPropertyString('Token', '');

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('Expires', 0);
        $this->RegisterAttributeString('State', '');
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('Credentials', '');
        // Nur für automatische Tests: andere Adressen der Schnittstelle (JSON mit api/authorize/token)
        $this->RegisterAttributeString('Endpoints', '');

        $this->RegisterTimer('Refresh', 0, 'STH_RefreshToken($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyInteger('AuthMode') === self::MODE_PAT) {
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(trim($this->ReadPropertyString('Token')) === '' ? 104 : 102);
            return;
        }

        // Neue OAuth-App eingetragen: alte Anmeldung verwerfen
        $credentials = hash('sha256', trim($this->ReadPropertyString('ClientID')) . "\n" . trim($this->ReadPropertyString('ClientSecret')));
        if ($credentials !== $this->ReadAttributeString('Credentials')) {
            $this->WriteAttributeString('Credentials', $credentials);
            $this->ClearTokens();
        }

        if (trim($this->ReadPropertyString('ClientID')) === '' || trim($this->ReadPropertyString('ClientSecret')) === '') {
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(104);
            return;
        }
        // Erneuert stündlich, sobald weniger als zwei Stunden Gültigkeit übrig sind
        $this->SetTimerInterval('Refresh', 3600 * 1000);
        $this->SetStatus($this->ReadAttributeString('RefreshToken') === '' ? self::STATUS_SIGN_IN : 102);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Authorize':
                echo $this->Authorize((string) $Value)
                    ? $this->Translate('Signed in. The account is connected.')
                    : $this->Translate('Sign-in failed') . ': ' . $this->ReadAttributeString('LastError');
                break;
            case 'SignOut':
                $this->ClearTokens();
                $this->SetStatus($this->ReadPropertyInteger('AuthMode') === self::MODE_OAUTH ? self::STATUS_SIGN_IN : 104);
                echo $this->Translate('Signed out.');
                break;
            case 'Test':
                $result = $this->Api('GET', 'devices');
                echo $result['Success']
                    ? sprintf($this->Translate('Connection works: %d devices found.'), count($result['Data']['items'] ?? []))
                    : $this->Translate('Connection failed') . ': ' . $result['Error'];
                break;
            default:
                throw new Exception('Invalid ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $oauth = $this->ReadPropertyInteger('AuthMode') === self::MODE_OAUTH;
        $expires = $this->ReadAttributeInteger('Expires');
        if (!$oauth) {
            $state = trim($this->ReadPropertyString('Token')) === '' ? 'Please enter a token.' : 'Personal access token – new tokens are only valid for 24 hours.';
            $caption = $this->Translate($state);
        } elseif ($this->ReadAttributeString('RefreshToken') === '') {
            $caption = $this->Translate('Not signed in yet.');
        } else {
            $caption = sprintf($this->Translate('Signed in – access valid until %s, renewed automatically.'), date('d.m.Y H:i', $expires));
        }
        $error = $this->ReadAttributeString('LastError');
        if ($error !== '') {
            $caption .= ' ' . $this->Translate('Last error') . ': ' . $error;
        }
        $this->InjectProperty($form['elements'], 'AuthState', 'caption', $caption);
        foreach (['OAuthPanel'] as $name) {
            $this->InjectProperty($form['elements'], $name, 'visible', $oauth);
        }
        // Anmeldeadresse zum Kopieren (erst nach dem Speichern von Client-ID und Secret)
        $url = $oauth ? $this->GetAuthorizeURL() : '';
        $this->InjectProperty($form['elements'], 'AuthorizeURL', 'value', $url);
        $this->InjectProperty($form['elements'], 'AuthorizeURL', 'visible', $url !== '');
        $this->InjectProperty($form['elements'], 'SaveFirst', 'visible', $oauth && $url === '');
        $this->InjectProperty($form['elements'], 'Token', 'visible', !$oauth);
        $this->InjectProperty($form['elements'], 'TokenHint', 'visible', !$oauth);
        return (string) json_encode($form);
    }

    /**
     * Anfragen der Kind-Instanzen: {Method, Endpoint, Body}.
     */
    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return (string) json_encode(['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'invalid request']);
        }
        $result = $this->Api((string) ($data['Method'] ?? 'GET'), (string) ($data['Endpoint'] ?? ''), (array) ($data['Body'] ?? []));
        return (string) json_encode($result);
    }

    // ------------------------------------------------------------------
    // Öffentliche Befehle (STH_…)
    // ------------------------------------------------------------------

    /**
     * Adresse zum Anmelden bei SmartThings (im Browser öffnen).
     */
    public function GetAuthorizeURL(): string
    {
        $clientID = trim($this->ReadPropertyString('ClientID'));
        if ($clientID === '') {
            return '';
        }
        // Gleiche Adresse bis zur erfolgreichen Anmeldung (Formularfeld und Skripte passen zusammen)
        $state = $this->ReadAttributeString('State');
        if ($state === '') {
            $state = bin2hex(random_bytes(12));
            $this->WriteAttributeString('State', $state);
        }
        return $this->Endpoint('authorize') . '?' . http_build_query([
            'client_id'     => $clientID,
            'response_type' => 'code',
            'redirect_uri'  => $this->RedirectURI(),
            'scope'         => STH::SCOPES,
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Schließt die Anmeldung ab. Erwartet den Code oder die ganze Adresse, auf die SmartThings
     * nach dem Anmelden weitergeleitet hat.
     */
    public function Authorize(string $Response): bool
    {
        $response = trim($Response);
        $code = $response;
        if (str_contains($response, 'code=') || str_starts_with($response, 'http') || str_starts_with($response, '{')) {
            $code = '';
            $state = '';
            // httpbin.org/get zeigt die Parameter als JSON unter "args"
            $json = json_decode($response, true);
            if (is_array($json) && is_array($json['args'] ?? null)) {
                $code = (string) ($json['args']['code'] ?? '');
                $state = (string) ($json['args']['state'] ?? '');
            } else {
                $query = (string) (parse_url($response, PHP_URL_QUERY) ?? $response);
                parse_str($query, $args);
                $code = (string) ($args['code'] ?? '');
                $state = (string) ($args['state'] ?? '');
            }
            if ($state !== '' && $state !== $this->ReadAttributeString('State')) {
                return $this->Fail('the sign-in link is outdated – please sign in again');
            }
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{4,512}$/', $code)) {
            return $this->Fail('no code found – paste the complete address after signing in');
        }
        $result = $this->TokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'client_id'    => trim($this->ReadPropertyString('ClientID')),
            'redirect_uri' => $this->RedirectURI(),
        ]);
        if ($result) {
            // Anmeldeadresse ist verbraucht; bei Fehlern darf es mit derselben Adresse erneut versucht werden
            $this->WriteAttributeString('State', '');
        }
        return $result;
    }

    /**
     * Erneuert den Zugang, wenn er bald abläuft (Timer, stündlich).
     */
    public function RefreshToken(): bool
    {
        if ($this->ReadPropertyInteger('AuthMode') !== self::MODE_OAUTH || $this->ReadAttributeString('RefreshToken') === '') {
            return false;
        }
        if ($this->ReadAttributeInteger('Expires') - time() > 7200 && $this->ReadAttributeString('AccessToken') !== '') {
            return true;
        }
        return $this->Renew();
    }

    /**
     * Alle Geräte des Kontos als JSON (für Skripte).
     */
    public function GetDevices(): string
    {
        $result = $this->Api('GET', 'devices');
        return (string) json_encode($result['Success'] ? ($result['Data']['items'] ?? []) : []);
    }

    // ------------------------------------------------------------------
    // Schnittstelle
    // ------------------------------------------------------------------

    /**
     * Anfrage an die SmartThings-Schnittstelle. Folgt bei Listen den Folgeseiten.
     */
    private function Api(string $Method, string $Endpoint, array $Body = []): array
    {
        $method = strtoupper($Method);
        $endpoint = ltrim($Endpoint, '/');
        $base = $this->Endpoint('api');
        if (str_starts_with($endpoint, $base)) {
            $endpoint = substr($endpoint, strlen($base));
        }
        if (!in_array($method, ['GET', 'POST'], true) || !preg_match(STH::ENDPOINT_PATTERN, $endpoint)) {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'request not allowed'];
        }
        $token = $this->AccessToken();
        if ($token === '') {
            return ['Success' => false, 'Code' => 401, 'Data' => null, 'Error' => $this->Translate('not signed in')];
        }

        $result = $this->Http($method, $base . $endpoint, $Body, $token);
        if ($result['Code'] === 401 && $this->ReadPropertyInteger('AuthMode') === self::MODE_OAUTH && $this->Renew()) {
            $result = $this->Http($method, $base . $endpoint, $Body, $this->ReadAttributeString('AccessToken'));
        }

        // Listen: Folgeseiten anhängen (höchstens 20)
        $page = 0;
        while ($result['Success'] && $method === 'GET' && is_array($result['Data']) && isset($result['Data']['items'])
            && is_string($result['Data']['_links']['next']['href'] ?? null) && $page++ < 20) {
            $next = (string) $result['Data']['_links']['next']['href'];
            if (!str_starts_with($next, $base)) {
                break;
            }
            $more = $this->Http('GET', $next, [], $this->ReadAttributeString('AccessToken') ?: $token);
            if (!$more['Success']) {
                break;
            }
            $result['Data']['items'] = array_merge($result['Data']['items'], $more['Data']['items'] ?? []);
            $result['Data']['_links'] = $more['Data']['_links'] ?? [];
        }

        if ($result['Success']) {
            $this->WriteAttributeString('LastError', '');
            if ($this->GetStatus() !== 102) {
                $this->SetStatus(102);
            }
        } elseif ($result['Code'] === 0) {
            $this->SetStatus(self::STATUS_NO_CONNECTION);
        } elseif ($result['Code'] === 401) {
            $this->SetStatus($this->ReadPropertyInteger('AuthMode') === self::MODE_OAUTH ? self::STATUS_AUTH_FAILED : 104);
        }
        return $result;
    }

    private function AccessToken(): string
    {
        if ($this->ReadPropertyInteger('AuthMode') === self::MODE_PAT) {
            return trim($this->ReadPropertyString('Token'));
        }
        if ($this->ReadAttributeString('RefreshToken') === '') {
            return '';
        }
        if ($this->ReadAttributeString('AccessToken') === '' || $this->ReadAttributeInteger('Expires') - time() < 300) {
            $this->Renew();
        }
        return $this->ReadAttributeString('AccessToken');
    }

    private function Renew(): bool
    {
        $refresh = $this->ReadAttributeString('RefreshToken');
        if ($refresh === '') {
            return false;
        }
        // Gleichzeitige Erneuerung aus mehreren Threads vermeiden
        if (!IPS_SemaphoreEnter('STH_Token_' . $this->InstanceID, 15000)) {
            return false;
        }
        try {
            // Wurde inzwischen von einem anderen Thread erneuert?
            if ($this->ReadAttributeString('RefreshToken') !== $refresh && $this->ReadAttributeInteger('Expires') - time() > 300) {
                return true;
            }
            return $this->TokenRequest([
                'grant_type'    => 'refresh_token',
                'client_id'     => trim($this->ReadPropertyString('ClientID')),
                'refresh_token' => $refresh,
            ]);
        } finally {
            IPS_SemaphoreLeave('STH_Token_' . $this->InstanceID);
        }
    }

    private function TokenRequest(array $fields): bool
    {
        $ch = curl_init($this->Endpoint('token'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode(trim($this->ReadPropertyString('ClientID')) . ':' . trim($this->ReadPropertyString('ClientSecret'))),
            ],
        ] + $this->CurlDefaults());
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $this->SetStatus(self::STATUS_NO_CONNECTION);
            return $this->Fail(curl_error($ch));
        }
        $data = json_decode((string) $raw, true);
        if ($code !== 200 || !is_array($data) || !isset($data['access_token'])) {
            $error = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
            // Abgelehnter Refresh-Token: neu anmelden
            if ($fields['grant_type'] === 'refresh_token' && in_array($code, [400, 401], true)) {
                $this->ClearTokens();
                $this->SetStatus(self::STATUS_AUTH_FAILED);
            }
            return $this->Fail($error);
        }
        $this->WriteAttributeString('AccessToken', (string) $data['access_token']);
        // SmartThings gibt bei jeder Erneuerung einen neuen Refresh-Token aus
        if (isset($data['refresh_token']) && is_string($data['refresh_token'])) {
            $this->WriteAttributeString('RefreshToken', $data['refresh_token']);
        }
        $this->WriteAttributeInteger('Expires', time() + max(300, (int) ($data['expires_in'] ?? 86400)));
        $this->WriteAttributeString('LastError', '');
        $this->SendDebug('Token', 'renewed, valid for ' . (int) ($data['expires_in'] ?? 0) . ' s', 0);
        $this->SetStatus(102);
        return true;
    }

    private function Http(string $method, string $url, array $body, string $token): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers] + $this->CurlDefaults();
        if ($method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = (string) json_encode($body === [] ? new stdClass() : $body);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $options);
        $started = microtime(true);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->SendDebug($method, $url . ' → ' . ($raw === false ? 'error' : $code) . ' (' . round((microtime(true) - $started) * 1000) . ' ms)', 0);
        if ($raw === false) {
            $error = curl_error($ch);
            $this->WriteAttributeString('LastError', $error);
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => $error];
        }
        $data = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $error = 'HTTP ' . $code;
            if (is_array($data) && isset($data['error']['message'])) {
                $error .= ': ' . (string) $data['error']['message'];
            } elseif ($code === 429) {
                $error .= ': ' . $this->Translate('too many requests – increase the update interval');
            }
            $this->WriteAttributeString('LastError', $error);
            return ['Success' => false, 'Code' => $code, 'Data' => $data, 'Error' => $error];
        }
        return ['Success' => true, 'Code' => $code, 'Data' => $data, 'Error' => ''];
    }

    private function CurlDefaults(): array
    {
        $test = $this->ReadAttributeString('Endpoints') !== '';
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => $test ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL       => true,
        ];
    }

    /**
     * Adressen der Schnittstelle. Andere Adressen nur im Test und nur auf dem eigenen Rechner.
     */
    private function Endpoint(string $name): string
    {
        $defaults = ['api' => STH::API_URL, 'authorize' => STH::AUTHORIZE_URL, 'token' => STH::TOKEN_URL];
        $override = json_decode($this->ReadAttributeString('Endpoints'), true);
        if (is_array($override) && isset($override[$name]) && str_starts_with((string) $override[$name], 'http://127.0.0.1:')) {
            return (string) $override[$name];
        }
        return $defaults[$name];
    }

    private function RedirectURI(): string
    {
        $uri = trim($this->ReadPropertyString('RedirectURI'));
        return $uri !== '' ? $uri : 'https://httpbin.org/get';
    }

    private function ClearTokens(): void
    {
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('Expires', 0);
    }

    private function Fail(string $error): bool
    {
        $this->WriteAttributeString('LastError', $error);
        $this->SendDebug('Error', $error, 0);
        return false;
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
