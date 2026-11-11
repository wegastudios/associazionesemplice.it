<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Identity\StaffAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/*
 * Accesso unico dello staff, passo 2 (docs/LOGIN_UNICO_E_DATABASE.md): una pagina, codice solo se attivo, recupero
 * password unico. Dati di prova inventati.
 */

const LOGIN_PASSWORD = 'una-password-di-prova-lunga';

beforeEach(function (): void {
    $seed = random_bytes(32);
    config([
        'saas.unified_login.enabled' => true,
        'saas.staff_issuer.enabled' => true,
        'saas.staff_issuer.signing_key' => base64_encode($seed),
        'saas.staff_issuer.super_admins' => '',
        'saas.public_url' => 'https://associazionesemplice.it',
    ]);
    RateLimiter::clear('staff-login|persona@example.test|127.0.0.1');
});

/** @param  array<string, string>  $roles */
function loginPerson(string $email = 'persona@example.test', array $roles = ['saas' => 'admin', 'wordpress' => 'vendita'], bool $twoFactor = false): string
{
    $id = (string) Str::uuid7();
    DB::table('core.staff_people')->insert([
        'id' => $id, 'email' => $email, 'name' => 'Persona di prova', 'password' => Hash::make(LOGIN_PASSWORD), 'active' => true,
        'two_factor_secret' => $twoFactor ? Fortify::currentEncrypter()->encrypt((new Google2FA)->generateSecretKey()) : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
    ]);
    foreach ($roles as $area => $level) {
        DB::table('core.staff_roles')->insert(['person_id' => $id, 'area' => $area, 'level' => $level]);
    }

    return $id;
}

function loginCode(string $id): string
{
    $secret = Fortify::currentEncrypter()->decrypt((string) DB::table('core.staff_people')->where('id', $id)->value('two_factor_secret'));

    return (new Google2FA)->getCurrentOtp(is_string($secret) ? $secret : '');
}

it('resta spenta (404) finché il flag non è acceso, e i vecchi moduli non rinviano', function (): void {
    config(['saas.unified_login.enabled' => false]);

    $this->get('/saas/staff/accedi')->assertNotFound();
    $this->get('/saas/staff/password-dimenticata')->assertNotFound();
    $this->get('/saas/admin/accesso')->assertOk()->assertSee('Accesso all\'amministrazione', false);
});

it('con il flag acceso i vecchi moduli di accesso rinviano alla pagina unica', function (): void {
    $this->get('/saas/admin/accesso')->assertRedirect('/saas/staff/accedi');
    $this->post('/saas/admin/accesso', ['email' => 'a@example.test', 'password' => 'x'])->assertRedirect('/saas/staff/accedi');
});

it('senza secondo livello entra con email e password e sceglie l\'area', function (): void {
    loginPerson();

    $this->get('/saas/staff/accedi')->assertOk()->assertSee('Password dimenticata?');
    $response = $this->post('/saas/staff/accedi', ['email' => 'persona@example.test', 'password' => LOGIN_PASSWORD])->assertRedirect('/saas/staff/aree');
    $this->get('/saas/staff/aree')->assertOk()->assertSee('Vendita del plugin')->assertSee('Amministrazione SaaS');

    // Cookie proprio, con percorso proprio.
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'as_staff');
    expect($cookie)->not->toBeNull()->and($cookie?->getPath())->toBe('/saas/staff')
        ->and(DB::table('core.staff_sessions')->count())->toBe(1)
        ->and(DB::table('core.staff_audit')->where('action', 'staff.login')->count())->toBe(1);
});

it('dà lo stesso errore con email sconosciuta, password sbagliata o persona disattivata', function (): void {
    $id = loginPerson();
    loginPerson('fuori@example.test');
    DB::table('core.staff_people')->where('email', 'fuori@example.test')->update(['active' => false]);

    foreach ([['nessuno@example.test', LOGIN_PASSWORD], ['persona@example.test', 'sbagliata-sbagliata'], ['fuori@example.test', LOGIN_PASSWORD]] as [$email, $password]) {
        $this->post('/saas/staff/accedi', ['email' => $email, 'password' => $password])->assertSessionHasErrors(['email' => 'Email o password non corrette.']);
    }
    expect(DB::table('core.staff_sessions')->where('person_id', $id)->count())->toBe(0);
    $this->get('/saas/staff/aree')->assertRedirect('/saas/staff/accedi');
});

