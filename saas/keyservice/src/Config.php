<?php

declare(strict_types=1);

namespace KeyService;

use RuntimeException;

/** Configurazione del servizio chiavi, da un file INI fuori dalla cartella pubblica. */
final readonly class Config
{
    /** @param  list<string>  $allowedIps */
    private function __construct(
        public string $masterKey,
        public string $clientPublicKey,
        public array $allowedIps,
        public string $logFile,
        public string $nonceDir,
    ) {}

    public static function load(string $file, string $base): self
    {
        $ini = is_readable($file) ? parse_ini_file($file) : false;
        if (! is_array($ini)) {
            throw new RuntimeException('Configurazione mancante.');
        }

        $text = static function (string $name, ?string $default = null) use ($ini): string {
            $value = $ini[$name] ?? $default;

            return is_string($value) && $value !== '' ? $value : throw new RuntimeException("Configurazione mancante: {$name}.");
        };
        $binary = static function (string $name, int $bytes) use ($text): string {
            $raw = base64_decode($text($name), true);

            return $raw !== false && strlen($raw) === $bytes ? $raw : throw new RuntimeException("Configurazione non valida: {$name}.");
        };

        return new self(
            $binary('master_key', KeyWrap::KEY_BYTES),
            $binary('client_public_key', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES),
            array_values(array_filter(array_map('trim', explode(',', $text('allowed_ips'))))),
            $text('log_file', $base.'/storage/keyservice.log'),
            $text('nonce_dir', $base.'/storage/nonces'),
        );
    }
}
