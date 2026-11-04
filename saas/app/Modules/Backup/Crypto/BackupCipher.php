<?php

declare(strict_types=1);

namespace App\Modules\Backup\Crypto;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Cifratura dei dump con una **chiave pubblica** (X25519): il server cifra ma non può decifrare. La chiave privata sta
 * solo dove si ripristina (staging o il computer di chi gestisce), mai sul server di produzione.
 *
 * Formato: "ASBK" · 0x01 · chiave del file sigillata con la chiave pubblica (crypto_box_seal, 80 byte) ·
 * intestazione secretstream (24 byte) · blocchi da 64 KiB (XChaCha20-Poly1305). Un file troncato non si apre.
 */
final class BackupCipher
{
    private const MAGIC = "ASBK\x01";

    private const CHUNK = 65536;

    private const SEALED = 32 + SODIUM_CRYPTO_BOX_SEALBYTES;

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public static function encrypt($in, $out, string $publicKey): void
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Chiave pubblica dei backup non valida.');
        }
        $fileKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);
        if (! is_string($state) || ! is_string($header)) {
            throw new RuntimeException('Cifratura del backup non avviata.');
        }

        fwrite($out, self::MAGIC.sodium_crypto_box_seal($fileKey, $publicKey).$header);

        $chunk = (string) fread($in, self::CHUNK);
        while (true) {
            $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
            $last = $next === '' && feof($in);
            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $chunk,
                self::MAGIC,
                $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
            ));
            if ($last) {
                return;
            }
            $chunk = $next;
        }
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  string  $keyPair  coppia X25519 (chiave privata + pubblica)
     */
    public static function decrypt($in, $out, #[SensitiveParameter] string $keyPair): void
    {
        if (strlen($keyPair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
            throw new InvalidArgumentException('Chiave privata dei backup non valida.');
        }
        $magic = (string) fread($in, strlen(self::MAGIC));
        $sealed = (string) fread($in, self::SEALED);
        $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $fileKey = $magic === self::MAGIC ? sodium_crypto_box_seal_open($sealed, $keyPair) : false;
        if ($fileKey === false || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('Backup non valido o di un\'altra chiave.');
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $fileKey);
        do {
            $block = (string) fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            $result = $block === '' ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block, self::MAGIC);
            if ($result === false || ! is_string($result[0])) {
                throw new RuntimeException('Backup alterato o incompleto.');
            }
            fwrite($out, $result[0]);
        } while ($result[1] !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
    }
}
