<?php

declare(strict_types=1);

namespace App\Modules\Portale\Jwt;

use JsonException;
use SensitiveParameter;

/**
 * JWT firmati Ed25519 (alg EdDSA), lo stesso formato dell'attestazione delle licenze del portale
 * (portale/src/PortalAttestation.php): intestazione {typ: JWT, alg: EdDSA, kid}, base64url senza riempimento.
 *
 * In verifica si accetta solo EdDSA, solo un `kid` tra quelli fissati, solo il destinatario e il tipo attesi, e solo
 * token di vita breve (al massimo 10 minuti tra iat ed exp): chi intercetta un token non lo può riusare a lungo.
 */
final class EdDsaJwt
{
    public const MAX_LIFETIME = 600;

    public const LEEWAY = 60;

    /**
     * @param  array<string, mixed>  $claims
     * @param  string  $seed  32 byte
     */
    public static function sign(array $claims, #[SensitiveParameter] string $seed): string
    {
        if (strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new InvalidToken('Chiave di firma non valida.');
        }
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $head = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => self::kid(sodium_crypto_sign_publickey($pair))]));
        $body = self::b64(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $head.'.'.$body.'.'.self::b64(sodium_crypto_sign_detached($head.'.'.$body, sodium_crypto_sign_secretkey($pair)));
    }

    /**
     * @param  array<string, string>  $keys  kid => chiave pubblica in base64url (il campo `x` del JWKS)
     * @return array<string, mixed>
     */
    public static function verify(string $token, array $keys, string $issuer, string $audience, string $type, int $now): array
    {
        if (strlen($token) > 16384) {
            throw new InvalidToken('Token troppo lungo.');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidToken('Token malformato.');
        }
        [$head, $body, $signature] = $parts;

        $header = self::json($head);
        if (($header['alg'] ?? null) !== 'EdDSA' || ! is_string($header['kid'] ?? null)) {
            throw new InvalidToken('Algoritmo o chiave non ammessi.');
        }
        $public = isset($keys[$header['kid']]) ? self::unb64($keys[$header['kid']]) : '';
        $raw = self::unb64($signature);
        if (strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($raw, $head.'.'.$body, $public)) {
            throw new InvalidToken('Firma non valida.');
        }

        $claims = self::json($body);
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (($claims['iss'] ?? null) !== $issuer || ($claims['aud'] ?? null) !== $audience || ($claims['typ'] ?? null) !== $type) {
            throw new InvalidToken('Emittente, destinatario o tipo non validi.');
        }
        if (! is_int($iat) || ! is_int($exp) || $exp <= $iat || $exp - $iat > self::MAX_LIFETIME
            || $iat > $now + self::LEEWAY || $exp < $now - self::LEEWAY) {
            throw new InvalidToken('Token scaduto o con date non valide.');
        }

        return $claims;
    }

    /** kid di una chiave pubblica: come nel portale, prefisso e prime 8 cifre dell'impronta. */
    public static function kid(string $publicKey, string $prefix = 'portale-'): string
    {
        return $prefix.substr(hash('sha256', self::b64($publicKey)), 0, 8);
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $value): string
    {
        $raw = base64_decode(strtr($value, '-_', '+/'), true);

        return $raw === false ? '' : $raw;
    }

    /** @return array<string, mixed> */
    private static function json(string $segment): array
    {
        try {
            $decoded = json_decode(self::unb64($segment), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidToken('Token malformato.');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidToken('Token malformato.');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
