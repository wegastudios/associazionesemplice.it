<?php

declare(strict_types=1);

namespace KeyService;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Protocollo tra SaaS e servizio chiavi. Il corpo è JSON:
 *
 *   {"op": "wrap"|"unwrap", "org_id": "...", "version": 1, "key"|"wrapped": "<base64>", "ts": 1700000000, "nonce": "<hex>"}
 *
 * firmato con Ed25519 dal SaaS (intestazione X-AS-Signature, base64). Il servizio conosce solo la chiave pubblica:
 * chi lo compromettesse non potrebbe fingersi il SaaS verso un altro servizio.
 */
final class Protocol
{
    public const SIGNATURE_HEADER = 'X-AS-Signature';

    public const MAX_SKEW_SECONDS = 60;

    /** @param  array<string, int|string>  $payload */
    public static function body(array $payload, int $now): string
    {
        return json_encode($payload + ['ts' => $now, 'nonce' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
    }

    public static function sign(string $body, #[SensitiveParameter] string $secretKey): string
    {
        if ($secretKey === '') {
            throw new InvalidArgumentException('Chiave di firma mancante.');
        }

        return base64_encode(sodium_crypto_sign_detached($body, $secretKey));
    }

    public static function verify(string $body, string $signature, string $publicKey): bool
    {
        $raw = base64_decode($signature, true);

        return $raw !== false
            && $publicKey !== ''
            && strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($raw, $body, $publicKey);
    }
}
