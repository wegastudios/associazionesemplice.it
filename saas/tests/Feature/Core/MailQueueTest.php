<?php

declare(strict_types=1);

use App\Modules\Attivita\Jobs\SendPromotionEmail;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\ThrottledMail;
use App\Modules\Soci\Jobs\SendApplicationEmail;
use App\Modules\Soci\Jobs\SendReminderEmail;
use App\Modules\Soci\Services\ApplicationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * Posta in coda: due code (urgenti prima, massivi per ultimi), tetti per mailer, e al rifiuto del server di posta
 * la coda si ferma per un'ora invece di insistere (oltre il limite la posta si perderebbe).
 */

const MQ_ORG = '00000000-0000-7000-8000-000000000001';
const MQ_ID = '00000000-0000-7000-8000-000000000002';

function mqUrgent(): SendApplicationEmail
{
    return (new SendApplicationEmail(MQ_ORG, MQ_ID, 'confirm', 'Polisportiva Alfa'))->withFakeQueueInteractions();
}

function mqBulk(): SendPromotionEmail
{
    return (new SendPromotionEmail(MQ_ORG, MQ_ID, MQ_ID, false))->withFakeQueueInteractions();
}

/** Passa il lavoro dalla sua middleware della posta: true se è arrivato all'invio. */
function mqThrough(object $job, ?Closure $send = null): bool
{
    $middleware = collect($job->middleware())->first(static fn (object $m): bool => $m instanceof ThrottledMail);
    expect($middleware)->toBeInstanceOf(ThrottledMail::class);
    $ran = false;
    $middleware->handle($job, static function () use (&$ran, $send): void {
        $ran = true;
        if ($send !== null) {
            $send();
        }
    });

    return $ran;
}

/** @return list<array{string, int, int}> */
function mqLimits(string $queue): array
{
    return array_map(static fn ($l): array => [$l->key, $l->maxAttempts, $l->decaySeconds], MailQueue::limits($queue));
}

beforeEach(function (): void {
    config(['mail.default' => 'smtp']);
});

it('conta i tetti per mailer: gli urgenti solo sul totale, i massivi anche sul loro', function (): void {
    expect(mqLimits(MailQueue::URGENT))->toBe([['mail-all|smtp|3600', 140, 3600]])
        ->and(mqLimits(MailQueue::BULK))->toBe([
            ['mail-bulk|smtp|60', 3, 60],
            ['mail-bulk|smtp|3600', 100, 3600],
            ['mail-all|smtp|3600', 140, 3600],
        ]);
});

it('legge i tetti di ogni mailer dalla configurazione, anche al secondo, al giorno e al mese', function (): void {
    config(['mail.default' => 'emailit', 'saas.mail_queue.mailers.emailit' => ['per_second' => 2, 'per_hour' => 900, 'per_day' => 4500, 'per_month' => 50000]]);

    expect(mqLimits(MailQueue::URGENT))->toBe([
        ['mail-all|emailit|1', 2, 1],
        ['mail-all|emailit|3600', 900, 3600],
        ['mail-all|emailit|86400', 4500, 86400],
        ['mail-all|emailit|2592000', 50000, 2592000],
    ]);
});

it('un mailer senza tetti propri usa quelli di smtp, contati a parte', function (): void {
    config(['mail.default' => 'altro']);

    expect(mqLimits(MailQueue::URGENT))->toBe([['mail-all|altro|3600', 140, 3600]]);
});

it('mette la conferma della domanda tra gli urgenti e il resto tra i massivi', function (): void {
    expect(mqUrgent()->queue)->toBe(MailQueue::URGENT)
        ->and(mqBulk()->queue)->toBe(MailQueue::BULK)
        ->and((new SendReminderEmail(MQ_ORG, MQ_ID, 'cert_soon', '2026-12-01', '2026-11-23'))->queue)->toBe(MailQueue::BULK);
    foreach (['received', 'accepted', 'rejected'] as $kind) {
        expect((new SendApplicationEmail(MQ_ORG, MQ_ID, $kind, 'Polisportiva Alfa'))->queue)->toBe(MailQueue::BULK);
    }

    Queue::fake();
    SendApplicationEmail::dispatch(MQ_ORG, MQ_ID, 'confirm', 'Polisportiva Alfa');
    Queue::assertPushedOn(MailQueue::URGENT, SendApplicationEmail::class);
});

it('il lavoratore prende prima gli urgenti, poi il resto, per ultimi i massivi', function (): void {
    $workers = collect(app(Schedule::class)->events())->filter(static fn ($e): bool => str_contains((string) $e->command, 'queue:work'));

    expect($workers)->toHaveCount(1)
        ->and((string) $workers->first()->command)->toContain('--queue=mail-urgent,default,mail-bulk');
});

