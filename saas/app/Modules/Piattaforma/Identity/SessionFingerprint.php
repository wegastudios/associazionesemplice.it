<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use RuntimeException;

/**
 * Impronta con chiave del server degli identificativi di sessione dello staff: nel database si conserva solo questa.
 * Due usi, con due etichette diverse: l'identificativo vero della sessione e i riferimenti per area.
 */
final class SessionFingerprint
{
    /** Impronta dell'identificativo vero della sessione (32 esadecimali minuscoli). */
    public static function ofSession(string $sid): string
    {
        return self::hash('saas-staff-sid-v1', $sid);
    }

    /** Impronta del riferimento per area. */
    public static function ofAreaRef(string $ref): string
    {
        return self::hash('saas-staff-area-ref-v1', $ref);
    }

    public static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1;
    }

    private static function hash(string $label, string $value): string
    {
        $key = config('app.key');
        $raw = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Chiave dell\'applicazione mancante.');
        }

        return substr(hash_hmac('sha256', $value, hash_hmac('sha256', $label, $raw, true)), 0, 32);
    }
}
