<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Code dei lavori per tipo e lavoratori lanciati dal cron (sul server non ci sono processi sempre accesi).
 *
 * - Lavori brevi (posta, avvisi): code mail-urgent, default e mail-bulk (MailQueue); il lavoratore li ferma a
 *   SHORT_TIMEOUT e la connessione «database» li riprende dopo 90 s se il lavoratore muore.
 * - Lavori lunghi (copia completa, import): coda «long», scelta dal lavoro con onQueue(LONG). La prende solo il
 *   lavoratore sulla connessione «database-long» (stessa tabella), che riprende un lavoro solo dopo un tempo più lungo
 *   del lavoro più lungo (LONG_TIMEOUT): un lavoro che gira non parte una seconda volta.
 *
 * Lavoratori, al massimo saas.queue.max_workers (1-3; il server ha 25 processi PHP in tutto, condivisi con il web):
 * 1 = uno solo per tutto; 2 = brevi + lunghi; 3 = in più uno per la sola posta urgente. Quello dei lunghi parte solo se
 * nella sua coda c'è qualcosa (`only_if_queued`).
 */
final class JobQueues
{
    public const DEFAULT = 'default';

    public const LONG = 'long';

    public const LONG_CONNECTION = 'database-long';

    /** Secondi massimi di un lavoro breve: sotto il retry_after della connessione «database» (90). */
    public const SHORT_TIMEOUT = 60;

    /** Secondi massimi di un lavoro lungo; il retry_after di «database-long» è questo più 5 minuti. */
    public const LONG_TIMEOUT = 1800;

    public const MAX_WORKERS = 3;

    /** Esclusione dei lavoratori brevi (minuti): fino a 50-55 s di giro più un lavoro breve. */
    private const SHORT_MUTEX_MINUTES = 5;

    /** La connessione del lavoratore dei lunghi; null (quella predefinita) se le code non sono sul database. */
    public static function longConnection(): ?string
    {
        return config('queue.default') === 'database' ? self::LONG_CONNECTION : null;
    }

    public static function maxWorkers(): int
    {
        $max = config('saas.queue.max_workers');

        return max(1, min(self::MAX_WORKERS, is_numeric($max) ? (int) $max : self::MAX_WORKERS));
    }

    /**
     * I lavoratori da lanciare ogni minuto, in ordine di importanza, con l'esclusione di ciascuno (minuti) e, per quello
     * dei lunghi, la coda che deve avere qualcosa perché valga la pena avviarlo.
     *
     * @return list<array{command: string, mutex_minutes: int, only_if_queued: ?string}>
     */
    public static function workers(?int $max = null): array
    {
        $max ??= self::maxWorkers();
        $long = self::longConnection();
        // L'esclusione di chi può prendere un lavoro lungo dura quanto la sua ripresa: mai due giri sullo stesso lavoro.
        $retry = config('queue.connections.'.self::LONG_CONNECTION.'.retry_after');
        $longMutex = (int) ceil((is_numeric($retry) ? (int) $retry : self::LONG_TIMEOUT) / 60);

        if ($max === 1) {
            return [self::worker($long, MailQueue::WORKER_QUEUES.','.self::LONG, 50, self::LONG_TIMEOUT, $longMutex)];
        }

        $workers = [
            self::worker(null, MailQueue::WORKER_QUEUES, 50, self::SHORT_TIMEOUT, self::SHORT_MUTEX_MINUTES),
            self::worker($long, self::LONG, 50, self::LONG_TIMEOUT, $longMutex, onlyIfQueued: true),
        ];
        if ($max >= 3) {
            // Codici di accesso e link di conferma: mai dietro a un altro lavoro.
            $workers[] = self::worker(null, MailQueue::URGENT, 55, self::SHORT_TIMEOUT, self::SHORT_MUTEX_MINUTES);
        }

        return $workers;
    }

    /** @return array{command: string, mutex_minutes: int, only_if_queued: ?string} */
    private static function worker(?string $connection, string $queues, int $maxTime, int $timeout, int $mutex, bool $onlyIfQueued = false): array
    {
        return [
            'command' => 'queue:work'.($connection !== null ? ' '.$connection : '')." --queue={$queues} --stop-when-empty --tries=3 --max-time={$maxTime} --timeout={$timeout}",
            'mutex_minutes' => $mutex,
            'only_if_queued' => $onlyIfQueued ? $queues : null,
        ];
    }
}
