<?php

declare(strict_types=1);

use App\Modules\Cifratura\Monitor\KeyServiceAlertNotification;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\QueuedMail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\FakeKeyService;

/*
 * Controllo del servizio chiavi: un avviso per guasto, uno al ritorno, niente con la chiave locale, mai segreti.
 */

beforeEach(function (): void {
    Notification::fake();
    config(['saas.staff_issuer.super_admins' => 'titolare@example.test, Titolare@example.test']);
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    });
});

it('con la chiave principale locale non controlla nulla', function (): void {
    Http::fake();

    $this->artisan('saas:keys:monitor')->expectsOutputToContain('nessun servizio chiavi da controllare')->assertSuccessful();

    Http::assertNothingSent();
    Notification::assertNothingSent();
});

it('con il servizio che funziona non avvisa nessuno', function (): void {
    $service = (new FakeKeyService)->install();
    config(['saas.crypto.vault' => 'remote']);

    $this->artisan('saas:keys:monitor')->expectsOutputToContain('ok    servizio chiavi')->assertSuccessful();

    expect($service->calls)->toBeGreaterThanOrEqual(2);
    Notification::assertNothingSent();
});

it('un solo avviso per guasto e uno al ritorno, senza segreti', function (): void {
    $service = (new FakeKeyService)->install();
    config(['saas.crypto.vault' => 'remote']);
    $service->down = true;

    $this->artisan('saas:keys:monitor')->expectsOutputToContain('Servizio chiavi non disponibile (HTTP 503)')->assertFailed();
    $this->artisan('saas:keys:monitor')->assertFailed();
    $this->artisan('saas:keys:monitor')->assertFailed();

    Notification::assertSentOnDemandTimes(KeyServiceAlertNotification::class, 1);
    Notification::assertSentOnDemand(KeyServiceAlertNotification::class, function (KeyServiceAlertNotification $n, array $channels, AnonymousNotifiable $notifiable): bool {
        return $n->down && $notifiable->routes['mail'] === ['titolare@example.test'];
    });

    $service->down = false;
    $this->artisan('saas:keys:monitor')->assertSuccessful();
    $this->artisan('saas:keys:monitor')->assertSuccessful();

    Notification::assertSentOnDemandTimes(KeyServiceAlertNotification::class, 2);
    Notification::assertSentOnDemand(KeyServiceAlertNotification::class, fn (KeyServiceAlertNotification $n): bool => ! $n->down);

    $text = implode("\n", $this->logged);
    expect($text)->not->toContain(base64_encode($service->masterKey))
        ->and($text)->not->toContain(base64_encode($service->signingKey));
});

it('l\'allarme è posta urgente: con la quota dell\'ora finita aspetta in coda tra gli urgenti', function (): void {
    $service = (new FakeKeyService)->install();
    config(['saas.crypto.vault' => 'remote']);
    $service->down = true;
    Queue::fake();
    RateLimiter::increment('mail-all|'.MailQueue::mailer().'|3600', 3600, 10_000);

    $this->artisan('saas:keys:monitor')->expectsOutputToContain('Avviso in coda per 1 indirizzi.')->assertFailed();

    Notification::assertNothingSent();
    Queue::assertPushedOn(MailQueue::URGENT, QueuedMail::class);
});

it('si accorge di una firma rifiutata', function (): void {
    $service = (new FakeKeyService)->install();
    config(['saas.crypto.vault' => 'remote']);
    $service->wrongSignature = true;

    $this->artisan('saas:keys:monitor')->expectsOutputToContain('HTTP 401')->assertFailed();

    Notification::assertSentOnDemandTimes(KeyServiceAlertNotification::class, 1);
});

it('si accorge di un servizio con una chiave principale diversa da quella delle associazioni', function (): void {
    // La chiave dell'associazione è avvolta con un'altra chiave principale: la prova casuale passa, quella vera no.
    $this->createOrg('Polisportiva Alfa');
    (new FakeKeyService)->install();
    config(['saas.crypto.vault' => 'remote']);

    $this->artisan('saas:keys:monitor')->expectsOutputToContain('chiave principale diversa')->assertFailed();

    Notification::assertSentOnDemand(KeyServiceAlertNotification::class, fn (KeyServiceAlertNotification $n): bool => $n->down && str_contains((string) $n->reason, 'chiave principale diversa'));
});

it('senza indirizzi di amministrazione lo dice una volta sola', function (): void {
    $service = (new FakeKeyService)->install();
    config(['saas.crypto.vault' => 'remote', 'saas.staff_issuer.super_admins' => '']);
    $service->down = true;

    // Nessun indirizzo: lo dice, e non ripete il messaggio a ogni controllo.
    $this->artisan('saas:keys:monitor')->expectsOutputToContain('Nessun indirizzo di amministrazione')->assertFailed();
    $this->artisan('saas:keys:monitor')->doesntExpectOutputToContain('Nessun indirizzo di amministrazione')->assertFailed();
    Notification::assertNothingSent();
});

it('avvisa i super amministratori dello staff per identificativo della persona, solo se attivi', function (): void {
    $service = (new FakeKeyService)->install();
    config(['saas.crypto.vault' => 'remote', 'saas.staff_issuer.super_admins' => '']);
    $service->down = true;
    foreach ([['super@example.test', true, true], ['sospeso@example.test', false, true], ['semplice@example.test', true, false]] as [$email, $active, $super]) {
        $id = (string) Illuminate\Support\Str::uuid7();
        Illuminate\Support\Facades\DB::table('core.staff_people')->insert(['id' => $id, 'email' => $email, 'name' => 'Persona', 'active' => $active]);
        if ($super) {
            Illuminate\Support\Facades\DB::table('core.staff_super_admins')->insert(['person_id' => $id]);
        }
    }

    $this->artisan('saas:keys:monitor')->assertFailed();

    Notification::assertSentOnDemand(KeyServiceAlertNotification::class, fn (KeyServiceAlertNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === ['super@example.test']);
});

it('è nello scheduler ogni 5 minuti', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'saas:keys:monitor'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *');
});
