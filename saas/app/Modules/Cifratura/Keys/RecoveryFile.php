<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use JsonException;
use RuntimeException;
use SensitiveParameter;
use SodiumException;

/**
 * Copia di recupero delle chiavi, chiusa con una frase segreta scelta da chi la crea.
 *
 * Formato (versione 1): «ASKR» · 0x01 · opslimit (uint32) · memlimit (uint32) · sale (16 byte) · nonce (24 byte) ·
 * testo cifrato. La chiave si ricava dalla frase con Argon2id (sodium_crypto_pwhash); il contenuto, un JSON con le
 * voci, si cifra con XChaCha20-Poly1305 e l'intestazione è dato autenticato: cambiarne un byte rende il file illeggibile.
 */
final class RecoveryFile
{
    public const MAGIC = 'ASKR';

    public const FORMAT = 1;

    /** Argon2id: 3 passaggi su 128 MiB. Sui server condivisi 256 MiB possono non esserci. */
    public const OPSLIMIT = 3;

    public const MEMLIMIT = 134_217_728;

    public const MIN_PASSPHRASE = 16;

    /** Limiti accettati in lettura: un file alterato non deve far consumare memoria a piacere. */
    private const MAX_OPSLIMIT = 10;

    private const MAX_MEMLIMIT = 1_073_741_824;

    /** Voci ammesse: chiavi e configurazione, mai altro. */
    public const ITEMS = [
        'saas_master_key',
        'service_url',
        'service_signing_key',
        'service_master_key',
        'service_client_public_key',
        'service_allowed_ips',
    ];

    /** @param  array<string, string>  $items */
    public static function seal(#[SensitiveParameter] array $items, #[SensitiveParameter] string $passphrase, int $opslimit = self::OPSLIMIT, int $memlimit = self::MEMLIMIT): string
    {
        self::checkPassphrase($passphrase);
        foreach (array_keys($items) as $name) {
            if (! in_array($name, self::ITEMS, true)) {
                throw new RuntimeException("Voce non ammessa nella copia di recupero: {$name}.");
            }
        }

        $payload = json_encode(['created_at' => gmdate('c'), 'items' => $items], JSON_THROW_ON_ERROR);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $header = self::MAGIC.chr(self::FORMAT).pack('NN', $opslimit, $memlimit).$salt.$nonce;

        $key = self::derive($passphrase, $salt, $opslimit, $memlimit);
        $sealed = $header.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($payload, $header, $nonce, $key);
        sodium_memzero($key);
        sodium_memzero($payload);

        return $sealed;
    }

    /**
     * @return array{created_at: string, items: array<string, string>}
     *
     * @throws RuntimeException frase sbagliata o file danneggiato, senza dire quale dei due
     */
    public static function open(string $data, #[SensitiveParameter] string $passphrase): array
    {
        $headerBytes = self::headerBytes();
        if (strlen($data) < $headerBytes + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES || ! self::looksLike($data)) {
            throw new RuntimeException('Il file non è una copia di recupero delle chiavi.');
        }
        if (ord($data[4]) !== self::FORMAT) {
            throw new RuntimeException('Versione della copia di recupero non riconosciuta.');
        }

        $limits = unpack('Nops/Nmem', substr($data, 5, 8));
        $opslimit = is_array($limits) && is_int($limits['ops'] ?? null) ? $limits['ops'] : 0;
        $memlimit = is_array($limits) && is_int($limits['mem'] ?? null) ? $limits['mem'] : 0;
        if ($opslimit < SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE || $opslimit > self::MAX_OPSLIMIT
            || $memlimit < SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE || $memlimit > self::MAX_MEMLIMIT) {
            throw new RuntimeException('Copia di recupero danneggiata.');
        }

        $header = substr($data, 0, $headerBytes);
        $salt = substr($data, 13, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = substr($data, 13 + SODIUM_CRYPTO_PWHASH_SALTBYTES, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $key = self::derive($passphrase, $salt, $opslimit, $memlimit);
        $payload = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($data, $headerBytes), $header, $nonce, $key);
        sodium_memzero($key);
        if ($payload === false) {
            throw new RuntimeException('Frase segreta sbagliata o copia di recupero danneggiata.');
        }

        try {
            $decoded = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
        sodium_memzero($payload);

        $items = [];
        $raw = is_array($decoded) && is_array($decoded['items'] ?? null) ? $decoded['items'] : null;
        foreach ($raw ?? [] as $name => $value) {
            if (is_string($name) && in_array($name, self::ITEMS, true) && is_string($value)) {
                $items[$name] = $value;
            }
        }
        $createdAt = is_array($decoded) && is_string($decoded['created_at'] ?? null) ? $decoded['created_at'] : null;
        if ($raw === null || $createdAt === null || count($items) !== count($raw)) {
            throw new RuntimeException('Copia di recupero danneggiata.');
        }

        return ['created_at' => $createdAt, 'items' => $items];
    }

    /** Solo l'intestazione: per riconoscere un file senza la frase. */
    public static function looksLike(string $head): bool
    {
        return str_starts_with($head, self::MAGIC);
    }

    public static function checkPassphrase(#[SensitiveParameter] string $passphrase): void
    {
        if (mb_strlen(trim($passphrase)) < self::MIN_PASSPHRASE) {
            throw new RuntimeException('La frase segreta deve avere almeno '.self::MIN_PASSPHRASE.' caratteri (meglio 5 o più parole).');
        }
    }

    private static function headerBytes(): int
    {
        return 4 + 1 + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    }

    private static function derive(#[SensitiveParameter] string $passphrase, string $salt, int $opslimit, int $memlimit): string
    {
        try {
            return sodium_crypto_pwhash(
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
                $passphrase,
                $salt,
                $opslimit,
                $memlimit,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
            );
        } catch (SodiumException) {
            throw new RuntimeException('Derivazione della chiave dalla frase non riuscita (memoria insufficiente?).');
        }
    }
}
