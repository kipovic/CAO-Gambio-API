<?php
return [
    // Basis-URL DEINES Shops (ohne /api.php)
    'baseUrl'    => 'https://dein-shop.tld/shop1',
    // 'v2' oder 'v3' – kann später pro Request überschrieben werden
    'apiVersion' => 'v2',

    // Basic-Auth (v2 & v3)
    'basicUser'  => getenv('GAMBIO_API_USER') ?: 'admin@example.org',
    'basicPass'  => getenv('GAMBIO_API_PASS') ?: '***',

    // JWT nur für v3 (optional)
    'jwt'        => getenv('GAMBIO_API_JWT') ?: null,

    // Logging
    'logFile'    => __DIR__ . '/../../logs/cao_api.log',

    // Optional: Zugriffsschutz für dieses CAO-Entry-Point
    // Wenn gesetzt, muss der Client den Token in Headern "X-CAO-Token" oder "X-Api-Key"
    // oder als Query-Parameter "token" mitsenden.
    'accessToken' => getenv('CAO_API_TOKEN') ?: null,

    // Optional: IP-Whitelist (z. B. ['203.0.113.10','203.0.113.11'])
    'allowedIps' => [],

    // Optional: Max. XML-Uploadgröße in Bytes (Default 2 MiB)
    'maxXmlBytes' => 2 * 1024 * 1024,
];
