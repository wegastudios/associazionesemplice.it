<?php

declare(strict_types=1);

namespace KeyService;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Cifratura delle chiavi dati delle associazioni con la chiave principale (XChaCha20-Poly1305).
 *
 * Formato: 0x01 · nonce (24 byte) · testo cifrato. Il dato aggiuntivo autenticato lega la chiave alla sua
 * associazione e alla sua versione: una chiave copiata su un'altra associazione non si apre.
 *
 * È l'unica implementazione: la usano il servizio chiavi e, come ripiego, il SaaS (LocalKeyVault).
 */
final class KeyWrap
{
    private const FORMAT = "\x01";

    public const KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    public function __construct(#[SensitiveParameter] private readonly string $masterKey)
    {
        if (strlen($masterKey) !== self::KEY_BYTES) {
            throw new InvalidArgumentException('Chiave principale non valida.');
        }
    }

    public function wrap(string $orgId, int $version, #[SensitiveParameter] string $dataKey): string
    {
        if (strlen($dataKey) !== self::KEY_BYTES) {
            throw new InvalidArgumentException('Chiave dati non valida.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return self::FORMAT.$nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dataKey, self::aad($orgId, $version), $nonce, $this->masterKey);
    }

    public function unwrap(string $orgId, int $version, string $wrapped): string
    {
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($wrapped) < 1 + $nonceBytes + 16 || $wrapped[0] !== self::FORMAT) {
            throw new RuntimeException('Chiave cifrata non valida.');
        }

        $key = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($wrapped, 1 + $nonceBytes),
            self::aad($orgId, $version),
            substr($wrapped, 1, $nonceBytes),
            $this->masterKey,
        );
        if ($key === false) {
            throw new RuntimeException('Chiave cifrata non valida.');
        }

        return $key;
    }

    private static function aad(string $orgId, int $version): string
    {
        return "as-orgkey|{$orgId}|{$version}";
    }
}
