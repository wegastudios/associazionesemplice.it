<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('mostra la pagina di accesso con le intestazioni di sicurezza', function (): void {
    $response = $this->get('/accesso');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'", "frame-ancestors 'none'");
});

it('non ha la registrazione pubblica', function (): void {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['email' => 'x@example.com'])->assertNotFound();
});

it('rifiuta una password sbagliata con un messaggio che non rivela se l\'email esiste', function (): void {
    $this->createUser('anna@example.com');

    $wrong = $this->from('/accesso')->post('/accesso', ['email' => 'anna@example.com', 'password' => 'sbagliata']);
    $unknown = $this->from('/accesso')->post('/accesso', ['email' => 'nessuno@example.com', 'password' => 'sbagliata']);

    $wrong->assertRedirect('/accesso')->assertSessionHasErrors(['email' => 'Email o password non corrette.']);
    $unknown->assertSessionHasErrors(['email' => 'Email o password non corrette.']);
    $this->assertGuest();
});

it('blocca i tentativi ripetuti', function (): void {
    $this->createUser('anna@example.com');

    foreach (range(1, 5) as $ignored) {
        $this->post('/accesso', ['email' => 'anna@example.com', 'password' => 'sbagliata']);
    }

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD])->assertTooManyRequests();
    $this->assertGuest();
});

it('senza verifica in due passaggi entra ma il gestionale chiede di attivarla', function (): void {
    $user = $this->createUser('anna@example.com');
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user);

    $this->post('/accesso', ['email' => 'ANNA@example.com', 'password' => $this::PASSWORD])->assertRedirect('/gestionale');
    $this->assertAuthenticatedAs($user);

    $this->get('/gestionale')->assertRedirect('/account/verifica-in-due-passaggi');
    $this->get('/associazioni')->assertRedirect('/account/verifica-in-due-passaggi');
});

it('con la verifica in due passaggi chiede il codice prima di entrare', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD])->assertRedirect('/verifica-accesso');
    $this->assertGuest();

    $this->get('/verifica-accesso')->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));

    $this->post('/verifica-accesso', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post('/verifica-accesso', ['code' => $this->totp($user)])->assertRedirect('/gestionale');
    $this->assertAuthenticatedAs($user);
});

it('accetta un codice di recupero una sola volta', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD]);
    $this->post('/verifica-accesso', ['recovery_code' => 'codice-uno-1234'])->assertRedirect('/gestionale');
    $this->assertAuthenticatedAs($user);

    $this->post('/esci');
    $this->assertGuest();

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD]);
    $this->post('/verifica-accesso', ['recovery_code' => 'codice-uno-1234'])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('limita i tentativi sul codice', function (): void {
    $this->createUser('anna@example.com', twoFactor: true);
    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD]);

    foreach (range(1, 5) as $ignored) {
        $this->post('/verifica-accesso', ['code' => '000000']);
    }

    $this->post('/verifica-accesso', ['code' => '000000'])->assertTooManyRequests();
});

it('manda chi non è collegato alla pagina di accesso', function (): void {
    $this->get('/gestionale')->assertRedirect('/accesso');
    $this->get('/account/verifica-in-due-passaggi')->assertRedirect('/accesso');
    $this->getJson('/gestionale')->assertUnauthorized();
});
