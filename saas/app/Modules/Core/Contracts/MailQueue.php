<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Posta in coda: due code e i tetti di invio, contati per mailer (il servizio che manda davvero la posta).
 *
 * - mail-urgent: messaggi che qualcuno sta aspettando (link di conferma); il lavoratore li prende per primi e contano
 *   solo sul tetto totale.
 * - mail-bulk: avvisi e promemoria; il lavoratore li prende per ultimi e contano anche su un tetto proprio, più basso,
 *   così una parte dell'ora resta sempre agli urgenti.
 *
 * Tetti in config saas.mail_queue.mailers.<mailer>: per_second, per_minute, per_hour, per_day, per_month (30 giorni) per
 * il totale, gli stessi con «bulk_» davanti per i massivi. Un mailer senza voce propria usa i numeri di «smtp», contati a
 * parte. Lavori in coda: ThrottledMail come middleware e onQueue(URGENT o BULK).
 */
final class MailQueue
{
    public const URGENT = 'mail-urgent';

    public const BULK = 'mail-bulk';

    /** Ordine di lavorazione: prima gli urgenti, poi gli altri lavori, per ultimi i massivi. */
    public const WORKER_QUEUES = self::URGENT.',default,'.self::BULK;

    /** Nomi dei limitatori (RateLimiter::for) dei due tipi di posta. */
    public const LIMIT_ALL = 'mail-all';

    public const LIMIT_BULK = 'mail-bulk';

    /** Fino a quando si riprovano i massivi (gli urgenti: fino alla scadenza del loro link o codice). */
    public const BULK_RETRY_HOURS = 48;

    private const PERIODS = ['second' => 1, 'minute' => 60, 'hour' => 3600, 'day' => 86400, 'month' => 2592000];

    /** Il mailer in uso: i tetti e l'interruttore valgono per lui solo. */
    public static function mailer(): string
    {
        $mailer = config('mail.default');

        return is_string($mailer) && $mailer !== '' ? $mailer : 'smtp';
    }

    /**
     * Tetti di una coda per il mailer (quello in uso se non indicato), dal periodo più corto al più lungo; per i massivi
     * prima i loro, poi il totale.
     *
     * @return list<Limit>
     */
    public static function limits(string $queue, ?string $mailer = null): array
    {
        $mailer ??= self::mailer();
        $mailers = config('saas.mail_queue.mailers');
        $mailers = is_array($mailers) ? $mailers : [];
        $values = $mailers[$mailer] ?? $mailers['smtp'] ?? [];
        $values = is_array($values) ? $values : [];

        $limits = [];
        foreach ($queue === self::BULK ? ['bulk_' => self::LIMIT_BULK, '' => self::LIMIT_ALL] : ['' => self::LIMIT_ALL] as $prefix => $name) {
            foreach (self::PERIODS as $period => $seconds) {
                $max = $values[$prefix.'per_'.$period] ?? null;
                if (is_numeric($max) && (int) $max > 0) {
                    $limits[] = new Limit("{$name}|{$mailer}|{$seconds}", (int) $max, $seconds);
                }
            }
        }

        return $limits;
    }

    /**
     * Invii contati nell'ora in corso sul tetto orario totale del mailer, con il tetto; null senza tetto orario.
     *
     * @return array{int, int}|null
     */
    public static function hourUsage(?string $mailer = null): ?array
    {
        foreach (self::limits(self::URGENT, $mailer) as $limit) {
            if ($limit->decaySeconds === 3600) {
                $sent = RateLimiter::attempts($limit->key);

                return [is_numeric($sent) ? (int) $sent : 0, $limit->maxAttempts];
            }
        }

        return null;
    }

    /** Fino a quando (timestamp) la posta del mailer è ferma dopo un rifiuto del server; null se non è ferma. */
    public static function blockedUntil(?string $mailer = null): ?int
    {
        $until = Cache::get(self::blockKey($mailer ?? self::mailer()));

        return is_int($until) && $until > now()->getTimestamp() ? $until : null;
    }

    /** Ferma la posta del mailer (saas.mail_queue.blocked_minutes, di base un'ora) e dice fino a quando. */
    public static function block(string $mailer): int
    {
        $until = now()->addMinutes(self::blockedMinutes());
        Cache::put(self::blockKey($mailer), $until->getTimestamp(), $until);

        return $until->getTimestamp();
    }

    public static function blockedMinutes(): int
    {
        $minutes = config('saas.mail_queue.blocked_minutes');

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : 60;
    }

    private static function blockKey(string $mailer): string
    {
        return 'smtp-blocked-until:'.$mailer;
    }
}
