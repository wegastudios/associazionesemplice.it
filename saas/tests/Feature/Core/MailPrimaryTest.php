<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\QueuedMail;
use App\Modules\Core\Contracts\ThrottledMail;
use App\Modules\Core\Mail\PrimaryMailer;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/*
 * Servizio di invio principale (Emailit), preparato ma spento: SAAS_MAIL_PRIMARY=emailit e la chiave nel file d'ambiente
 * lo accendono. Acceso, la posta parte da lì entro i suoi tetti; il mailer di sempre (cPanel) resta il ripiego: per gli
 * urgenti quando la quota del giorno è finita, per tutti quando il servizio dà errori di fila (interruttore di 15 minuti).
 */

const MP_KEY = 'CHIAVE-EMAILIT-SEGRETA-7731';

function mpOn(): void
{
    config([
        'saas.mail_queue.primary' => 'emailit',
        'mail.mailers.emailit.password' => MP_KEY,
    ]);
}

function mpJob(string $queue): QueuedMail
{
    return QueuedMail::raw('mario@example.com', 'Oggetto', 'Testo', $queue, now()->addHours(48))->withFakeQueueInteractions();
}

/** Passa il lavoro dalla middleware: il mailer con cui avrebbe mandato, null se non è arrivato all'invio. */
function mpThrough(object $job, ?Closure $send = null): ?string
{
    $used = null;
    (new ThrottledMail)->handle($job, static function () use (&$used, $send): void {
        $used = config('mail.default');
        if ($send !== null) {
            $send();
        }
    });

    return $used;
}

function mpError(string $smtp): TransportException
{
    return new TransportException('Expected response code "250" but got code "'.substr($smtp, 0, 3).'", with message "'.$smtp.' <mario@example.com>".', (int) substr($smtp, 0, 3));
}

function mpNextUtcMidnight(): int
{
    return now('UTC')->startOfDay()->addDay()->getTimestamp();
}

beforeEach(function (): void {
    config(['mail.default' => 'smtp']);
});

it('prepara il mailer emailit dal solo file d\'ambiente, con il mittente di sempre', function (): void {
    $mailer = config('mail.mailers.emailit');

    expect($mailer['transport'])->toBe('emailit')
        ->and($mailer['host'])->toBe('smtp.emailit.com')
        ->and((int) $mailer['port'])->toBe(465)
        ->and($mailer['username'])->toBe('emailit')
        ->and($mailer)->toHaveKey('password')
        ->and($mailer)->not->toHaveKey('from')
        ->and(config('saas.mail_queue.primary'))->toBe('cpanel');
});

it('tetti di emailit: 2 al secondo, 900 all\'ora, 4.000 al giorno (3.500 massivi) azzerati a mezzanotte UTC, 900.000 al mese', function (): void {
    $this->freezeTime();
    $day = now('UTC')->toDateString();
    $toMidnight = mpNextUtcMidnight() - now()->getTimestamp();
    $limits = static fn (string $queue): array => array_map(static fn ($l): array => [$l->key, $l->maxAttempts, $l->decaySeconds], MailQueue::limits($queue, 'emailit'));

    expect($limits(MailQueue::URGENT))->toBe([
        ['mail-all|emailit|1', 2, 1],
        ['mail-all|emailit|3600', 900, 3600],
        ["mail-all|emailit|86400|{$day}", 4000, $toMidnight],
        ['mail-all|emailit|2592000', 900000, 2592000],
    ])->and($limits(MailQueue::BULK))->toContain(["mail-bulk|emailit|86400|{$day}", 3500, $toMidnight]);
});

it('spento (di base) la posta parte sempre dal mailer di sempre', function (): void {
    expect(mpThrough(mpJob(MailQueue::URGENT)))->toBe('smtp')
        ->and(mpThrough(mpJob(MailQueue::BULK)))->toBe('smtp');
});

it('con SAAS_MAIL_PRIMARY=emailit ma senza chiave resta spento', function (): void {
    config(['saas.mail_queue.primary' => 'emailit', 'mail.mailers.emailit.password' => '']);

    expect(mpThrough(mpJob(MailQueue::URGENT)))->toBe('smtp');
});

it('acceso manda con emailit e poi torna al mailer di sempre', function (): void {
    mpOn();

    expect(mpThrough(mpJob(MailQueue::URGENT)))->toBe('emailit')
        ->and(mpThrough(mpJob(MailQueue::BULK)))->toBe('emailit')
        ->and(config('mail.default'))->toBe('smtp')
        ->and(RateLimiter::attempts('mail-all|emailit|3600'))->toBe(2)
        ->and(RateLimiter::attempts('mail-all|smtp|3600'))->toBe(0);
});

