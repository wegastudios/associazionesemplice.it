<?php

declare(strict_types=1);

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function profileUser(object $test): User
{
    $user = $test->createUser('anna@example.com', twoFactor: true);
    $test->addOperator($test->createOrg('Polisportiva Alfa'), $user);

    return $user;
}

it('mostra il profilo con nome, email e verifica in due passaggi', function (): void {
    $user = profileUser($this);

    $this->actingAs($user)->get('/gestionale/profilo')
        ->assertInertia(fn (Assert $page) => $page->component('Account/Profilo')
            ->where('profile.email', 'anna@example.com')->where('twoFactor', true));
});

it('cambia il nome', function (): void {
    $user = profileUser($this);

    $this->actingAs($user)->put('/gestionale/profilo/nome', ['name' => 'Anna Rossi'])->assertSessionHasNoErrors();
    expect($user->refresh()->name)->toBe('Anna Rossi');
});

it('cambia l\'email solo con la password giusta e non su un\'email già usata', function (): void {
    $user = profileUser($this);
    $this->createUser('bruno@example.com');

    $this->actingAs($user)->put('/gestionale/profilo/email', ['email' => 'nuova@example.com', 'password' => 'sbagliata'])
        ->assertSessionHasErrors('password');
    $this->put('/gestionale/profilo/email', ['email' => 'bruno@example.com', 'password' => $this::PASSWORD])
        ->assertSessionHasErrors('email');
    expect($user->refresh()->email)->toBe('anna@example.com');

    $this->put('/gestionale/profilo/email', ['email' => 'Nuova@Example.com', 'password' => $this::PASSWORD])->assertSessionHasNoErrors();
    expect($user->refresh()->email)->toBe('nuova@example.com');
});

it('scollega gli altri dispositivi tenendo questo', function (): void {
    $user = profileUser($this);
    DB::table('core.sessions')->insert(['id' => 'altra-sessione', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0 (Android) Chrome/120', 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($user)->post('/gestionale/profilo/dispositivi', ['password' => 'sbagliata'])->assertSessionHasErrors('password');
    expect(DB::table('core.sessions')->where('id', 'altra-sessione')->exists())->toBeTrue();

    $this->post('/gestionale/profilo/dispositivi', ['password' => $this::PASSWORD])->assertSessionHasNoErrors();
    expect(DB::table('core.sessions')->where('id', 'altra-sessione')->exists())->toBeFalse();
});

it('non è raggiungibile senza accesso', function (): void {
    $this->get('/gestionale/profilo')->assertRedirect('/accesso');
});
