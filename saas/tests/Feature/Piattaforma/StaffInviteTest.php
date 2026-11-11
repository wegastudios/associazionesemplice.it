<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Identity\StaffInvites;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
 * Inviti dello staff (docs/UTENTI_E_ACCESSI.md §4): la persona sceglie da sé la password dal link; l'invito vale una volta
 * sola e 7 giorni; si conserva solo l'impronta del codice. Dati di prova inventati.
 */

it('invita, fa scegliere la password una sola volta e assegna i ruoli', function (): void {
    $code = app(StaffInvites::class)->create('Persona@Example.test', 'Persona Prova', ['saas' => 'admin', 'wordpress' => 'vendita']);

    // Del codice resta solo l'impronta.
    expect(DB::table('core.staff_invites')->value('token_hash'))->toBe(hash('sha256', $code))
        ->and(DB::table('core.staff_invites')->where('token_hash', $code)->exists())->toBeFalse();

    $this->get('/saas/staff/invito/'.$code)->assertOk()->assertSee('Scegli la tua password')->assertSee('persona@example.test');
    $this->post('/saas/staff/invito/'.$code, ['password' => 'corta', 'password_confirmation' => 'corta'])->assertSee('almeno 12 caratteri');
    $this->post('/saas/staff/invito/'.$code, ['password' => 'una-password-lunga-1', 'password_confirmation' => 'una-password-lunga-2'])->assertSee('non coincidono');
    expect(DB::table('core.staff_people')->count())->toBe(0);

    $this->post('/saas/staff/invito/'.$code, ['password' => 'una-password-lunga-1', 'password_confirmation' => 'una-password-lunga-1'])->assertOk()->assertSee('Password impostata');

    $person = DB::table('core.staff_people')->where('email', 'persona@example.test')->first();
    expect($person)->not->toBeNull()
        ->and(Hash::check('una-password-lunga-1', (string) $person?->password))->toBeTrue()
        ->and($person?->active)->toBeTrue()
        ->and(DB::table('core.staff_roles')->where('person_id', $person?->id)->pluck('level', 'area')->all())->toBe(['saas' => 'admin', 'wordpress' => 'vendita'])
        ->and(DB::table('core.staff_audit')->whereIn('action', ['staff.people.invite', 'staff.people.accept'])->count())->toBe(2);

    // Una volta sola.
    $this->get('/saas/staff/invito/'.$code)->assertSee('Invito non valido');
    $this->post('/saas/staff/invito/'.$code, ['password' => 'un-altra-password-lunga', 'password_confirmation' => 'un-altra-password-lunga'])->assertSee('Invito non valido');
    expect(Hash::check('una-password-lunga-1', (string) DB::table('core.staff_people')->value('password')))->toBeTrue();
});

it('un invito scaduto o inventato non vale', function (): void {
    $code = app(StaffInvites::class)->create('altra@example.test', 'Altra Persona', ['supporto' => 'agente']);
    DB::table('core.staff_invites')->update(['expires_at' => now()->subMinute()]);

    $this->get('/saas/staff/invito/'.$code)->assertSee('Invito non valido');
    $this->get('/saas/staff/invito/'.str_repeat('a', 48))->assertSee('Invito non valido');
    $this->get('/saas/staff/invito/non-un-codice')->assertNotFound();
});

it('rifiuta email, ruoli e persone già con accesso', function (): void {
    $invites = app(StaffInvites::class);

    expect(fn () => $invites->create('non-una-email', 'Nome', ['saas' => 'admin']))->toThrow(InvalidArgumentException::class, 'Email')
        ->and(fn () => $invites->create('x@example.test', '', ['saas' => 'admin']))->toThrow(InvalidArgumentException::class, 'nome')
        ->and(fn () => $invites->create('x@example.test', 'X', ['saas' => 'agente']))->toThrow(InvalidArgumentException::class, 'Ruolo')
        ->and(fn () => $invites->create('x@example.test', 'X', ['altro' => 'admin']))->toThrow(InvalidArgumentException::class, 'Ruolo');

    $code = $invites->create('x@example.test', 'X', ['saas' => 'admin']);
    $this->post('/saas/staff/invito/'.$code, ['password' => 'una-password-lunga-1', 'password_confirmation' => 'una-password-lunga-1']);
    expect(fn () => $invites->create('x@example.test', 'X', ['saas' => 'admin']))->toThrow(InvalidArgumentException::class, 'già un accesso');
});

it('il comando manda il link per email e non lo stampa', function (): void {
    Mail::fake();
    $this->artisan('saas:staff:invite', ['email' => 'Nuova@Example.test', 'name' => 'Nuova Persona', '--role' => ['saas:admin', 'wordpress:admin']])
        ->expectsOutput('Invito mandato a nuova@example.test.')->assertSuccessful();

    expect(DB::table('core.staff_invites')->where('email', 'nuova@example.test')->count())->toBe(1);
    $this->artisan('saas:staff:invite', ['email' => 'rotta', 'name' => 'Nome', '--role' => ['saas:admin']])->assertFailed();
});
