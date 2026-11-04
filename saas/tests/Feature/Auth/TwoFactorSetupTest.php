<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use Inertia\Testing\AssertableInertia as Assert;

function confirmedPassword(): array
{
    return ['auth.password_confirmed_at' => time()];
}

it('chiede di confermare la password prima di aprire la pagina', function (): void {
    $user = $this->createUser('anna@example.com');

    $this->actingAs($user)->get('/account/verifica-in-due-passaggi')->assertRedirect('/conferma-password');
});

it('attiva e conferma la verifica in due passaggi, poi mostra i codici di recupero', function (): void {
    $user = $this->createUser('anna@example.com');
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user);
    $this->actingAs($user)->withSession(confirmedPassword());

    $this->get('/account/verifica-in-due-passaggi')
        ->assertInertia(fn (Assert $page) => $page->component('Account/TwoFactor')
            ->where('enabled', false)->where('required', true)->where('qrSvg', null));

    $this->post('/user/two-factor-authentication')->assertRedirect();

    $this->get('/account/verifica-in-due-passaggi')
        ->assertInertia(fn (Assert $page) => $page->where('enabled', true)->where('confirmed', false)
            ->where('qrSvg', fn (string $svg) => str_contains($svg, '<svg'))
            ->where('setupKey', fn (string $key) => strlen($key) >= 16)
            ->where('recoveryCodes', null));

    $this->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');

    $this->post('/user/confirmed-two-factor-authentication', ['code' => $this->totp($user)])
        ->assertSessionHas('status', 'two-factor-authentication-confirmed');

    $this->withSession(['status' => 'two-factor-authentication-confirmed'])
        ->get('/account/verifica-in-due-passaggi')
        ->assertInertia(fn (Assert $page) => $page->where('confirmed', true)->where('qrSvg', null)->has('recoveryCodes', 8));

    // Dopo la conferma i codici non si rivedono aprendo di nuovo la pagina.
    $this->withSession(['status' => null])->get('/account/verifica-in-due-passaggi')
        ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', null));

    $this->get('/gestionale')->assertOk();
});

it('non lascia disattivare la verifica a un operatore', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user, OrgRole::Accountant);

    $this->actingAs($user)->withSession(confirmedPassword())
        ->delete('/user/two-factor-authentication')
        ->assertSessionHasErrors('two_factor');

    expect($user->fresh()?->hasConfirmedTwoFactor())->toBeTrue();
});

it('lascia disattivare la verifica a chi non è operatore', function (): void {
    $user = $this->createUser('socio@example.com', twoFactor: true);

    $this->actingAs($user)->withSession(confirmedPassword())->delete('/user/two-factor-authentication');

    expect($user->fresh()?->two_factor_secret)->toBeNull();
});

it('non sostituisce un segreto già confermato', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $secret = $user->two_factor_secret;

    $this->actingAs($user)->withSession(confirmedPassword())->post('/user/two-factor-authentication', ['force' => true]);

    expect($user->fresh()?->two_factor_secret)->toBe($secret);
});

it('non si attiva senza conferma della password', function (): void {
    $user = $this->createUser('anna@example.com');

    $this->actingAs($user)->post('/user/two-factor-authentication')->assertRedirect('/conferma-password');
    expect($user->fresh()?->two_factor_secret)->toBeNull();
});
