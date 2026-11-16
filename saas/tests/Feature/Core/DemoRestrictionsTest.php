<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\DemoScale;

dataset('scritture della demo', [
    'password' => ['put', '/user/password'],
    'due passaggi' => ['post', '/user/two-factor-authentication'],
    'email del profilo' => ['put', '/gestionale/profilo/email'],
    'altri dispositivi' => ['post', '/gestionale/profilo/dispositivi'],
    'invito operatore' => ['post', '/gestionale/operatori'],
    'ruolo di un operatore' => ['put', '/gestionale/operatori/0190a8e0-0000-7000-8000-000000000001'],
    'ruolo personalizzato' => ['post', '/gestionale/ruoli'],
    'chiavi dei pagamenti' => ['put', '/gestionale/pagamenti-online'],
    'prova dei pagamenti' => ['post', '/gestionale/pagamenti-online/prova/stripe'],
    'dati dell\'ente' => ['post', '/gestionale/configurazione'],
]);

it('nella demo rifiuta le scritture che toccherebbero tutti i visitatori', function (string $method, string $url): void {
    config(['saas.demo.enabled' => true]);
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->$method($url, ['email' => 'altra@example.com', 'password' => $this::PASSWORD])
        ->assertSessionHasErrors(['ledger' => DemoScale::CHANGE_OFF]);
    expect($user->refresh()->email)->toBe('titolare@example.com');
})->with('scritture della demo');

it('nella demo il resto del profilo e la lettura delle stesse pagine restano liberi', function (): void {
    config(['saas.demo.enabled' => true]);
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->put('/gestionale/profilo/nome', ['name' => 'Anna Rossi'])->assertSessionHasNoErrors();
    $this->get('/gestionale/operatori')->assertOk();
    $this->get('/gestionale/pagamenti-online')->assertOk();
    expect($user->refresh()->name)->toBe('Anna Rossi');
});

it('fuori dalla demo non cambia nulla', function (): void {
    config(['saas.demo.enabled' => false]);
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->put('/gestionale/profilo/email', ['email' => 'nuova@example.com', 'password' => $this::PASSWORD])->assertSessionHasNoErrors();
    expect($user->refresh()->email)->toBe('nuova@example.com');
});