it('oltre i 2 al secondo il messaggio aspetta un attimo, senza passare a cPanel', function (): void {
    mpOn();
    $this->freezeTime();
    mpThrough(mpJob(MailQueue::URGENT));
    mpThrough(mpJob(MailQueue::URGENT));

    $held = mpJob(MailQueue::URGENT);
    expect(mpThrough($held))->toBeNull();
    $held->assertReleased();
    expect($held->job->releaseDelay)->toBeLessThanOrEqual(2)
        ->and(RateLimiter::attempts('mail-all|smtp|3600'))->toBe(0);
});

it('«452 4.4.5» (troppi al secondo) rimette il messaggio in coda per 2 secondi senza contare un errore', function (): void {
    mpOn();
    $job = mpJob(MailQueue::BULK);
    mpThrough($job, static fn () => throw mpError('452 4.4.5 Too many messages per second'));

    $job->assertReleased(2)->assertNotFailed();
    expect(MailQueue::blockedUntil('emailit'))->toBeNull()
        ->and(mpThrough(mpJob(MailQueue::URGENT)))->toBe('emailit');
});

it('«452 4.5.3» (giorno finito): gli urgenti passano a cPanel, i massivi aspettano la mezzanotte UTC', function (): void {
    mpOn();
    $this->freezeTime();
    $job = mpJob(MailQueue::URGENT);
    mpThrough($job, static fn () => throw mpError('452 4.5.3 Daily sending limit reached'));
    $job->assertReleased()->assertNotFailed();

    expect(mpThrough(mpJob(MailQueue::URGENT)))->toBe('smtp');

    $bulk = mpJob(MailQueue::BULK);
    expect(mpThrough($bulk))->toBeNull();
    $bulk->assertReleased();
    expect($bulk->job->releaseDelay)->toBeGreaterThanOrEqual(mpNextUtcMidnight() - now()->getTimestamp());

    // Dopo la mezzanotte UTC emailit riparte.
    $this->travelTo(now()->setTimestamp(mpNextUtcMidnight() + 1));
    expect(mpThrough(mpJob(MailQueue::BULK)))->toBe('emailit');
});

it('con la quota del giorno di emailit finita gli urgenti vanno su cPanel e i massivi aspettano', function (): void {
    mpOn();
    $day = MailQueue::limits(MailQueue::URGENT, 'emailit')[2];
    RateLimiter::increment(MailQueue::key($day), $day->decaySeconds, 4000);

    expect(mpThrough(mpJob(MailQueue::URGENT)))->toBe('smtp');
    $bulk = mpJob(MailQueue::BULK);
    expect(mpThrough($bulk))->toBeNull();
    $bulk->assertReleased();
});

it('al terzo errore di fila emailit si spegne per 15 minuti: tutto su cPanel entro i suoi tetti, poi una sola prova lo riapre', function (): void {
    mpOn();
    $this->freezeTime();
    foreach (range(1, 2) as $i) {
        $job = mpJob(MailQueue::BULK);
        mpThrough($job, static fn () => throw mpError('535 5.7.8 Authentication failed'));
        $job->assertReleased()->assertNotFailed();
        expect(MailQueue::blockedUntil('emailit'))->toBeNull();
        // Un secondo dopo l'altro: il tetto di 2 al secondo non c'entra.
        $this->travel(1)->seconds();
    }
    $third = mpJob(MailQueue::URGENT);
    mpThrough($third, static fn () => throw new TransportException('Connection could not be established with host "smtp.emailit.com:587".'));
    $third->assertReleased()->assertNotFailed();
    expect(MailQueue::blockedUntil('emailit'))->toBe(now()->addMinutes(15)->getTimestamp());

    // Interruttore aperto: urgenti e massivi su cPanel, i massivi al massimo 100 all'ora.
    expect(mpThrough(mpJob(MailQueue::URGENT)))->toBe('smtp')
        ->and(mpThrough(mpJob(MailQueue::BULK)))->toBe('smtp')
        ->and(MailQueue::limits(MailQueue::BULK, 'smtp')[1]->maxAttempts)->toBe(100);

    // Dopo 15 minuti un solo messaggio prova emailit; gli altri, nel frattempo, restano su cPanel.
    $this->travel(16)->minutes();
    $probe = mpJob(MailQueue::URGENT);
    $other = null;
    (new ThrottledMail)->handle($probe, static function () use (&$other): void {
        expect(config('mail.default'))->toBe('emailit');
        $other = mpThrough(mpJob(MailQueue::URGENT));
    });
    expect($other)->toBe('smtp')
        ->and(mpThrough(mpJob(MailQueue::BULK)))->toBe('emailit');
});

