<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contatori della posta in core.mail_counters, per giorno e ora UTC e per mailer: inviati (un invio per destinatario),
 * di cui massivi, errori del servizio e invii fatti in ripiego. Un errore del database non ferma mai l'invio: si annota e
 * basta.
 */
final class MailCounters
{
    public static function add(string $provider, int $sent = 0, int $bulk = 0, int $errors = 0, int $fallbacks = 0): void
    {
        $now = now('UTC');
        try {
            $write = static fn (): bool => DB::statement(<<<'SQL'
                INSERT INTO core.mail_counters (day, hour, provider, sent, bulk_sent, errors, fallbacks) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (day, hour, provider) DO UPDATE SET
                    sent = core.mail_counters.sent + EXCLUDED.sent,
                    bulk_sent = core.mail_counters.bulk_sent + EXCLUDED.bulk_sent,
                    errors = core.mail_counters.errors + EXCLUDED.errors,
                    fallbacks = core.mail_counters.fallbacks + EXCLUDED.fallbacks
            SQL, [$now->toDateString(), $now->hour, $provider, $sent, $bulk, $errors, $fallbacks]);
            // Dentro la transazione di chi chiama, un punto di salvataggio: un errore qui non la rovina.
            if (DB::transactionLevel() > 0) {
                DB::transaction($write);
            } else {
                $write();
            }
        } catch (Throwable $e) {
            Log::warning('Contatori della posta non salvati', ['mailer' => $provider, 'error' => $e::class]);
        }
    }

    /**
     * Invii del mailer nel giorno UTC di oggi: totale e massivi; zero se il database non risponde.
     *
     * @return array{int, int}
     */
    public static function sentToday(string $provider): array
    {
        try {
            $read = static fn (): mixed => DB::table('core.mail_counters')->where('day', now('UTC')->toDateString())->where('provider', $provider)
                ->selectRaw('coalesce(sum(sent), 0) AS sent, coalesce(sum(bulk_sent), 0) AS bulk')->first();
            $row = DB::transactionLevel() > 0 ? DB::transaction($read) : $read();
        } catch (Throwable) {
            return [0, 0];
        }
        $sent = is_object($row) ? ($row->sent ?? 0) : 0;
        $bulk = is_object($row) ? ($row->bulk ?? 0) : 0;

        return [is_numeric($sent) ? (int) $sent : 0, is_numeric($bulk) ? (int) $bulk : 0];
    }
}
