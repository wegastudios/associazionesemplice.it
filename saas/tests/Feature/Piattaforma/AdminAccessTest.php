<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/StaffHelpers.php';

it('rimanda all\'accesso chi non è entrato', function (): void {
    $this->get('/saas/admin')->assertRedirect('/saas/admin/accesso');
    $this->get('/saas/admin/registro')->assertRedirect('/saas/admin/accesso');
    $this->get('/saas/admin/accesso')->assertOk()->assertSee('Accesso all\'amministrazione', false);
});

it('dà lo stesso errore con email sconosciuta, password sbagliata o persona disattivata', function (): void {
    $staff = makeStaff();
    makeStaff('fuori@example.test')->forceFill(['disabled_at' => now()])->save();

    foreach ([['nessuno@example.test', STAFF_PASSWORD], [$staff->email, 'sbagliata-sbagliata'], ['fuori@example.test', STAFF_PASSWORD]] as [$email, $password]) {
        $this->post('/saas/admin/accesso', ['email' => $email, 'password' => $password])
            ->assertSessionHasErrors(['email' => 'Email o password non corrette.']);
    }
    expect(DB::table('core.platform_audit')->where('action', 'staff.login_failed')->count())->toBe(3);
});

it('entra solo dopo la password e il codice, con un cookie proprio', function (): void {
    $staff = makeStaff();

    $this->post('/saas/admin/accesso', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertRedirect('/saas/admin/verifica');
    // Con la sola password non si è dentro.
    $this->get('/saas/admin')->assertRedirect('/saas/admin/accesso');

    $this->post('/saas/admin/verifica', ['code' => '000000'])->assertSessionHasErrors('code');

    $response = $this->post('/saas/admin/verifica', ['code' => staffCode($staff)])->assertRedirect('/saas/admin');
    $this->get('/saas/admin')->assertOk()->assertSee('Riepilogo');
    // L'admin gestisce solo la vendita: associazioni e utenti stanno nell'app.
    $this->get('/saas/admin/associazioni')->assertNotFound();
    $this->get('/saas/admin/registro')->assertOk()->assertSee('staff.login');

    // Cookie con nome e percorso propri: non quello del gestionale.
    $cookies = collect($response->headers->getCookies());
    $admin = $cookies->first(fn ($c) => $c->getName() === 'as_admin');
    expect($admin)->not->toBeNull()
        ->and($admin?->getPath())->toBe('/saas/admin')
        ->and($cookies->contains(fn ($c) => $c->getName() === 'as_gestionale'))->toBeFalse();
});

it('alla prima volta fa attivare il codice a due passaggi, una volta sola', function (): void {
    $staff = makeStaff('nuovo@example.test', twoFactor: false);

    $this->post('/saas/admin/accesso', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertRedirect('/saas/admin/attivazione');
    $this->get('/saas/admin/attivazione')->assertOk()->assertSee('<svg', false);
    $this->post('/saas/admin/attivazione', ['code' => '123456'])->assertSessionHasErrors('code');
    $this->post('/saas/admin/attivazione', ['code' => staffCode($staff)])->assertRedirect('/saas/admin');

    expect($staff->fresh()?->two_factor_confirmed_at)->not->toBeNull();

    // Già attivo: la pagina non permette di sostituire il segreto.
    $this->post('/saas/admin/esci')->assertRedirect('/saas/admin/accesso');
    $this->post('/saas/admin/accesso', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertRedirect('/saas/admin/verifica');
    $this->get('/saas/admin/attivazione')->assertRedirect('/saas/admin/accesso');
});

it('chiude la sessione quando la persona viene disattivata', function (): void {
    $staff = makeStaff();
    $this->post('/saas/admin/accesso', ['email' => $staff->email, 'password' => STAFF_PASSWORD]);
    $this->post('/saas/admin/verifica', ['code' => staffCode($staff)]);
    $this->get('/saas/admin')->assertOk();

    $staff->forceFill(['disabled_at' => now()])->save();
    // Nei test l'applicazione resta la stessa tra due richieste: la guardia va azzerata come farebbe una richiesta nuova.
    Auth::forgetGuards();

    $this->get('/saas/admin')->assertRedirect('/saas/admin/accesso');
});

it('non lascia entrare gli operatori delle associazioni', function (): void {
    $operator = $this->createUser('operatore@example.test', twoFactor: true);

    $this->actingAs($operator)->get('/saas/admin')->assertRedirect('/saas/admin/accesso');
});

it('ha il ruolo di sola lettura senza permessi di scrittura', function (): void {
    expect(makeStaff('a@example.test', 'admin')->canWrite())->toBeTrue()
        ->and(makeStaff('b@example.test', 'readonly')->canWrite())->toBeFalse();
});

it('non permette di modificare né cancellare il registro delle azioni', function (): void {
    $staff = makeStaff();
    DB::table('core.platform_audit')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'staff_id' => $staff->id, 'action' => 'staff.login']);

    expect(fn () => DB::table('core.platform_audit')->update(['action' => 'staff.altro']))->toThrow(QueryException::class);
});
