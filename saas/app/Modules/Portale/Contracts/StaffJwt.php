<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\Jwt\InvalidToken;

/**
 * Firma e verifica dei token dello staff (JWT EdDSA) per gli altri moduli: la sola porta verso il codice dei token del
 * portale. In verifica ogni difetto diventa `StaffAccessDenied`.
 */
final class StaffJwt
{
    /**
     * @param  array<string, mixed>  $claims
     * @param  string  $seed  32 byte
     */
    public static function sign(array $claims, string $seed, string $kidPrefix): string
    {
        return EdDsaJwt::sign($claims, $seed, $kidPrefix);
    }

    /**
     * @param  array<string, string>  $keys  kid => chiave pubblica base64url
     * @return array<string, mixed>
     *
     * @throws StaffAccessDenied
     */
    public static function verify(string $token, array $keys, string $issuer, string $audience, string $type, int $now): array
    {
        try {
            return EdDsaJwt::verify($token, $keys, $issuer, $audience, $type, $now);
        } catch (InvalidToken) {
            throw new StaffAccessDenied('token non valido');
        }
    }

    /** Il `kid` dell'intestazione, senza verificare nulla. */
    public static function kidOf(string $token): ?string
    {
        $decoded = json_decode(EdDsaJwt::unb64(explode('.', $token)[0]), true);
        $kid = is_array($decoded) ? ($decoded['kid'] ?? null) : null;

        return is_string($kid) ? $kid : null;
    }

    /** Lunghezza in byte di una chiave pubblica base64url (per controllare quelle configurate). */
    public static function publicKeyLength(string $x): int
    {
        return strlen(EdDsaJwt::unb64($x));
    }
}