it('chiede il codice solo a chi lo ha attivato e senza il codice non si entra', function (): void {
    $id = loginPerson(twoFactor: true);

    $this->post('/saas/staff/accedi', ['email' => 'persona@example.test', 'password' => LOGIN_PASSWORD])->assertRedirect('/saas/staff/codice');
    $this->get('/saas/staff/aree')->assertRedirect('/saas/staff/accedi');
    $this->get('/saas/staff/codice')->assertOk()->assertSee('Codice di verifica');

    $this->post('/saas/staff/codice', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post('/saas/staff/codice', ['code' => loginCode($id)])->assertRedirect('/saas/staff/aree');
    $this->get('/saas/staff/aree')->assertOk();
});

it('la pagina del codice non si apre senza aver messo la password', function (): void {
    $this->get('/saas/staff/codice')->assertRedirect('/saas/staff/accedi');
    $this->post('/saas/staff/codice', ['code' => '123456'])->assertRedirect('/saas/staff/accedi');
});

it('il passaggio a un\'area dà un token firmato all\'indirizzo fisso dell\'area, solo se la persona ha quel ruolo', function (): void {
    loginPerson(roles: ['saas' => 'vendita']);
    $this->post('/saas/staff/accedi', ['email' => 'persona@example.test', 'password' => LOGIN_PASSWORD]);

    $page = $this->post('/saas/staff/vai', ['area' => 'saas'])->assertOk()
        ->assertSee('action="https://associazionesemplice.it/saas/admin/sso"', false);
    expect($page->getContent())->toMatch('/name="token" value="[\w-]+\.[\w-]+\.[\w-]+"/');

    // Un'area senza ruolo (o inventata) rinvia all'accesso, senza token.
    $this->post('/saas/staff/vai', ['area' => 'wordpress'])->assertRedirect('/saas/staff/accedi');
    $this->post('/saas/staff/vai', ['area' => 'https://attaccante.example'])->assertRedirect('/saas/staff/accedi');
});

it('esce chiudendo la sessione centrale', function (): void {
    loginPerson();
    $this->post('/saas/staff/accedi', ['email' => 'persona@example.test', 'password' => LOGIN_PASSWORD]);

    $this->post('/saas/staff/esci')->assertRedirect('/saas/staff/accedi');
    expect(DB::table('core.staff_sessions')->whereNull('revoked_at')->count())->toBe(0);
    $this->get('/saas/staff/aree')->assertRedirect('/saas/staff/accedi');
});

it('il recupero password risponde sempre allo stesso modo e scrive solo a chi esiste', function (): void {
    Mail::fake();
    loginPerson();

    $known = $this->post('/saas/staff/password-dimenticata', ['email' => 'persona@example.test'])->assertOk()->getContent();
    $unknown = $this->post('/saas/staff/password-dimenticata', ['email' => 'nessuno@example.test'])->assertOk()->getContent();

    expect(preg_replace('/value="[^"]*"/', '', (string) $known))->toBe(preg_replace('/value="[^"]*"/', '', (string) $unknown))
        ->and(DB::table('core.staff_resets')->count())->toBe(1);
});

it('con il link sceglie una nuova password una volta sola e chiude gli accessi aperti', function (): void {
    Mail::fake();
    $id = loginPerson();
    $this->post('/saas/staff/accedi', ['email' => 'persona@example.test', 'password' => LOGIN_PASSWORD]);
    expect(DB::table('core.staff_sessions')->whereNull('revoked_at')->count())->toBe(1);

    app(StaffAccess::class)->requestReset('persona@example.test', 'https://associazionesemplice.it/saas', '127.0.0.1');
    // Il codice non si legge dal database (c'è solo l'impronta): se ne chiede uno e lo si prova con quello vero.
    $code = bin2hex(random_bytes(24));
    DB::table('core.staff_resets')->where('person_id', $id)->update(['token_hash' => hash('sha256', $code)]);

    $this->get('/saas/staff/reimposta/'.$code)->assertOk()->assertSee('Scegli la nuova password');
    $this->post('/saas/staff/reimposta/'.$code, ['password' => 'corta', 'password_confirmation' => 'corta'])->assertSee('almeno 12 caratteri');
    $this->post('/saas/staff/reimposta/'.$code, ['password' => 'una-password-lunga-1', 'password_confirmation' => 'una-password-lunga-2'])->assertSee('non coincidono');
    $this->post('/saas/staff/reimposta/'.$code, ['password' => 'una-nuova-password-1', 'password_confirmation' => 'una-nuova-password-1'])->assertSee('Password cambiata');

    expect(Hash::check('una-nuova-password-1', (string) DB::table('core.staff_people')->where('id', $id)->value('password')))->toBeTrue()
        ->and(DB::table('core.staff_sessions')->whereNull('revoked_at')->count())->toBe(0);
    // Una volta sola.
    $this->get('/saas/staff/reimposta/'.$code)->assertSee('Link non valido');
    $this->post('/saas/staff/reimposta/'.$code, ['password' => 'un-altra-password-1', 'password_confirmation' => 'un-altra-password-1'])->assertSee('Link non valido');
    expect(Hash::check('una-nuova-password-1', (string) DB::table('core.staff_people')->where('id', $id)->value('password')))->toBeTrue();
});

it('un link scaduto non vale e un nuovo link annulla il precedente', function (): void {
    Mail::fake();
    $id = loginPerson();
    $access = app(StaffAccess::class);
    $access->requestReset('persona@example.test', 'https://associazionesemplice.it/saas', null);
    $access->requestReset('persona@example.test', 'https://associazionesemplice.it/saas', null);

    expect(DB::table('core.staff_resets')->where('person_id', $id)->whereNull('used_at')->count())->toBe(1);

    $code = bin2hex(random_bytes(24));
    DB::table('core.staff_resets')->where('person_id', $id)->whereNull('used_at')->update(['token_hash' => hash('sha256', $code), 'expires_at' => now()->subMinute()]);
    $this->get('/saas/staff/reimposta/'.$code)->assertSee('Link non valido');
    expect($access->find($id)?->email)->toBe('persona@example.test');
});

it('dall\'accesso unico si entra in /saas/admin con il token firmato dal SaaS stesso, una volta sola', function (): void {
    config(['saas.staff_sso.enabled' => true]);
    $id = loginPerson(roles: ['saas' => 'vendita']);
    $this->post('/saas/staff/accedi', ['email' => 'persona@example.test', 'password' => LOGIN_PASSWORD]);

    $page = $this->post('/saas/staff/vai', ['area' => 'saas'])->assertOk()->getContent();
    preg_match('/name="token" value="([^"]+)"/', (string) $page, $m);
    $token = $m[1] ?? '';

    $this->post('/saas/admin/sso', ['token' => $token])->assertOk()->assertSee('/saas/admin');
    $this->get('/saas/admin')->assertOk();
    expect(DB::table('core.platform_staff')->where('email', 'persona@example.test')->value('role'))->not->toBeNull();

    // Monouso, e un token di un'altra area o manomesso non entra.
    $this->post('/saas/admin/sso', ['token' => $token])->assertForbidden();
    $this->post('/saas/admin/sso', ['token' => $token.'x'])->assertForbidden();

    // Persona senza il ruolo SaaS: nessun token.
    DB::table('core.staff_roles')->where('person_id', $id)->delete();
    $this->post('/saas/staff/vai', ['area' => 'saas'])->assertRedirect('/saas/staff/accedi');
});

it('se la sessione centrale è chiusa il token non entra', function (): void {
    config(['saas.staff_sso.enabled' => true]);
    loginPerson();
    $this->post('/saas/staff/accedi', ['email' => 'persona@example.test', 'password' => LOGIN_PASSWORD]);
    $page = $this->post('/saas/staff/vai', ['area' => 'saas'])->getContent();
    preg_match('/name="token" value="([^"]+)"/', (string) $page, $m);

    DB::table('core.staff_sessions')->update(['revoked_at' => now()]);
    $this->post('/saas/admin/sso', ['token' => $m[1] ?? ''])->assertForbidden();
});
