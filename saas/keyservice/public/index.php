<?php

declare(strict_types=1);

/*
 * Servizio chiavi: punto d'ingresso. La cartella pubblica contiene solo questo file; configurazione, registro e
 * nonce stanno un livello sopra. Va su un account cPanel separato da quello del SaaS, solo in HTTPS.
 */

use KeyService\Config;
use KeyService\FileNonceStore;
use KeyService\KeyWrap;
use KeyService\Protocol;
use KeyService\Service;

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

$service = new Service(
    new KeyWrap($config->masterKey),
    $config->clientPublicKey,
    $config->allowedIps,
    new FileNonceStore($config->nonceDir),
    static function (string $line) use ($config): void {
        file_put_contents($config->logFile, gmdate('c').' '.$line."\n", FILE_APPEND | LOCK_EX);
    },
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
