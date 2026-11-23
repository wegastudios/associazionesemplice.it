<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Code dei lavori per tipo e lavoratori lanciati dal cron (sul server non ci sono processi sempre accesi).
 *
 * - Lavori brevi (posta, avvisi): code mail-urgent, default e mail-bulk (MailQueue), connessione «database», ripresi
 *   dopo 90 s se il lavoratore muore; il lavoratore li ferma a SHORT_TIMEOUT.
 * - Lavori lunghi (copia completa, import): coda «long» sulla connessione «database-long», stessa tabella ma ripresi
 *   solo dopo un tempo più lungo del lavoro più lungo (LONG_TIMEOUT), così un lavoro che gira non parte una seconda
 *   volta. Il lavoro sceglie la coda dal costruttore: onConnection(longConnection())->onQueue(LONG).
 *
 * Lavoratori, al massimo saas.queue.max_workers (1-3; il server ha 25 processi PHP in tutto, condivisi con il web):
 * 1 = uno solo per tutto; 2 = brevi + lunghi; 3 = in più uno per la sola posta urgente.
 */
final class JobQueues
{
    public const DEFAULT = 'default';

    public const LONG = 'long';

    public const LONG_CONNECTION = 'database-long';

    /** Secondi massimi di un lavoro breve: sotto il retry_after della connessione «database» (90). */
    public const SHORT_TIMEOUT = 60;

    /** Secondi massimi di un lavoro lungo: sotto il retry_after di «database-long» (2100). */
    public const LONG_TIMEOUT = 1800;

    public const MAX_WORKERS = 3;

    /** Esclusione dei lavoratori brevi (minuti): fino a 50-55 s di giro più un lavoro breve. */
    private const SHORT_MUTEX_MINUTES = 5;

    /** La connessione dei lavori lunghi; null (quella predefinita) se le code non sono sul database, come «sync» nei test. */
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
     * I lavoratori da lanciare ogni minuto, in ordine di importanza, con l'esclusione di ciascuno (minuti).
     *
     * @return list<array{command: string, mutex_minutes: int}>
     */
    public static function workers(?int $max = null): array
    {
        $max ??= self::maxWorkers();
        $long = self::longConnection();
        $longPrefix = 'queue:work'.($long !== null ? ' '.$long : '');
        // L'esclusione di chi può prendere un lavoro lungo dura quanto la sua ripresa: mai due giri sullo stesso lavoro.
        $retry = $long !== null ? config('queue.connections.'.$long.'.retry_after') : null;
        $longMutex = (int) ceil((is_numeric($retry) ? (int) $retry : self::LONG_TIMEOUT + 300) / 60);
        $flags = ' --stop-when-empty --tries=3';

        if ($max === 1) {
            return [['command' => $longPrefix.' --queue='.MailQueue::WORKER_QUEUES.','.self::LONG.$flags.' --max-time=50 --timeout='.self::LONG_TIMEOUT, 'mutex_minutes' => $longMutex]];
        }

        $workers = [
            ['command' => 'queue:work --queue='.MailQueue::WORKER_QUEUES.$flags.' --max-time=50 --timeout='.self::SHORT_TIMEOUT, 'mutex_minutes' => self::SHORT_MUTEX_MINUTES],
            ['command' => $longPrefix.' --queue='.self::LONG.$flags.' --max-time=50 --timeout='.self::LONG_TIMEOUT, 'mutex_minutes' => $longMutex],
        ];
        if ($max >= 3) {
            // Codici di accesso e link di conferma: mai dietro a un altro lavoro.
            $workers[] = ['command' => 'queue:work --queue='.MailQueue::URGENT.$flags.' --max-time=55 --timeout='.self::SHORT_TIMEOUT, 'mutex_minutes' => self::SHORT_MUTEX_MINUTES];
        }

        return $workers;
    }
}
