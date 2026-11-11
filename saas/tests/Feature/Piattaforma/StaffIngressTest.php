<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Identity\StaffDirectory;
use App\Modules\Piattaforma\Identity\StaffPerson;
use App\Modules\Piattaforma\Identity\StaffTokenIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Ingresso di /saas/admin con l'accesso unico: la pagina di accesso è /accedi del portale; il SaaS riceve il token
 * `staff_sso` che ha firmato lui stesso. Dati di prova inventati.
 */

beforeEach(function (): void {
    config([
        'saas.unified_login.enabled' => true,
        'saas.staff_sso.enabled' => true,
        'saas.staff_issuer.enabled' => true,
        'saas.staff_issuer.signing_key' => base64_encode(random_bytes(32)),
        'saas.staff_issuer.super_admins' => '',
        'saas.public_url' => 'https://associazionesemplice.it',
    ]);
});

/** @param  array<string, string>  $roles */
function ingressPerson(array $roles = ['saas' => 'vendita']): StaffPerson
{
    $id = (string) Str::uuid7();
    DB::table('core.staff_people')->insert(['id' => $id, 'email' => 'persona@example.test', 'name' => 'Persona di prova', 'password' => Hash::make('una-password-di-prova-lunga'), 'active' => true]);
    foreach ($roles as $area => $level) {
        DB::table('core.staff_roles')->insert(['person_id' => $id, 'area' => $area, 'level' => $level]);
    }

    return new StaffPerson($id, 'persona@example.test', 'Persona di prova');
}

/** Il token che il portale porterebbe a /saas/admin/sso dopo il passaggio dalla pagina /account. */
function ingressToken(StaffPerson $person, ?string $sid = null): string
{
    $directory = app(StaffDirectory::class);
    $sid ??= $directory->openSession($person, '203.0.113.7');

    return app(StaffTokenIssuer::class)->issue($person, 'saas', $sid, $directory->areas($person));
}

it('i vecchi moduli di accesso rinviano a /accedi del portale', function (): void {
    $this->get('/saas/admin/accesso')->assertRedirect('https://associazionesemplice.it/accedi');
    $this->post('/saas/admin/accesso', ['email' => 'a@example.test', 'password' => 'x'])->assertRedirect('https://associazionesemplice.it/accedi');
    // La pagina provvisoria dentro /saas non esiste più.
    $this->get('/saas/staff/accedi')->assertNotFound();
    $this->get('/saas/staff/password-dimenticata')->assertNotFound();
});

it('con il flag spento il modulo di accesso locale resta', function (): void {
    config(['saas.unified_login.enabled' => false]);

    $this->get('/saas/admin/accesso')->assertOk()->assertSee('Accesso all\'amministrazione', false);
});

it('si entra in /saas/admin con il token firmato dal SaaS stesso, una volta sola', function (): void {
    $token = ingressToken(ingressPerson());

    $this->post('/saas/admin/sso', ['token' => $token])->assertOk()->assertSee('/saas/admin');
    $this->get('/saas/admin')->assertOk();

    // Monouso, e un token manomesso non entra.
    $this->post('/saas/admin/sso', ['token' => $token])->assertForbidden();
    $this->post('/saas/admin/sso', ['token' => $token.'x'])->assertForbidden();
});

it('se la sessione centrale è chiusa o la persona non ha il ruolo il token non entra', function (): void {
    $person = ingressPerson();
    $token = ingressToken($person);
    DB::table('core.staff_sessions')->update(['revoked_at' => now()]);
    $this->post('/saas/admin/sso', ['token' => $token])->assertForbidden();

    // Tolto il ruolo SaaS: anche con la sessione valida il livello non c'è più.
    $other = ingressToken($person);
    DB::table('core.staff_roles')->where('person_id', $person->id)->delete();
    $this->post('/saas/admin/sso', ['token' => $other])->assertForbidden();
});

it('un token firmato con un\'altra chiave non entra', function (): void {
    $token = ingressToken(ingressPerson());
    config(['saas.staff_issuer.signing_key' => base64_encode(random_bytes(32))]);

    $this->post('/saas/admin/sso', ['token' => $token])->assertForbidden();
});
