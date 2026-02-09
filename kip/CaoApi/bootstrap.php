<?php
declare(strict_types=1);

// Fehler-Reporting für Entwicklung
// error_reporting(E_ALL); ini_set('display_errors', '1');

$baseDir = __DIR__;
$configFile = __DIR__ . '/config/config.php';
if (!is_file($configFile)) {
    $configFile = __DIR__ . '/config/config.sample.php';
}
if (!is_file($configFile)) {
    throw new RuntimeException('Missing config file. Provide config/config.php.');
}
$config = require $configFile;

$helpersFile = __DIR__ . '/src/CaoXmlHelpers.php';
if (is_file($helpersFile)) {
    require_once $helpersFile;
}

// Mini-Autoloader
spl_autoload_register(function ($class) use ($baseDir) {
    // Erlaubt z. B. "CaoApi\GambioApiClient" ODER einfache Klassennamen
    $class = ltrim($class, '\\');
    $paths = [
        $baseDir . '/src/' . basename(str_replace('\\', '/', $class)) . '.php',
        $baseDir . '/src/' . str_replace('\\', '/', $class) . '.php',
    ];
    foreach ($paths as $p) {
        if (is_file($p)) { require_once $p; return; }
    }
});

// Hilfsfunktion: Logging
function cao_api_log(string $message, ?string $logFile = null): void {
    // 1) Default-Pfad, wenn keiner übergeben wurde
    if (!$logFile) {
        // __DIR__ zeigt hier auf .../GXModules/kip/CaoApi
        $logFile = __DIR__ . '/logs/cao-api.log';
    }

    $dir = dirname($logFile);

    // 2) Ordner existiert nicht? -> anlegen (rekursiv)
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

    // 3) Schreiben, wenn möglich
    if (is_dir($dir) && is_writable($dir)) {
        if (@file_put_contents($logFile, $line, FILE_APPEND) === false) {
            // Fallback + Hinweis
            @error_log('[CAO-API][LOG WRITE FAIL to ' . $logFile . '] ' . $line);
        }
        return;
    }

    // 4) Fallback in system error_log + Grund
    @error_log('[CAO-API][LOG DIR NOT WRITABLE ' . $dir . '] ' . $line);
}


return $config;
