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
 * Gemeinsame Konstanten und Hilfen der Bibliothek "SmartThings".
 */
class STH
{
    // Datenfluss zwischen Konto und Kind-Instanzen
    public const DATA_TO_KONTO = '{C221D232-BD8A-4398-A748-C938CC1312B3}';
    public const DATA_FROM_KONTO = '{3B83CD33-EAB3-432E-BD9E-1D540A1214D2}';

    // Module
    public const MODUL_KONTO = '{3EE55FDE-100A-4113-9E1A-F5B6C956BBB4}';
    public const MODUL_KONFIGURATOR = '{1945AAC8-A5B8-457A-82CA-AE24A6143B2E}';
    public const MODUL_GERAET = '{0E3FCD01-8B22-4987-88AE-26B272A7EA7D}';

    // SmartThings-Schnittstelle (offiziell)
    public const API_URL = 'https://api.smartthings.com/v1/';
    public const AUTHORIZE_URL = 'https://api.smartthings.com/oauth/authorize';
    public const TOKEN_URL = 'https://auth-global.api.smartthings.com/oauth/token';
    public const SCOPES = 'r:devices:* x:devices:* r:locations:*';

    // Erlaubte Endpunkte für Anfragen der Kind-Instanzen
    public const ENDPOINT_PATTERN = '#^(devices|locations|rooms)(/[A-Za-z0-9_-]+)*(\?[A-Za-z0-9_=&%.:-]*)?$#';

    /**
     * Baut die Anfrage, die eine Kind-Instanz an das Konto schickt.
     */
    public static function Request(string $Method, string $Endpoint, array $Body = []): string
    {
        return (string) json_encode([
            'DataID'   => self::DATA_TO_KONTO,
            'Method'   => $Method,
            'Endpoint' => $Endpoint,
            'Body'     => $Body,
        ]);
    }

    /**
     * Wertet die Antwort des Kontos aus: ['Success' => bool, 'Code' => int, 'Data' => mixed, 'Error' => string].
     */
    public static function Response(mixed $Raw): array
    {
        if (!is_string($Raw) || $Raw === '') {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'no answer from the account'];
        }
        $Result = json_decode($Raw, true);
        if (!is_array($Result)) {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'invalid answer from the account'];
        }
        return $Result + ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => ''];
    }

    /**
     * Ident aus Komponente und Attribut (nur Buchstaben, Ziffern, Unterstrich).
     */
    public static function Ident(string $Component, string $Key): string
    {
        $Ident = ($Component === 'main' ? '' : $Component . '_') . $Key;
        return (string) preg_replace('/[^A-Za-z0-9_]/', '_', $Ident);
    }
}
