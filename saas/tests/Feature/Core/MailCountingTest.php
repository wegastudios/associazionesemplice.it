<?php

declare(strict_types=1);

use App\Modules\Core\Auth\ResetPasswordNotification;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\QueuedMail;
use App\Modules\Core\Contracts\ThrottledMail;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Message;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * Posta contata e mai persa: ogni messaggio partito consuma la quota dell'ora (un invio per destinatario, anche fuori dalla
 * coda); chi aspetta un codice lo riceve subito se c'è quota, altrimenti il messaggio va in coda invece di perdersi; il
 * codice non finisce mai in chiaro nei log, nella coda o tra i lavori falliti.
 */

const MC_CODE = 'https://example.test/area/entra/CODICE-SEGRETO-482913';

beforeEach(function (): void {
    config(['mail.default' => 'array']);
});

function mcSent(): int
{
    return MailQueue::hourUsage('array')[0] ?? 0;
}

function mcRaw(): QueuedMail
{
    return QueuedMail::raw('mario@example.com', 'Il tuo link', 'Apri '.MC_CODE, MailQueue::URGENT, now()->addMinutes(15));
}

it('conta ogni messaggio partito, un invio per destinatario, anche fuori dalla coda', function (): void {
    Mail::raw('Ciao', static fn (Message $m) => $m->to('uno@example.com')->subject('Uno'));
    expect(mcSent())->toBe(1);

    Mail::raw('Ciao', static fn (Message $m) => $m->to(['due@example.com', 'tre@example.com'])->bcc('quattro@example.com')->subject('Tre'));
    expect(mcSent())->toBe(4);
});

it('un invio prenotato non si conta due volte; i destinatari in più sì', function (): void {
    expect(MailQueue::reserve(MailQueue::URGENT))->toBeTrue();
    MailQueue::reserved(MailQueue::URGENT, static fn () => Mail::raw('Ciao', static fn (Message $m) => $m->to('uno@example.com')->subject('Uno')));
    expect(mcSent())->toBe(1);

    expect(MailQueue::reserve(MailQueue::URGENT))->toBeTrue();
    MailQueue::reserved(MailQueue::URGENT, static fn () => Mail::raw('Ciao', static fn (Message $m) => $m->to(['a@example.com', 'b@example.com', 'c@example.com'])->subject('Tre')));
    expect(mcSent())->toBe(4);

    // Fuori dalla prenotazione si torna a contare tutto.
    Mail::raw('Ciao', static fn (Message $m) => $m->to('dopo@example.com')->subject('Dopo'));
    expect(mcSent())->toBe(5);
});

it('un lavoro di posta massiva conta i destinatari in più anche sul tetto dei massivi', function (): void {
    $job = QueuedMail::raw('uno@example.com', 'Avviso', 'Testo', MailQueue::BULK, now()->addHour())->withFakeQueueInteractions();
    (new ThrottledMail)->handle($job, static fn () => Mail::raw('Ciao', static fn (Message $m) => $m->to(['a@example.com', 'b@example.com'])->subject('Due')));

    expect(mcSent())->toBe(2)
        ->and(RateLimiter::attempts('mail-bulk|array|3600'))->toBe(2);
});

it('un messaggio urgente parte subito se c\'è quota', function (): void {
    Queue::fake();

    expect(MailQueue::sendNowOrQueue(mcRaw()))->toBeTrue();
    Queue::assertNothingPushed();
    expect(mcSent())->toBe(1);
});

it('con la quota dell\'ora finita o la posta ferma l\'urgente va in coda, cifrato e fino alla scadenza del codice', function (): void {
    $this->freezeTime();
    Queue::fake();
    RateLimiter::increment('mail-all|array|3600', 3600, 140);

    expect(MailQueue::sendNowOrQueue(mcRaw()))->toBeFalse();
    Queue::assertPushedOn(MailQueue::URGENT, QueuedMail::class, static fn (QueuedMail $job): bool => $job->retryUntil()->getTimestamp() === now()->addMinutes(15)->getTimestamp());
    expect(mcSent())->toBe(140);

    RateLimiter::clear('mail-all|array|3600');
    MailQueue::block('array');
    expect(MailQueue::sendNowOrQueue(mcRaw()))->toBeFalse();
    Queue::assertPushed(QueuedMail::class, 2);
});

it('un rifiuto del server torna in coda e non si perde; la posta si ferma salvo che il problema sia il destinatario', function (): void {
    Queue::fake();

    $sent = MailQueue::sendNowOrQueue(mcRaw(), static fn () => throw new TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 <mario@example.com>: Recipient address rejected".', 550));
    expect($sent)->toBeFalse()->and(MailQueue::blockedUntil('array'))->toBeNull();
    Queue::assertPushed(QueuedMail::class, 1);

    $sent = MailQueue::sendNowOrQueue(mcRaw(), static fn () => throw new TransportException('Expected response code "250" but got code "550", with message "550 Domain example.test has exceeded the max emails per hour".', 550));
    expect($sent)->toBeFalse()->and(MailQueue::blockedUntil('array'))->not->toBeNull();
    Queue::assertPushed(QueuedMail::class, 2);
});

it('il codice non finisce nei log, nella coda né tra i lavori falliti', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    Queue::fake();
    MailQueue::sendNowOrQueue(mcRaw(), static fn () => throw new TransportException('Expected response code "250" but got code "421", with message "421 riprova più tardi".', 421));

    expect($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->not->toContain('CODICE-SEGRETO')->not->toContain('mario@example.com');

    // Quello che va nella coda (e, se fallisce, tra i lavori falliti) è cifrato.
    $queue = new SyncQueue;
    $queue->setContainer(app());
    $payload = (fn () => $this->createPayload(mcRaw(), MailQueue::URGENT))->call($queue);
    expect(mcRaw())->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldBeEncrypted::class)
        ->and($payload)->not->toContain('CODICE-SEGRETO')->not->toContain('mario@example.com');
});

it('il link per la password con la quota finita va in coda, con la stessa risposta che l\'email esista o no', function (): void {
    Notification::fake();
    Queue::fake();
    $user = $this->createUser('anna@example.com');
    RateLimiter::increment('mail-all|array|3600', 3600, 140);

    $known = $this->from('/password-dimenticata')->post('/password-dimenticata', ['email' => 'anna@example.com']);
    $unknown = $this->from('/password-dimenticata')->post('/password-dimenticata', ['email' => 'nessuno@example.com']);

    Notification::assertNothingSent();
    Queue::assertPushedOn(MailQueue::URGENT, QueuedMail::class);
    Queue::assertPushed(QueuedMail::class, 1);
    expect($known->getSession()?->get('status'))->toBe($unknown->getSession()?->get('status'));

    // Il lavoro, quando parte, manda il link alla persona giusta.
    Queue::pushed(QueuedMail::class)->first()->handle();
    Notification::assertSentTo($user, ResetPasswordNotification::class);
});
