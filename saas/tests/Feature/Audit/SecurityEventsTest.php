<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @return list<string> */
function securityEvents(): array
{
    return DB::table('core.security_events')->orderBy('occurred_at')->orderBy('id')->pluck('event')->all();
}

it('annota accessi riusciti, falliti e le uscite', function (): void {
    $user = $this->createUser('anna@example.com');

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => 'sbagliata']);
    $this->post('/accesso', ['email' => 'nessuno@example.com', 'password' => 'sbagliata']);
    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD]);
    $this->post('/esci');

    expect(securityEvents())->toBe(['auth.failed', 'auth.failed', 'auth.login', 'auth.logout']);

    $rows = DB::table('core.security_events')->orderBy('occurred_at')->orderBy('id')->get();
    expect($rows[0]->user_id)->toBe($user->id)
        ->and($rows[1]->user_id)->toBeNull()
        ->and($rows[2]->ip)->toBe('127.0.0.1')
        ->and(json_encode($rows))->not->toContain('nessuno@example.com');
});

it('annota la verifica in due passaggi: sfida, codice sbagliato, codice di recupero', function (): void {
    $this->createUser('anna@example.com', twoFactor: true);

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD]);
    $this->post('/verifica-accesso', ['code' => '000000']);
    $this->post('/verifica-accesso', ['recovery_code' => 'codice-uno-1234']);

    expect(securityEvents())->toBe(['two_factor.challenged', 'two_factor.failed', 'two_factor.recovery_code_used', 'auth.login']);
});

it('annota il blocco dopo troppi tentativi', function (): void {
    $this->createUser('anna@example.com');
    foreach (range(1, 6) as $ignored) {
        $this->post('/accesso', ['email' => 'anna@example.com', 'password' => 'sbagliata']);
    }

    expect(securityEvents())->toContain('auth.lockout');
});

it('non lascia modificare gli eventi di sicurezza', function (): void {
    $this->createUser('anna@example.com');
    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => 'sbagliata']);

    expect(fn () => DB::transaction(fn () => DB::table('core.security_events')->delete()))
        ->toThrow(QueryException::class, 'permission denied');
});
