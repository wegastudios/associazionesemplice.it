<?php

declare(strict_types=1);

/*
 * Servizio chiavi: punto d'ingresso. La cartella pubblica contiene solo questo file; configurazione, registro e
 * nonce stanno un livello sopra. Va su un account cPanel separato da quello del SaaS, solo in HTTPS.
 */

use KeyService\Config;
use KeyService\FileNonceStore;
use KeyService\FileRateCounter;
use KeyService\KeyWrap;
use KeyService\Protocol;
use KeyService\RotatingLog;
use KeyService\Service;

// Mai errori a schermo: avvisi e percorsi non devono finire nella risposta.
ini_set('display_errors', '0');
ini_set('html_errors', '0');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KeyService\\')) {
        require dirname(__DIR__).'/src/'.substr($class, strlen('KeyService\\')).'.php';
    }
});

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');

$base = dirname(__DIR__);
$configFile = getenv('KEYSERVICE_CONFIG');

try {
    $config = Config::load(is_string($configFile) && $configFile !== '' ? $configFile : $base.'/config.ini', $base);
} catch (Throwable) {
    http_response_code(500);
    echo '{"error":"configurazione mancante"}';

    return;
}

try {
    $log = new RotatingLog($config->logFile, $config->logMaxBytes, $config->logKeep);

    $service = new Service(
        new KeyWrap($config->masterKey),
        $config->clientPublicKey,
        $config->allowedIps,
        new FileNonceStore($config->nonceDir),
        static function (string $line) use ($log): void {
            $log->write($line);
        },
        new FileRateCounter($config->rateDir),
        $config->unwrapAlarm,
        $config->unwrapLimit,
        static function (string $message) use ($config): void {
            if ($config->alarmEmail !== '') {
                @mail($config->alarmEmail, 'Servizio chiavi: allarme', $message);
            }
        },
        $config->batchAlarm,
        $config->batchLimit,
        $config->totalAlarm,
        $config->totalLimit,
    );

    $server = static fn (string $key): ?string => isset($_SERVER[$key]) && is_string($_SERVER[$key]) ? $_SERVER[$key] : null;
    $body = file_get_contents('php://input', length: 8192);

    $result = $service->handle(
        $server('REQUEST_METHOD') ?? 'GET',
        $body === false ? '' : $body,
        $server('HTTP_'.strtoupper(str_replace('-', '_', Protocol::SIGNATURE_HEADER))),
        $server('REMOTE_ADDR') ?? '',
        time(),
    );

    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // Errore fuori dal servizio (file mancanti, memoria, ...): risposta generica, mai percorsi né messaggi.
    http_response_code(500);
    echo '{"error":"errore interno"}';
}
