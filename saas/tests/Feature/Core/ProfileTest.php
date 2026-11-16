<?php

declare(strict_types=1);

use App\Modules\Core\Auth\EmailChangeNoticeNotification;
use App\Modules\Core\Auth\EmailChangeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

it('mostra il profilo con nome, email e verifica in due passaggi', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->get('/gestionale/profilo')
        ->assertInertia(fn (Assert $page) => $page->component('Account/Profilo')
            ->where('profile.email', 'anna@example.com')->where('twoFactor', true));
});

it('cambia il nome', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->put('/gestionale/profilo/nome', ['name' => 'Anna Rossi'])->assertSessionHasNoErrors();
    expect($user->refresh()->name)->toBe('Anna Rossi');
});

it('scollega gli altri dispositivi tenendo questo', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    DB::table('core.sessions')->insert(['id' => 'altra-sessione', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0 (Android) Chrome/120', 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($user)->post('/gestionale/profilo/dispositivi', ['password' => 'sbagliata'])->assertSessionHasErrors('password');
    expect(DB::table('core.sessions')->where('id', 'altra-sessione')->exists())->toBeTrue();

    $this->post('/gestionale/profilo/dispositivi', ['password' => $this::PASSWORD])->assertSessionHasNoErrors();
    expect(DB::table('core.sessions')->where('id', 'altra-sessione')->exists())->toBeFalse();
});

it('non è raggiungibile senza accesso', function (): void {
    $this->get('/gestionale/profilo')->assertRedirect('/accesso');
});

it('cambia l\'email solo dopo il link aperto dal nuovo indirizzo, con la password giusta', function (): void {
    Notification::fake();
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->put('/gestionale/profilo/email', ['email' => 'nuova@example.com', 'password' => 'sbagliata'])->assertSessionHasErrors('password');
    $this->put('/gestionale/profilo/email', ['email' => 'anna@example.com', 'password' => $this::PASSWORD])->assertSessionHasErrors('email');
    Notification::assertNothingSent();

    $this->put('/gestionale/profilo/email', ['email' => 'Nuova@Example.com', 'password' => $this::PASSWORD])->assertSessionHasNoErrors()->assertSessionHas('status');
    expect($user->refresh()->email)->toBe('anna@example.com');
    $url = null;
    Notification::assertSentOnDemand(EmailChangeNotification::class, function (EmailChangeNotification $n, array $channels, object $notifiable) use (&$url): bool {
        $url = $n->url;

        return ($notifiable->routes['mail'] ?? null) === 'nuova@example.com';
    });
    Notification::assertSentTo($user, EmailChangeNoticeNotification::class, fn (EmailChangeNoticeNotification $n): bool => $n->maskedNew === 'n***@e***.com');

    $this->get($url)->assertRedirect('/gestionale/profilo')->assertSessionHas('status', 'Email aggiornata.');
    expect($user->refresh()->email)->toBe('nuova@example.com')->and($user->email_verified_at)->not->toBeNull();
});

it('il link del cambio email vale solo per chi l\'ha chiesto: aperto da un altro utente non cambia nulla', function (): void {
    Notification::fake();
    $anna = $this->createUser('anna@example.com', twoFactor: true);
    $bruno = $this->createUser('bruno@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $anna);
    $this->addOperator($this->createOrg('Polisportiva Beta'), $bruno);

    $this->actingAs($anna)->put('/gestionale/profilo/email', ['email' => 'altro@example.com', 'password' => $this::PASSWORD])->assertSessionHasNoErrors();
    $url = null;
    Notification::assertSentOnDemand(EmailChangeNotification::class, function (EmailChangeNotification $n) use (&$url): bool {
        $url = $n->url;

        return true;
    });

    $this->actingAs($bruno)->get($url)->assertSessionHasErrors('ledger');
    expect($bruno->refresh()->email)->toBe('bruno@example.com')->and($anna->refresh()->email)->toBe('anna@example.com');
});

it('l\'email di un altro non si prende: stessa risposta, nessun link, e il link manomesso non vale', function (): void {
    Notification::fake();
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    $this->createUser('bruno@example.com');

    $this->actingAs($user)->put('/gestionale/profilo/email', ['email' => 'bruno@example.com', 'password' => $this::PASSWORD])
        ->assertSessionHasNoErrors()->assertSessionHas('status');
    Notification::assertNothingSent();

    $this->get('/gestionale/profilo/email/conferma?email=bruno@example.com')->assertSessionHasErrors('ledger');
    $this->get(URL::temporarySignedRoute('profile.email.confirm', now()->addMinutes(60), ['email' => 'bruno@example.com']))
        ->assertSessionHasErrors('ledger');
    $this->get(URL::temporarySignedRoute('profile.email.confirm', now()->subMinute(), ['email' => 'libera@example.com']))
        ->assertSessionHasErrors('ledger');
    expect($user->refresh()->email)->toBe('anna@example.com');
});
