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
 * Formato 2: "ASBK" · 0x02 · lunghezza dell'intestazione (2 byte) · intestazione JSON (database, ora, ambiente) ·
 * chiave del file sigillata con la chiave pubblica (crypto_box_seal, 80 byte) · intestazione secretstream (24 byte) ·
 * blocchi da 64 KiB (XChaCha20-Poly1305). L'intestazione JSON è dato associato di ogni blocco: se qualcuno la cambia
 * i blocchi non si aprono. Un file troncato non si apre.
 *
 * Formato 1 (0x01, senza intestazione): ancora letto, ma non dice di che database e di che ora è.
 */
final class BackupCipher
{
    private const MAGIC = "ASBK\x02";

    private const MAGIC_V1 = "ASBK\x01";

    private const CHUNK = 65536;

    private const SEALED = 32 + SODIUM_CRYPTO_BOX_SEALBYTES;

    private const MAX_META = 1024;

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  array<string, string>  $meta  database, ora e ambiente del dump: entrano nel dato autenticato
     */
    public static function encrypt($in, $out, string $publicKey, array $meta = []): void
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Chiave pubblica dei backup non valida.');
        }
        ksort($meta);
        $json = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_META) {
            throw new InvalidArgumentException('Intestazione del backup troppo lunga.');
        }
        $fileKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);
        if (! is_string($state) || ! is_string($header)) {
            throw new RuntimeException('Cifratura del backup non avviata.');
        }

        fwrite($out, self::MAGIC.pack('n', strlen($json)).$json.sodium_crypto_box_seal($fileKey, $publicKey).$header);
        $ad = self::MAGIC.$json;

        $chunk = (string) fread($in, self::CHUNK);
        while (true) {
            $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
            $last = $next === '' && feof($in);
            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $chunk,
                $ad,
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
     * @return array{legacy: bool, meta: array<string, string>} legacy = formato 1, senza intestazione autenticata
     */
    public static function decrypt($in, $out, #[SensitiveParameter] string $keyPair): array
    {
        if (strlen($keyPair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
            throw new InvalidArgumentException('Chiave privata dei backup non valida.');
        }
        $magic = (string) fread($in, strlen(self::MAGIC));
        $legacy = $magic === self::MAGIC_V1;
        if (! $legacy && $magic !== self::MAGIC) {
            throw new RuntimeException('Backup non valido o di un\'altra chiave.');
        }

        $json = '';
        if (! $legacy) {
            $length = unpack('n', str_pad((string) fread($in, 2), 2, "\0"));
            $size = is_array($length) && is_int($length[1] ?? null) ? $length[1] : self::MAX_META + 1;
            $json = $size > self::MAX_META || $size === 0 ? '' : (string) fread($in, $size);
            if ($size > self::MAX_META || strlen($json) !== $size) {
                throw new RuntimeException('Backup non valido o di un\'altra chiave.');
            }
        }
        $ad = $legacy ? self::MAGIC_V1 : $magic.$json;

        $sealed = (string) fread($in, self::SEALED);
        $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $fileKey = sodium_crypto_box_seal_open($sealed, $keyPair);
        if ($fileKey === false || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('Backup non valido o di un\'altra chiave.');
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $fileKey);
        do {
            $block = (string) fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            $result = $block === '' ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block, $ad);
            if ($result === false || ! is_string($result[0])) {
                throw new RuntimeException('Backup alterato o incompleto.');
            }
            fwrite($out, $result[0]);
        } while ($result[1] !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);

        return ['legacy' => $legacy, 'meta' => $legacy ? [] : self::meta($json)];
    }

    /** @return array<string, string> */
    private static function meta(string $json): array
    {
        $data = json_decode($json, true, 4);
        $meta = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }
}
