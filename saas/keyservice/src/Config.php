<?php

declare(strict_types=1);

namespace KeyService;

use RuntimeException;

/** Configurazione del servizio chiavi, da un file INI fuori dalla cartella pubblica. */
final readonly class Config
{
    /** Dimensione oltre la quale il registro passa al file successivo (5 MiB). */
    public const LOG_MAX_BYTES = 5242880;

    /** Quanti registri vecchi si tengono oltre a quello in corso. */
    public const LOG_KEEP = 3;

    /** Aperture al minuto che fanno scattare l'allarme. */
    public const UNWRAP_ALARM = 100;

    /** Aperture al minuto oltre le quali il servizio rifiuta (429). 0 = nessun limite. */
    public const UNWRAP_LIMIT = 200;

    /** @param  list<string>  $allowedIps */
    private function __construct(
        public string $masterKey,
        public string $clientPublicKey,
        public array $allowedIps,
        public string $logFile,
        public string $nonceDir,
        public int $logMaxBytes,
        public int $logKeep,
        public string $rateDir,
        public int $unwrapAlarm,
        public int $unwrapLimit,
        public string $alarmEmail,
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
        $number = static function (string $name, int $default, int $min) use ($ini): int {
            $value = $ini[$name] ?? null;
            if ($value === null || $value === '') {
                return $default;
            }

            return is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1 && (int) $value >= $min
                ? (int) $value
                : throw new RuntimeException("Configurazione non valida: {$name}.");
        };

        $email = $ini['alarm_email'] ?? '';
        if (! is_string($email) || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new RuntimeException('Configurazione non valida: alarm_email.');
        }

        return new self(
            $binary('master_key', KeyWrap::KEY_BYTES),
            $binary('client_public_key', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES),
            array_values(array_filter(array_map('trim', explode(',', $text('allowed_ips'))))),
            $text('log_file', $base.'/storage/keyservice.log'),
            $text('nonce_dir', $base.'/storage/nonces'),
            $number('log_max_bytes', self::LOG_MAX_BYTES, 1024),
            $number('log_keep', self::LOG_KEEP, 0),
            $text('rate_dir', $base.'/storage/rate'),
            $number('unwrap_alarm_per_minute', self::UNWRAP_ALARM, 0),
            $number('unwrap_limit_per_minute', self::UNWRAP_LIMIT, 0),
            $email,
        );
    }
}
