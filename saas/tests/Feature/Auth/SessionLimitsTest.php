<?php

declare(strict_types=1);

use App\Modules\Core\Http\Middleware\EnforceSessionLimits;

it('scrive l\'orario dell\'accesso quando si entra', function (): void {
    $this->createUser('anna@example.com');

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD])->assertRedirect('/gestionale');

    $this->assertAuthenticated();
    $this->get('/account/verifica-in-due-passaggi')->assertSessionHas(EnforceSessionLimits::STARTED_AT);
});

it('fa uscire dopo 12 ore dall\'accesso anche se si è lavorato fino a un attimo prima', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)
        ->withSession([EnforceSessionLimits::STARTED_AT => now()->subHours(11)->getTimestamp()])
        ->get('/gestionale')->assertOk();

    $this->actingAs($user)
        ->withSession([EnforceSessionLimits::STARTED_AT => now()->subHours(12)->subMinute()->getTimestamp()])
        ->get('/gestionale')->assertRedirect('/accesso')->assertSessionHas('status', 'Per sicurezza accedi di nuovo con la password e il codice.');
    $this->assertGuest();
});

it('alla scadenza risponde 401 alle richieste JSON', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)
        ->withSession([EnforceSessionLimits::STARTED_AT => now()->subHours(13)->getTimestamp()])
        ->getJson('/gestionale')->assertUnauthorized();
});

it('non butta fuori chi era già collegato prima del controllo: la prima richiesta scrive l\'orario', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->get('/gestionale')->assertOk()->assertSessionHas(EnforceSessionLimits::STARTED_AT);
    $this->assertAuthenticatedAs($user);
});

it('con «ricordami» dura 14 giorni', function (): void {
    expect(config('auth.guards.web.remember'))->toBe(60 * 24 * 14);
});

it('rientrando dal cookie «ricordami» chi ha il secondo fattore deve rifare l\'accesso', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD, 'remember' => true]);
    $login = $this->post('/verifica-accesso', ['code' => $this->totp($user)])->assertRedirect('/gestionale');
    $name = collect($login->headers->getCookies())->first(static fn ($c): bool => str_starts_with($c->getName(), 'remember_'))?->getName();
    expect($name)->toBeString();
    $recaller = $login->getCookie($name);
    $token = $user->refresh()->remember_token;
    expect($token)->not->toBeNull();

    // Un browser nuovo: niente sessione, solo il cookie «ricordami».
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->withCookie($name, (string) $recaller?->getValue());

    // Chiede solo il codice: la sessione è chiusa, il cookie «ricordami» tolto, si riparte dalla verifica di Fortify.
    $this->get('/gestionale')->assertRedirect('/verifica-accesso')->assertCookieExpired($name)
        ->assertSessionHas('login.id', $user->id)->assertSessionHas('login.remember', true)
        ->assertSessionHas('status', 'Per sicurezza inserisci il codice dell\'app di autenticazione.');
    // Il token degli altri dispositivi resta com'è.
    expect($user->refresh()->remember_token)->toBe($token);

    // Il browser ha buttato il cookie: ora c'è solo la verifica del codice, senza password.
    $this->defaultCookies = [];
    $this->app['auth']->forgetGuards();
    $this->assertGuest();
    $this->post('/verifica-accesso', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();
    $this->post('/verifica-accesso', ['recovery_code' => 'codice-uno-1234'])->assertRedirect('/gestionale');
    $this->assertAuthenticatedAs($user);
});

it('chi non ha il secondo fattore rientra con «ricordami» senza altro', function (): void {
    $user = $this->createUser('anna@example.com');
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $login = $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD, 'remember' => true]);
    $name = collect($login->headers->getCookies())->first(static fn ($c): bool => str_starts_with($c->getName(), 'remember_'))?->getName();
    expect($name)->toBeString();
    $recaller = $login->getCookie($name);

    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->withCookie($name, (string) $recaller?->getValue());

    $this->get('/gestionale')->assertRedirect('/account/verifica-in-due-passaggi');
    $this->assertAuthenticatedAs($user);
});

it('limita «password dimenticata» per email e indirizzo', function (): void {
    foreach (range(1, 5) as $ignored) {
        $this->post('/password-dimenticata', ['email' => 'anna@example.com'])->assertStatus(302);
    }

    $this->post('/password-dimenticata', ['email' => 'anna@example.com'])->assertTooManyRequests();
    $this->post('/password-dimenticata', ['email' => 'altra@example.com'])->assertStatus(302);
});

it('limita «password dimenticata» anche per indirizzo con email diverse', function (): void {
    foreach (range(1, 20) as $i) {
        $this->post('/password-dimenticata', ['email' => "persona{$i}@example.com"])->assertStatus(302);
    }

    $this->post('/password-dimenticata', ['email' => 'ventunesima@example.com'])->assertTooManyRequests();
});

it('limita la conferma della password per persona', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->post('/conferma-password', ['password' => 'sbagliata'])->assertStatus(302);
    }

    $this->actingAs($user)->post('/conferma-password', ['password' => $this::PASSWORD])->assertTooManyRequests();
});

it('limita il cambio della password per persona', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->put('/user/password', ['current_password' => 'sbagliata', 'password' => 'x', 'password_confirmation' => 'y']);
    }

    $this->actingAs($user)->put('/user/password', ['current_password' => 'sbagliata', 'password' => 'x', 'password_confirmation' => 'y'])->assertTooManyRequests();
});