it('i massivi si fermano a 3 al minuto mentre gli urgenti continuano', function (): void {
    foreach (range(1, 3) as $i) {
        expect(mqThrough(mqBulk()))->toBeTrue();
    }
    $held = mqBulk();
    expect(mqThrough($held))->toBeFalse();
    $held->assertReleased();

    expect(mqThrough(mqUrgent()))->toBeTrue();
});

it('al tetto totale dell\'ora si fermano anche gli urgenti, e solo per quel mailer', function (): void {
    RateLimiter::increment('mail-all|smtp|3600', 3600, 140);

    $held = mqUrgent();
    expect(mqThrough($held))->toBeFalse();
    $held->assertReleased();

    config(['mail.default' => 'emailit']);
    expect(mqThrough(mqUrgent()))->toBeTrue();
});

it('al rifiuto del server di posta il lavoro si rilascia per un\'ora e tutta la posta di quel mailer si ferma', function (): void {
    $this->freezeTime();
    $job = mqBulk();
    expect(mqThrough($job, static fn () => throw new TransportException('Expected response code "250" but got code "550", with message "550 limite orario superato".', 550)))->toBeTrue();

    $job->assertReleased(3600)->assertNotFailed();
    expect(MailQueue::blockedUntil('smtp'))->toBe(now()->addHour()->getTimestamp());

    // Il lavoro dopo non prova nemmeno a mandare.
    $next = mqUrgent();
    expect(mqThrough($next))->toBeFalse();
    $next->assertReleased();

    // L'interruttore vale per un mailer solo.
    config(['mail.default' => 'emailit']);
    expect(mqThrough(mqUrgent()))->toBeTrue();
});

it('un indirizzo rifiutato fa fallire solo quel messaggio, senza fermare la posta né lasciare l\'indirizzo tra i falliti', function (): void {
    $job = mqBulk();
    mqThrough($job, static fn () => throw new TransportException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <mario@example.com>: Recipient address rejected".', 550));

    $job->assertFailed()->assertNotReleased();
    expect(MailQueue::blockedUntil('smtp'))->toBeNull()
        ->and($job->job->failedWith->getMessage())->not->toContain('mario@example.com');
});

it('lo stesso messaggio rifiutato più volte alla fine si arrende, invece di fermare la posta ogni ora', function (): void {
    // Lo stesso lavoro (stesso identificativo) ripreso dalla coda dopo ogni ora di attesa.
    $attempt = static function (): FakeJob {
        $fake = new class extends FakeJob
        {
            public function getRawBody(): string
            {
                return (string) json_encode(['uuid' => '0193a0c4-0000-7000-8000-00000000abcd']);
            }
        };
        $job = new SendPromotionEmail(MQ_ORG, MQ_ID, MQ_ID, false);
        $job->setJob($fake);
        mqThrough($job, static fn () => throw new TransportException('Expected response code "250" but got code "554", with message "554 rifiutato".', 554));

        return $fake;
    };

    foreach (range(1, 2) as $i) {
        $fake = $attempt();
        expect($fake->isReleased())->toBeTrue()->and($fake->failedWith)->toBeNull();
        $this->travel(61)->minutes();
    }

    $fake = $attempt();
    expect($fake->isReleased())->toBeFalse()
        ->and($fake->failedWith)->toBeInstanceOf(Throwable::class)
        ->and($fake->failedWith->getMessage())->toContain('codice 554');
});

it('gli altri errori non toccano l\'interruttore e seguono i tentativi normali', function (): void {
    expect(fn () => mqThrough(mqBulk(), static fn () => throw new RuntimeException('database non raggiungibile')))->toThrow(RuntimeException::class);
    expect(MailQueue::blockedUntil('smtp'))->toBeNull();
});

it('gli urgenti si riprovano fino alla scadenza del link, i massivi per 48 ore, senza un numero fisso di tentativi', function (): void {
    $this->freezeTime();

    expect(mqUrgent()->retryUntil()->getTimestamp())->toBe(now()->addHours(ApplicationService::CONFIRM_HOURS)->getTimestamp())
        ->and(mqBulk()->retryUntil()->getTimestamp())->toBe(now()->addHours(48)->getTimestamp())
        ->and((new SendApplicationEmail(MQ_ORG, MQ_ID, 'accepted', 'Polisportiva Alfa'))->retryUntil()->getTimestamp())->toBe(now()->addHours(48)->getTimestamp())
        ->and((new SendReminderEmail(MQ_ORG, MQ_ID, 'cert_soon', '2026-12-01', '2026-11-23'))->retryUntil()->getTimestamp())->toBe(now()->addHours(48)->getTimestamp());
});