it('la prova fallita rispegne emailit per altri 15 minuti', function (): void {
    mpOn();
    $this->freezeTime();
    foreach (range(1, 3) as $i) {
        mpThrough(mpJob(MailQueue::BULK), static fn () => throw mpError('530 5.7.0 Authentication required'));
        $this->travel(1)->seconds();
    }
    expect(MailQueue::blockedUntil('emailit'))->not->toBeNull();
    $this->travel(16)->minutes();

    mpThrough(mpJob(MailQueue::URGENT), static fn () => throw mpError('554 5.0.0 Service unavailable'));
    expect(MailQueue::blockedUntil('emailit'))->toBe(now()->addMinutes(15)->getTimestamp());
});

it('un indirizzo rifiutato da emailit fa fallire solo quel messaggio, senza contare un errore di fila', function (): void {
    mpOn();
    $job = mpJob(MailQueue::BULK);
    mpThrough($job, static fn () => throw mpError('550 5.1.1 Recipient address rejected'));

    $job->assertFailed();
    expect(Cache::get('mail-primary-errors:emailit'))->toBeNull();
});

it('un urgente da mandare subito passa a cPanel se emailit è spento per errori', function (): void {
    mpOn();
    config(['mail.default' => 'array']);
    Queue::fake();
    MailQueue::block('emailit', 15);

    expect(MailQueue::sendNowOrQueue(QueuedMail::raw('mario@example.com', 'Link', 'Testo', MailQueue::URGENT, now()->addMinutes(15))))->toBeTrue();
    Queue::assertNothingPushed();
    expect(RateLimiter::attempts('mail-all|array|3600'))->toBe(1);
});

it('un urgente da mandare subito prova cPanel se emailit lo rifiuta, senza perdere il messaggio', function (): void {
    mpOn();
    config(['mail.default' => 'array']);
    Queue::fake();
    $tries = [];

    $sent = MailQueue::sendNowOrQueue(QueuedMail::raw('mario@example.com', 'Link', 'Testo', MailQueue::URGENT, now()->addMinutes(15)), static function () use (&$tries): void {
        $tries[] = config('mail.default');
        if (count($tries) === 1) {
            throw mpError('421 4.3.0 Temporary server error');
        }
    });

    expect($sent)->toBeTrue()->and($tries)->toBe(['emailit', 'array']);
    Queue::assertNothingPushed();
});

it('salva i contatori per giorno, ora e fornitore: inviati, errori e ripieghi', function (): void {
    mpOn();
    $this->travelTo(now()->startOfHour()->addMinutes(10));
    $this->freezeTime();
    config(['mail.default' => 'array', 'mail.mailers.emailit.transport' => 'array']);
    $send = static fn () => Mail::raw('Ciao', static fn (Message $m) => $m->to(['a@example.com', 'b@example.com'])->subject('Due'));

    mpThrough(mpJob(MailQueue::BULK), $send);
    $this->travel(1)->seconds();
    mpThrough(mpJob(MailQueue::BULK), static fn () => throw mpError('421 4.3.0 Temporary server error'));
    MailQueue::block('emailit', 15);
    mpThrough(mpJob(MailQueue::URGENT), $send);

    $rows = DB::table('core.mail_counters')->where('day', now('UTC')->toDateString())->where('hour', now('UTC')->hour)
        ->orderBy('provider')->get(['provider', 'sent', 'errors', 'fallbacks'])->map(static fn ($r): array => (array) $r)->all();
    expect($rows)->toBe([
        ['provider' => 'array', 'sent' => 2, 'errors' => 0, 'fallbacks' => 2],
        ['provider' => 'emailit', 'sent' => 2, 'errors' => 1, 'fallbacks' => 0],
    ]);
});

it('se Redis si svuota il conteggio del giorno riparte dalla tabella', function (): void {
    mpOn();
    $this->freezeTime();
    DB::table('core.mail_counters')->insert(['day' => now('UTC')->toDateString(), 'hour' => 0, 'provider' => 'emailit', 'sent' => 3999, 'errors' => 0, 'fallbacks' => 0]);

    expect(mpThrough(mpJob(MailQueue::URGENT)))->toBe('emailit')
        ->and(mpThrough(mpJob(MailQueue::URGENT)))->toBe('smtp');
});

