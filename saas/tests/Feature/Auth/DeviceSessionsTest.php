<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

function otherSession(string $id, string $userId): void
{
    DB::table('core.sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0 (Android) Chrome/120', 'payload' => '', 'last_activity' => time()]);
}

it('chiude gli altri accessi e cambia il token «ricordami» quando si cambia la password', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $user->forceFill(['remember_token' => 'token-vecchio'])->save();
    otherSession('altra-sessione', $user->id);

    $this->actingAs($user)->put('/user/password', [
        'current_password' => $this::PASSWORD,
        'password' => 'la-mia-password-nuova-2026',
        'password_confirmation' => 'la-mia-password-nuova-2026',
    ])->assertSessionHasNoErrors();

    expect(DB::table('core.sessions')->where('id', 'altra-sessione')->exists())->toBeFalse()
        ->and($user->refresh()->remember_token)->not->toBe('token-vecchio')->not->toBeNull();
});

it('chiude tutti gli accessi e cambia il token «ricordami» quando si reimposta la password', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $user->forceFill(['remember_token' => 'token-vecchio'])->save();
    otherSession('sessione-uno', $user->id);
    otherSession('sessione-due', $user->id);
    $altro = $this->createUser('luca@example.com');
    otherSession('sessione-altrui', $altro->id);

    $this->post('/reimposta-password', [
        'token' => Password::createToken($user),
        'email' => 'anna@example.com',
        'password' => 'la-mia-password-nuova-2026',
        'password_confirmation' => 'la-mia-password-nuova-2026',
    ])->assertRedirect('/accesso');

    expect(DB::table('core.sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('core.sessions')->where('id', 'sessione-altrui')->exists())->toBeTrue()
        ->and($user->refresh()->remember_token)->not->toBe('token-vecchio');
});

it('cambia il token «ricordami» anche scollegando i dispositivi', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    $user->forceFill(['remember_token' => 'token-vecchio'])->save();

    $this->actingAs($user)->post('/gestionale/profilo/dispositivi', ['password' => $this::PASSWORD])->assertSessionHasNoErrors();

    expect($user->refresh()->remember_token)->not->toBe('token-vecchio')->not->toBeNull();
});

it('chiede di riconfermare la password per cambiare operatori e ruoli', function (): void {
    $owner = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $owner);

    $this->actingAs($owner)->post('/gestionale/operatori', ['email' => 'carlo@example.com', 'name' => 'Carlo', 'role' => 'operator'])->assertRedirect('/conferma-password');
    $this->post('/gestionale/ruoli', ['name' => 'Consigliere', 'abilities' => ['people.view']])->assertRedirect('/conferma-password');
    expect(DB::table('core.org_roles')->count())->toBe(0);
});
