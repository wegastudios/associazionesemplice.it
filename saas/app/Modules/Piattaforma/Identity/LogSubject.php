<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use RuntimeException;

/**
 * Cosa si scrive nei registri di sicurezza al posto di un testo libero arrivato da un accesso fallito: il campo email
 * lo compila chiunque, e a volte vi finisce una password digitata per errore. L'indirizzo si salva solo se ha la forma
 * di un indirizzo; in ogni altro caso resta una sigla (HMAC con una chiave derivata da quella dell'applicazione) che
 * permette di riconoscere ripetizioni senza conservare il testo.
 */
final class LogSubject
{
    public static function email(string $text): string
    {
        $text = mb_strtolower(trim($text));
        if (strlen($text) <= 254 && filter_var($text, FILTER_VALIDATE_EMAIL) !== false) {
            return $text;
        }

        return 'impronta:'.substr(hash_hmac('sha256', $text, self::key()), 0, 32);
    }

    private static function key(): string
    {
        $key = config('app.key');
        $raw = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Chiave dell\'applicazione mancante.');
        }

        return hash_hmac('sha256', 'saas-log-subject-v1', $raw, true);
    }
}
