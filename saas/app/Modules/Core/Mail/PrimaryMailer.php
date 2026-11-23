<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use App\Modules\Core\Contracts\MailQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Servizio di invio principale (Emailit, docs/POSTA_EMAILIT.md): acceso solo con SAAS_MAIL_PRIMARY=emailit e la chiave nel
 * file d'ambiente (mailer «emailit» in config/mail.php); spento, la posta parte come sempre dal mailer di sempre.
 *
 * Acceso, la posta parte da qui entro i suoi tetti (saas.mail_queue.mailers.emailit, contati a parte); il mailer di
 * sempre (cPanel) è il ripiego, entro i suoi tetti:
 * - «452 4.4.5» (troppi al secondo): il messaggio riprova tra 2 secondi, nessun errore contato.
 * - «452 4.5.3» o quota del giorno finita: fino alla mezzanotte UTC gli urgenti passano al ripiego, i massivi aspettano.
 * - Altri rifiuti (connessione, 5xx, 530, 535): errore di fila e messaggio di nuovo in coda; al terzo di fila il servizio
 *   si spegne per 15 minuti e tutta la posta va sul ripiego; poi un solo messaggio prova e, se parte, lo riaccende.
 *
 * Nei log solo il mailer e il codice SMTP: mai la chiave, mai gli indirizzi.
 */
final class PrimaryMailer
{
    public const NAME = 'emailit';

    /** Errori di fila che spengono il servizio, e per quanti minuti. */
    public const MAX_ERRORS = 3;

    private const OFF_MINUTES = 15;

    /** Attesa dopo «troppi al secondo» (secondi). */
    public const SECOND_WAIT = 2;

    /** Stati del servizio per il prossimo invio. */
    public const READY = 'ready';

    /** Spento da 15 minuti dopo gli errori: questo invio fa la prova (uno solo alla volta). */
    public const PROBE = 'probe';

    /** Spento per errori (o la prova è già di un altro invio): tutta la posta sul ripiego. */
    public const DOWN = 'down';

    /** Quota del giorno finita fino alla mezzanotte UTC. */
    public const DAY_FULL = 'day';

    /** Esiti di un rifiuto (rejected()), oltre a DAY_FULL. */
    public const SECOND = 'second';

    public const ERROR = 'error';

    /** Il rifiuto ha appena spento il servizio. */
    public const OFF = 'off';

    private const ERRORS_KEY = 'mail-primary-errors:'.self::NAME;

    private const DAY_FULL_KEY = 'mail-primary-day-full:'.self::NAME;

    private const FALLBACK_KEY = 'mail-primary-fallback-since:'.self::NAME;

    public static function enabled(): bool
    {
        $key = config('mail.mailers.'.self::NAME.'.password');

        return config('saas.mail_queue.primary') === self::NAME && is_string($key) && $key !== '';
    }

    /** Stato senza effetti (controllo di salute): READY, DAY_FULL, DOWN, o PROBE se tocca a una prova non ancora presa. */
    public static function peek(): string
    {
        return match (true) {
            self::dayFullUntil() !== null => self::DAY_FULL,
            MailQueue::blockedUntil(self::NAME) !== null => self::DOWN,
            self::errors() >= self::MAX_ERRORS => self::PROBE,
            default => self::READY,
        };
    }

    /** Stato per il prossimo invio. Con PROBE l'invio tiene la prova: se non parte, va liberata (MailQueue::endProbe). */
    public static function state(): string
    {
        $state = self::peek();

        return $state === self::PROBE && ! MailQueue::startProbe(self::NAME) ? self::DOWN : $state;
    }

    /** Fino a quando (timestamp) la quota del giorno è finita; null se non lo è. */
    public static function dayFullUntil(): ?int
    {
        $until = Cache::get(self::DAY_FULL_KEY);

        return is_numeric($until) && (int) $until > now()->getTimestamp() ? (int) $until : null;
    }

    /** Errori di fila del servizio. */
    public static function errors(): int
    {
        $errors = Cache::get(self::ERRORS_KEY);

        return is_numeric($errors) ? (int) $errors : 0;
    }

    /** Da quando (timestamp) la posta parte dal ripiego senza che il servizio abbia più mandato nulla; null se non è così. */
    public static function fallbackSince(): ?int
    {
        $since = Cache::get(self::FALLBACK_KEY);

        return is_numeric($since) ? (int) $since : null;
    }

    /**
     * Un invio è partito da $mailer. Dal servizio: errori azzerati, ripiego finito, servizio riacceso se era la prova.
     * Dal mailer di sempre con il servizio acceso: è un ripiego, se ne annota l'inizio.
     */
    public static function delivered(string $mailer): void
    {
        if (! self::enabled()) {
            return;
        }
        if ($mailer !== self::NAME) {
            Cache::add(self::FALLBACK_KEY, now()->getTimestamp(), now()->addDays(2));

            return;
        }
        if (Cache::forget(self::ERRORS_KEY)) {
            MailQueue::unblock(self::NAME);
            MailQueue::endProbe(self::NAME);
        }
        Cache::forget(self::FALLBACK_KEY);
    }

    /**
     * Rifiuto del servizio che non riguarda il solo destinatario (quello lo vede ThrottledMail::recipientOnly prima):
     * SECOND, DAY_FULL, ERROR, o OFF se è il terzo errore di fila e il servizio si è appena spento.
     */
    public static function rejected(TransportExceptionInterface $e): string
    {
        $context = ['mailer' => self::NAME, 'code' => $e->getCode()];
        if (preg_match('/\b4\.4\.5\b/', $e->getMessage()) === 1) {
            return self::SECOND;
        }
        if (preg_match('/\b4\.5\.3\b/', $e->getMessage()) === 1) {
            $until = MailQueue::nextUtcMidnight();
            Cache::put(self::DAY_FULL_KEY, $until, now()->setTimestamp($until));
            Log::warning('Servizio di invio: quota del giorno finita, urgenti sul ripiego fino alla mezzanotte UTC', $context);

            return self::DAY_FULL;
        }

        MailCounters::add(self::NAME, errors: 1);
        Cache::add(self::ERRORS_KEY, 0, now()->addDay());
        $errors = (int) Cache::increment(self::ERRORS_KEY);
        if ($errors >= self::MAX_ERRORS) {
            $until = MailQueue::block(self::NAME, self::OFF_MINUTES);
            MailQueue::endProbe(self::NAME);
            Log::warning('Servizio di invio spento per errori di fila: posta sul ripiego', $context + ['errors' => $errors, 'until' => date(DATE_ATOM, $until)]);

            return self::OFF;
        }
        Log::warning('Servizio di invio: invio rifiutato', $context + ['errors' => $errors]);

        return self::ERROR;
    }

    /**
     * Se Redis si è svuotato, il conteggio del giorno riparte dalla tabella core.mail_counters (una volta al giorno per
     * cache: la chiave di controllo sparisce con Redis).
     */
    public static function seedDay(): void
    {
        $limit = MailQueue::totalLimit(86400, self::NAME);
        if ($limit === null) {
            return;
        }
        $key = MailQueue::key($limit);
        if (! Cache::add('mail-day-seeded:'.$key, 1, $limit->decaySeconds)) {
            return;
        }
        $stored = MailCounters::sentToday(self::NAME);
        $counted = RateLimiter::attempts($key);
        $counted = is_numeric($counted) ? (int) $counted : 0;
        if ($stored > $counted) {
            RateLimiter::increment($key, $limit->decaySeconds, $stored - $counted);
        }
    }
}