it('nei log solo mailer e codice: mai la chiave né gli indirizzi', function (): void {
    mpOn();
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    foreach (range(1, 3) as $i) {
        mpThrough(mpJob(MailQueue::BULK), static fn () => throw new TransportException('Failed to authenticate on SMTP server with username "emailit" using password "'.MP_KEY.'" for mario@example.com: 535', 535));
    }
    mpThrough(mpJob(MailQueue::BULK), static fn () => throw mpError('452 4.5.3 Daily limit'));

    expect($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->not->toContain(MP_KEY)->not->toContain('mario@example.com')->toContain('emailit');
});

it('saas:health: emailit spento per errori è un problema', function (): void {
    mpOn();
    MailQueue::block('emailit', 15);

    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ERRORE servizio di invio')->assertFailed();
});

it('saas:health: oltre il 90% della quota del giorno è un avviso', function (): void {
    mpOn();
    $day = MailQueue::limits(MailQueue::URGENT, 'emailit')[2];
    RateLimiter::increment(MailQueue::key($day), $day->decaySeconds, 3601);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('AVVISO invii del giorno: 3601 su 4000')
        ->assertSuccessful();
});

it('saas:health: il ripiego su cPanel attivo da più di un\'ora è un avviso', function (): void {
    mpOn();
    $this->freezeTime();
    MailQueue::block('emailit', 15);
    expect(mpThrough(mpJob(MailQueue::URGENT)))->toBe('smtp');
    $this->artisan('saas:health', ['--skip-migrations' => true, '--mail-warnings-only' => true])->expectsOutputToContain('ok    ripiego della posta')->assertSuccessful();

    $this->travel(61)->minutes();
    MailQueue::block('emailit', 15);
    $this->artisan('saas:health', ['--skip-migrations' => true, '--mail-warnings-only' => true])->expectsOutputToContain('AVVISO ripiego della posta')->assertSuccessful();
});

it('saas:health: emailit principale senza chiave è un problema di configurazione', function (): void {
    app(KeyVault::class);
    config(['saas.mail_queue.primary' => 'emailit', 'mail.mailers.emailit.password' => '']);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('EMAILIT_PASSWORD')
        ->assertFailed();
});

it('emailit cifra sempre: 465 (predefinita) con TLS implicito, 587 con STARTTLS obbligatorio', function (): void {
    $requireTls = static fn (EsmtpTransport $t): bool => (fn (): bool => $this->requireTls)->call($t);

    $default = Mail::mailer('emailit')->getSymfonyTransport();
    expect($default)->toBeInstanceOf(EsmtpTransport::class)
        ->and($default->getStream()->isTLS())->toBeTrue()
        ->and($requireTls($default))->toBeTrue();

    // Se il server non offre STARTTLS l'invio fallisce invece di proseguire in chiaro.
    $starttls = PrimaryMailer::transport(['host' => 'smtp.emailit.com', 'port' => 587, 'username' => 'emailit', 'password' => MP_KEY]);
    expect($starttls->getStream()->isTLS())->toBeFalse()
        ->and($requireTls($starttls))->toBeTrue();
});

it('ThrottledMail e MailQueue::routes scelgono lo stesso mailer per ogni coda e ogni stato', function (string $queue, string $state): void {
    mpOn();
    $this->freezeTime();
    match ($state) {
        PrimaryMailer::READY => null,
        PrimaryMailer::PROBE => Cache::put('mail-primary-errors:emailit', PrimaryMailer::MAX_ERRORS, 3600),
        PrimaryMailer::DOWN => MailQueue::block('emailit', 15),
        PrimaryMailer::DAY_FULL => Cache::put('mail-primary-day-full:emailit', MailQueue::nextUtcMidnight(), 86400),
    };

    $route = MailQueue::routes($queue)[0] ?? null;
    // routes() ha preso la prova (PROBE): la si libera, così la middleware parte dallo stesso stato.
    MailQueue::endProbe('emailit');

    expect(PrimaryMailer::peek())->toBe($state)
        ->and(mpThrough(mpJob($queue)))->toBe($route);
})->with([MailQueue::URGENT, MailQueue::BULK])->with([PrimaryMailer::READY, PrimaryMailer::PROBE, PrimaryMailer::DOWN, PrimaryMailer::DAY_FULL]);

it('se Redis si svuota riparte dalla tabella anche il conteggio dei massivi', function (): void {
    mpOn();
    $this->freezeTime();
    DB::table('core.mail_counters')->insert(['day' => now('UTC')->toDateString(), 'hour' => 0, 'provider' => 'emailit', 'sent' => 3600, 'bulk_sent' => 3499, 'errors' => 0, 'fallbacks' => 0]);

    expect(mpThrough(mpJob(MailQueue::BULK)))->toBe('emailit');
    $held = mpJob(MailQueue::BULK);
    expect(mpThrough($held))->toBeNull();
    $held->assertReleased();
    expect(mpThrough(mpJob(MailQueue::URGENT)))->toBe('emailit');
});

it('conta i massivi a parte nella tabella', function (): void {
    mpOn();
    config(['mail.default' => 'array', 'mail.mailers.emailit.transport' => 'array']);
    $send = static fn () => Mail::raw('Ciao', static fn (Message $m) => $m->to('a@example.com')->subject('Uno'));

    mpThrough(mpJob(MailQueue::BULK), $send);
    mpThrough(mpJob(MailQueue::URGENT), $send);

    expect([(int) DB::table('core.mail_counters')->sum('sent'), (int) DB::table('core.mail_counters')->sum('bulk_sent')])->toBe([2, 1]);
});
