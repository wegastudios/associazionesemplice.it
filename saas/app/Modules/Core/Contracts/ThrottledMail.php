<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Middleware dei lavori di posta (MailQueue). Il lavoro deve usare InteractsWithQueue.
 *
 *   public function middleware(): array { return [new ThrottledMail]; }
 *
 * - Posta ferma dopo un rifiuto del server: i massivi ripartono quando l'interruttore si spegne, senza provare; degli
 *   urgenti ne passa uno ogni 10 minuti come prova (MailQueue::startProbe): se parte, l'interruttore si chiude.
 *   Il rifiuto per limite orario dell'account (testo «max emails per hour» o «exceeded») è sempre l'interruttore.
 * - Tetti della coda del lavoro (MailQueue::limits), più quelli di un limitatore del modulo se indicato (RateLimiter::for):
 *   oltre, il lavoro riparte più tardi. Le chiavi sono quelle dei tetti, così tutti i lavori contano sugli stessi.
 * - Rifiuto o errore del server di posta: il lavoro riparte tra un'ora e la posta del mailer si ferma per tutti (oltre il
 *   limite orario il server rifiuta e il messaggio si perderebbe). Le attese non contano come errori (maxExceptions).
 *   Se il server rifiuta solo il destinatario (indirizzo o casella), si arrende solo quel messaggio; lo stesso messaggio
 *   rifiutato MAX_REJECTIONS volte si arrende, così non ferma la posta di tutti ogni ora. Nei lavori falliti resta solo il
 *   codice del server, mai la sua risposta (può contenere l'indirizzo).
 */
final class ThrottledMail
{
    public const MAX_REJECTIONS = 3;

    /** Ritardo massimo aggiunto ai massivi rilasciati: ripartono sparsi invece di ripresentarsi tutti al minuto dopo. */
    private const BULK_SPREAD_SECONDS = 300;

    public function __construct(private readonly ?string $moduleLimiter = null) {}

    /** @param  Closure(object): mixed  $next */
    public function handle(object $job, Closure $next): mixed
    {
        // Coda «sync» (sviluppo e test): il lavoro gira dentro la richiesta, non c'è una coda dove aspettare.
        if (property_exists($job, 'job') && $job->job instanceof SyncJob) {
            return $next($job);
        }

        $mailer = MailQueue::mailer();
        $queue = property_exists($job, 'queue') && is_string($job->queue) ? $job->queue : MailQueue::URGENT;
        $urgent = $queue !== MailQueue::BULK;
        $blocked = MailQueue::blockedUntil($mailer);
        $probe = false;
        if ($blocked !== null) {
            // Posta ferma: i massivi aspettano la fine; un solo urgente ogni PROBE_SECONDS prova se il server ha ripreso.
            $left = $blocked - now()->getTimestamp();
            if (! $urgent || ! MailQueue::startProbe($mailer)) {
                return $this->release($job, $urgent ? min($left, MailQueue::PROBE_SECONDS) : $left);
            }
            $probe = true;
        }

        $limits = $this->limits($job, $queue, $mailer);
        $wait = null;
        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts(MailQueue::key($limit), $limit->maxAttempts)) {
                $wait = max($wait ?? 0, RateLimiter::availableIn(MailQueue::key($limit)));
            }
        }
        if ($wait !== null) {
            return $this->release($job, $wait + ($queue === MailQueue::BULK ? random_int(0, self::BULK_SPREAD_SECONDS) : 0));
        }
        foreach ($limits as $limit) {
            RateLimiter::hit(MailQueue::key($limit), $limit->decaySeconds);
        }

        try {
            // Il destinatario è già contato dai tetti qui sopra; gli altri, se il messaggio ne ha più d'uno, quando parte.
            $result = MailQueue::reserved($queue, static fn (): mixed => $next($job));
        } catch (TransportExceptionInterface $e) {
            return $this->rejected($job, $mailer, $urgent, $e);
        }
        // La prova è passata: il server ha ripreso, la posta riparte per tutti.
        if ($probe) {
            MailQueue::unblock($mailer);
        }

        return $result;
    }

    private function rejected(object $job, string $mailer, bool $urgent, TransportExceptionInterface $e): null
    {
        $code = $e->getCode();
        // Il limite orario dell'account non conta come rifiuto del messaggio.
        $hourLimit = self::hourLimit($e);
        $recipient = self::recipientOnly($e);

        if ($recipient === '5' || (! $hourLimit && $this->rejections($job) >= self::MAX_REJECTIONS)) {
            if (! method_exists($job, 'fail')) {
                throw new LogicException('Il lavoro di posta deve usare InteractsWithQueue.');
            }
            $job->fail(new RuntimeException("Messaggio rifiutato dal server di posta (codice {$code})."));

            return null;
        }

        if ($recipient === null) {
            $until = MailQueue::block($mailer);
            Log::warning('Server di posta: invio rifiutato, posta in coda ferma', ['mailer' => $mailer, 'code' => $code, 'until' => date(DATE_ATOM, $until)]);
        }

        // Un urgente può fare da prova al prossimo turno; un massivo aspetta tutta la pausa.
        return $this->release($job, $urgent ? MailQueue::PROBE_SECONDS : MailQueue::blockedMinutes() * 60);
    }

    /**
     * Il rifiuto riguarda il solo destinatario? '5' definitivo, '4' temporaneo, null no (server o account). Codici estesi
     * x.1.x (indirizzo) e x.2.1/x.2.2 (casella disattivata o piena); mai il limite orario dell'account, anche se il server
     * lo manda con un codice da destinatario.
     */
    public static function recipientOnly(TransportExceptionInterface $e): ?string
    {
        if (self::hourLimit($e) || preg_match('/\b([45])\.(?:1\.\d{1,3}|2\.[12])\b/', $e->getMessage(), $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /** Limite orario dell'account (exim: «has exceeded the max emails per hour»): sempre l'interruttore. */
    private static function hourLimit(TransportExceptionInterface $e): bool
    {
        return preg_match('/max emails per hour|exceeded/i', $e->getMessage()) === 1;
    }

    /** Quante volte il server ha rifiutato questo stesso lavoro (contato per identificativo, per tre giorni). */
    private function rejections(object $job): int
    {
        $queued = property_exists($job, 'job') ? $job->job : null;
        $uuid = $queued instanceof Job ? $queued->uuid() : null;
        if (! is_string($uuid) || $uuid === '') {
            return 1;
        }
        $key = 'mail-rejections:'.$uuid;
        Cache::add($key, 0, now()->addDays(3));

        return (int) Cache::increment($key);
    }

    /** @return list<Limit> */
    private function limits(object $job, string $queue, string $mailer): array
    {
        $limits = MailQueue::limits($queue, $mailer);
        $callback = $this->moduleLimiter === null ? null : RateLimiter::limiter($this->moduleLimiter);
        $result = $callback === null ? [] : $callback($job);
        foreach (is_array($result) ? $result : [$result] as $limit) {
            if ($limit instanceof Limit) {
                $limits[] = $limit;
            }
        }

        return $limits;
    }

    private function release(object $job, int $seconds): null
    {
        if (! method_exists($job, 'release')) {
            throw new LogicException('Il lavoro di posta deve usare InteractsWithQueue.');
        }
        $job->release(max(1, $seconds));

        return null;
    }
}
