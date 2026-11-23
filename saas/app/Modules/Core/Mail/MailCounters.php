<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contatori della posta in core.mail_counters, per giorno e ora UTC e per mailer: inviati (un invio per destinatario),
 * errori del servizio e invii fatti in ripiego. Un errore del database non ferma mai l'invio: si annota e basta.
 */
final class MailCounters
{
    public static function add(string $provider, int $sent = 0, int $errors = 0, int $fallbacks = 0): void
    {
        $now = now('UTC');
        try {
            // Dentro una transazione (punto di salvataggio se ce n'è già una): un errore qui non rovina quella di chi chiama.
            DB::transaction(static fn (): bool => DB::statement(<<<'SQL'
                INSERT INTO core.mail_counters (day, hour, provider, sent, errors, fallbacks) VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT (day, hour, provider) DO UPDATE SET
                    sent = core.mail_counters.sent + EXCLUDED.sent,
                    errors = core.mail_counters.errors + EXCLUDED.errors,
                    fallbacks = core.mail_counters.fallbacks + EXCLUDED.fallbacks
            SQL, [$now->toDateString(), $now->hour, $provider, $sent, $errors, $fallbacks]));
        } catch (Throwable $e) {
            Log::warning('Contatori della posta non salvati', ['mailer' => $provider, 'error' => $e::class]);
        }
    }

    /** Invii del mailer nel giorno UTC di oggi; 0 se il database non risponde. */
    public static function sentToday(string $provider): int
    {
        try {
            $sent = DB::transaction(static fn (): mixed => DB::table('core.mail_counters')->where('day', now('UTC')->toDateString())->where('provider', $provider)->sum('sent'));
        } catch (Throwable) {
            return 0;
        }

        return is_numeric($sent) ? (int) $sent : 0;
    }
}
