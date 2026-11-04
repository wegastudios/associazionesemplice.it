<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Crypto;

use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\FieldCipher;
use App\Modules\Cifratura\Keys\KeyRing;
use SensitiveParameter;

/**
 * Formato: 0x01 · versione della chiave (4 byte) · nonce (24 byte) · testo cifrato XChaCha20-Poly1305.
 * Dato aggiuntivo autenticato: associazione, campo e versione.
 *
 * Le impronte di ricerca usano sempre la prima versione della chiave: una rotazione non le cambia (se si volesse
 * cambiare anche quella, serve ricalcolarle tutte).
 */
final readonly class SodiumFieldCipher implements FieldCipher
{
    private const FORMAT = "\x01";

    private const HEADER = 1 + 4 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    public function __construct(private KeyRing $keys) {}

    public function encrypt(string $orgId, string $field, #[SensitiveParameter] string $plain): string
    {
        $key = $this->keys->current($orgId);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return self::FORMAT.pack('N', $key->version).$nonce
            .sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, self::aad($orgId, $field, $key->version), $nonce, $key->fieldKey());
    }

    public function decrypt(string $orgId, string $field, string $cipher): string
    {
        if (strlen($cipher) < self::HEADER + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES || $cipher[0] !== self::FORMAT) {
            throw new DecryptionFailed('Dato cifrato non valido.');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($cipher, 1, 4));
        $version = $unpacked[1];
        $key = $this->keys->version($orgId, $version);

        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($cipher, self::HEADER),
            self::aad($orgId, $field, $version),
            substr($cipher, 5, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            $key->fieldKey(),
        );

        return $plain === false ? throw new DecryptionFailed('Dato cifrato non valido.') : $plain;
    }

    public function blindIndex(string $orgId, string $field, #[SensitiveParameter] string $normalized): string
    {
        $this->keys->current($orgId); // crea la chiave se l'associazione non ce l'ha ancora

        return sodium_crypto_generichash($field."\0".$normalized, $this->keys->version($orgId, 1)->indexKey(), 32);
    }

    private static function aad(string $orgId, string $field, int $version): string
    {
        return "as-field|{$orgId}|{$field}|{$version}";
    }
}
