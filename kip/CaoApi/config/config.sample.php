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
];
