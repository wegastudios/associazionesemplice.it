<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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
 * parte. Lavori in coda: onQueue(URGENT o BULK) e ThrottledMail come middleware (i tetti seguono la coda del lavoro).
 *
 * Ogni messaggio partito si conta, un invio per destinatario (sent(), dall'evento MessageSent): anche quelli mandati
 * fuori dalla coda. Chi aspetta un codice o un link passa da sendNowOrQueue (QueuedMail per un messaggio già pronto).
 */
final class MailQueue
{
    public const URGENT = 'mail-urgent';

    public const BULK = 'mail-bulk';

    /** Ordine di lavorazione: prima gli urgenti, poi gli altri lavori, per ultimi i massivi. */
    public const WORKER_QUEUES = self::URGENT.',default,'.self::BULK;

    /** Fino a quando si riprovano i massivi (gli urgenti: fino alla scadenza del loro link o codice). */
    public const BULK_RETRY_HOURS = 48;

    /** Con la posta ferma, ogni quanto un messaggio urgente prova se il server ha ripreso (secondi). */
    public const PROBE_SECONDS = 600;

    private const PERIODS = ['second' => 1, 'minute' => 60, 'hour' => 3600, 'day' => 86400, 'month' => 2592000];

    /** Destinatari già contati da una prenotazione (reserve) per l'invio in corso: il conteggio dei partiti li salta. */
    private static int $reserved = 0;

    /** La coda dell'invio in corso: gli invii fuori da un lavoro di posta contano come urgenti (solo sul totale). */
    private static string $current = self::URGENT;

    /**
     * Manda subito un lavoro di posta se la posta non è ferma e c'è quota (per non far aspettare il lavoratore a chi
     * aspetta un codice), altrimenti lo mette in coda. Al rifiuto del server il messaggio va in coda e, salvo un problema
     * del solo destinatario, la posta del mailer si ferma come per i lavori in coda. $now sostituisce l'invio del lavoro
     * quando chi chiama ha già pronto il messaggio. true = partito subito, false = in coda.
     *
     * @param  (Closure(): mixed)|null  $now
     */
    public static function sendNowOrQueue(object $job, ?Closure $now = null): bool
    {
        $queue = property_exists($job, 'queue') && is_string($job->queue) ? $job->queue : self::URGENT;
        $mailer = self::mailer();
        if (self::reserve($queue, $mailer)) {
            try {
                self::reserved($queue, $now ?? static fn (): mixed => method_exists($job, 'handle') ? app()->call([$job, 'handle']) : throw new LogicException('Il lavoro di posta non ha handle().'));

                return true;
            } catch (TransportExceptionInterface $e) {
                if (ThrottledMail::recipientOnly($e) === null) {
                    $until = self::block($mailer);
                    Log::warning('Server di posta: invio rifiutato, posta in coda ferma', ['mailer' => $mailer, 'code' => $e->getCode(), 'until' => date(DATE_ATOM, $until)]);
                }
            }
        }
        Bus::dispatch($job);

        return false;
    }

    /**
     * Prenota un invio sui tetti della coda (un messaggio a un destinatario): false se la posta è ferma o un tetto è pieno.
     * Dopo la prenotazione l'invio va fatto dentro reserved(), così il destinatario prenotato non si conta due volte.
     */
    public static function reserve(string $queue, ?string $mailer = null): bool
    {
        $mailer ??= self::mailer();
        if (self::blockedUntil($mailer) !== null) {
            return false;
        }
        $limits = self::limits($queue, $mailer);
        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts(self::key($limit), $limit->maxAttempts)) {
                return false;
            }
        }
        foreach ($limits as $limit) {
            RateLimiter::hit(self::key($limit), $limit->decaySeconds);
        }

        return true;
    }

    /**
     * Esegue un invio già prenotato (un destinatario contato): i destinatari in più si contano quando il messaggio parte.
     *
     * @template T
     *
     * @param  Closure(): T  $send
     * @return T
     */
    public static function reserved(string $queue, Closure $send): mixed
    {
        [$reserved, $current] = [self::$reserved, self::$current];
        [self::$reserved, self::$current] = [1, $queue];
        try {
            return $send();
        } finally {
            [self::$reserved, self::$current] = [$reserved, $current];
        }
    }

    /**
     * Un messaggio partito (evento MessageSent): conta un invio per destinatario sui tetti del mailer che l'ha mandato,
     * tolti quelli già prenotati. Così consumano la quota anche gli invii diretti e i messaggi a più destinatari.
     */
    public static function sent(int $recipients, ?string $mailer = null): void
    {
        $prepaid = min(self::$reserved, $recipients);
        self::$reserved -= $prepaid;
        $count = $recipients - $prepaid;
        if ($count <= 0) {
            return;
        }
        foreach (self::limits(self::$current, $mailer) as $limit) {
            RateLimiter::increment(self::key($limit), $limit->decaySeconds, $count);
        }
    }

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
        foreach ($queue === self::BULK ? ['bulk_' => 'mail-bulk', '' => 'mail-all'] : ['' => 'mail-all'] as $prefix => $name) {
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
                $sent = RateLimiter::attempts(self::key($limit));

                return [is_numeric($sent) ? (int) $sent : 0, $limit->maxAttempts];
            }
        }

        return null;
    }

    /** Fino a quando (timestamp) la posta del mailer è ferma dopo un rifiuto del server; null se non è ferma. */
    public static function blockedUntil(?string $mailer = null): ?int
    {
        // Redis restituisce i numeri come stringhe.
        $until = Cache::get(self::blockKey($mailer ?? self::mailer()));

        return is_numeric($until) && (int) $until > now()->getTimestamp() ? (int) $until : null;
    }

    /** Ferma la posta del mailer (saas.mail_queue.blocked_minutes, di base un'ora) e dice fino a quando. */
    public static function block(string $mailer): int
    {
        $until = now()->addMinutes(self::blockedMinutes());
        Cache::put(self::blockKey($mailer), $until->getTimestamp(), $until);

        return $until->getTimestamp();
    }

    /**
     * Con la posta ferma, prenota la prova di un messaggio urgente: una sola ogni PROBE_SECONDS per mailer, anche con più
     * lavoratori (aggiunta atomica in cache). true = questo lavoro fa la prova.
     */
    public static function startProbe(string $mailer): bool
    {
        return Cache::add('smtp-probe:'.$mailer, now()->getTimestamp(), self::PROBE_SECONDS);
    }

    /** Riapre la posta del mailer (prova riuscita). */
    public static function unblock(string $mailer): void
    {
        Cache::forget(self::blockKey($mailer));
    }

    public static function blockedMinutes(): int
    {
        $minutes = config('saas.mail_queue.blocked_minutes');

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : 60;
    }

    /** La chiave di un tetto come testo (Limit::$key non ha tipo). */
    public static function key(Limit $limit): string
    {
        return is_scalar($limit->key) ? (string) $limit->key : '';
    }

    private static function blockKey(string $mailer): string
    {
        return 'smtp-blocked-until:'.$mailer;
    }
}
