<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Identity\SessionFingerprint;
use App\Modules\Piattaforma\Identity\StaffDirectory;
use App\Modules\Piattaforma\Identity\StaffPerson;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Il SaaS come fornitore di identità dello staff (docs/UTENTI_E_ACCESSI.md): login, «Vai a» e controllo di sessione
 * richiesti dalle aree con token firmati. Le aree sono finte: ognuna ha la sua coppia di chiavi.
 */

const IDP_PASSWORD = 'una-password-di-prova-lunga';
const IDP_ISSUER = 'https://associazionesemplice.it/saas';

beforeEach(function (): void {
    $this->staffSeed = random_bytes(32);
    $this->wordpressSeed = random_bytes(32);
    $this->supportSeed = random_bytes(32);
    $x = static fn (string $seed): string => EdDsaJwt::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
    config([
        'saas.staff_issuer.enabled' => true,
        'saas.staff_issuer.signing_key' => base64_encode($this->staffSeed),
        'saas.staff_issuer.area_keys' => 'portale-wp='.$x($this->wordpressSeed).'@wordpress,supporto-sp='.$x($this->supportSeed).'@supporto',
        'saas.staff_issuer.super_admins' => 'titolare@example.test',
    ]);
    $this->staffPublic = $x($this->staffSeed);
    RateLimiter::clear('staff-login|persona@example.test|203.0.113.7');
});

/** Una richiesta firmata di un'area (kid fisso del test, come in SAAS_STAFF_AREA_KEYS). */
function idpRequest(string $seed, string $type, array $claims, string $area = 'wordpress', ?string $kid = null): string
{
    $now = time();
    $claims = [
        'iss' => 'https://associazionesemplice.it',
        'aud' => PortalConfig::AUDIENCE,
        'typ' => $type,
        'iat' => $now,
        'exp' => $now + 60,
        'jti' => bin2hex(random_bytes(8)),
        'area' => $area,
    ] + $claims;

    $token = EdDsaJwt::sign($claims, $seed);
    if ($kid === null) {
        return $token;
    }
    // Il test usa kid fissi: si riscrive l'intestazione e si firma di nuovo con quel kid.
    $head = EdDsaJwt::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => $kid]));
    $body = EdDsaJwt::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));

    return $head.'.'.$body.'.'.EdDsaJwt::b64(sodium_crypto_sign_detached($head.'.'.$body, $secret));
}

function idpPerson(string $email = 'persona@example.test', array $roles = ['wordpress' => 'vendita'], bool $active = true): string
{
    $id = (string) Illuminate\Support\Str::uuid7();
    DB::table('core.staff_people')->insert(['id' => $id, 'email' => $email, 'name' => 'Persona', 'password' => Hash::make(IDP_PASSWORD), 'active' => $active]);
    foreach ($roles as $area => $level) {
        DB::table('core.staff_roles')->insert(['person_id' => $id, 'area' => $area, 'level' => $level]);
    }

    return $id;
}

/** Apre una sessione centrale come fa il portale con il login unico e ne restituisce l'identificativo vero. */
function idpTrueSession(string $personId): string
{
    return app(StaffDirectory::class)->openSession(new StaffPerson($personId, 'persona@example.test', 'Persona'), '203.0.113.7');
}

/** @return array<string, mixed> */
function idpVerifyStaffToken(string $token, string $public, string $audience): array
{
    $header = json_decode(EdDsaJwt::unb64(explode('.', $token)[0]), true);
    expect($header['kid'])->toStartWith('staff-');

    return EdDsaJwt::verify($token, [$header['kid'] => $public], IDP_ISSUER, $audience, 'staff_sso', time());
}

function idpLogin(Tests\TestCase $test, array $overrides = [], string $area = 'wordpress'): Illuminate\Testing\TestResponse
{
    $seed = $area === 'wordpress' ? $test->wordpressSeed : $test->supportSeed;

    return $test->postJson('/saas/api/staff/v1/login', ['token' => idpRequest($seed, 'staff_login', $overrides + [
        'email' => 'persona@example.test',
        'password' => IDP_PASSWORD,
        'ip' => '203.0.113.7',
    ], $area, $area === 'wordpress' ? 'portale-wp' : 'supporto-sp')]);
}

it('è spento (404) finché SAAS_STAFF_ISSUER non è acceso', function (): void {
    config(['saas.staff_issuer.enabled' => false]);
    idpPerson();

    idpLogin($this)->assertNotFound();
});

it('fa entrare con email e password e dà un staff_sso per quell\'area, senza secondo fattore', function (): void {
    $id = idpPerson('persona@example.test', ['wordpress' => 'vendita', 'saas' => 'supporto']);

    $response = idpLogin($this)->assertOk();
    $claims = idpVerifyStaffToken((string) $response->json('token'), $this->staffPublic, 'associazionesemplice-portale');

    expect($claims['sub'])->toBe('persona@example.test')
        ->and($claims['area'])->toBe('wordpress')
        ->and($claims['level'])->toBe('vendita')
        ->and($claims['areas'])->toEqual(['wordpress' => 'vendita', 'saas' => 'supporto'])
        ->and($claims['mfa'])->toBeFalse()
        ->and($claims)->not->toHaveKey('mfa_at')
        ->and($claims['exp'] - $claims['iat'])->toBe(60)
        ->and($claims['sid'])->toMatch('/^[0-9a-f]{32}$/');
    // L'area riceve un riferimento, non l'identificativo di sessione: il database ha solo impronte.
    expect(DB::table('core.staff_sessions')->where('sid', $claims['sid'])->count())->toBe(0)
        ->and(DB::table('core.staff_sessions')->where('person_id', $id)->count())->toBe(1)
        ->and(DB::table('core.staff_area_sessions')->where('ref_hash', SessionFingerprint::ofAreaRef($claims['sid']))->where('area', 'wordpress')->count())->toBe(1)
        ->and(DB::table('core.staff_audit')->where('action', 'staff.login')->where('person_id', $id)->count())->toBe(1);
});

it('risponde allo stesso modo a password sbagliata, email sconosciuta e persona disattivata', function (): void {
    idpPerson('persona@example.test');
    idpPerson('spenta@example.test', ['wordpress' => 'admin'], false);

    $wrong = idpLogin($this, ['password' => 'sbagliata-sbagliata'])->assertUnauthorized();
    $unknown = idpLogin($this, ['email' => 'nessuno@example.test'])->assertUnauthorized();
    $off = idpLogin($this, ['email' => 'spenta@example.test'])->assertUnauthorized();

    expect($unknown->json())->toBe($wrong->json())->and($off->json())->toBe($wrong->json());
    expect(DB::table('core.staff_audit')->where('action', 'staff.login_failed')->count())->toBe(3)
        ->and(DB::table('core.staff_sessions')->count())->toBe(0);
});

it('nel registro degli accessi falliti salva l\'email solo se è un indirizzo, altrimenti una sigla', function (): void {
    idpLogin($this, ['email' => 'Nessuno@Example.Test'])->assertUnauthorized();
    idpLogin($this, ['email' => 'la mia password digitata qui per errore'])->assertUnauthorized();

    $subjects = DB::table('core.staff_audit')->where('action', 'staff.login_failed')->orderBy('created_at')->orderBy('id')->pluck('subject')->all();

    expect($subjects[0])->toBe('nessuno@example.test')
        ->and($subjects[1])->toMatch('/^impronta:[0-9a-f]{32}$/')
        ->and((string) json_encode(DB::table('core.staff_audit')->get()))->not->toContain('digitata');
});

it('non fa entrare in un\'area dove la persona non ha un ruolo', function (): void {
    idpPerson('persona@example.test', ['saas' => 'admin']);

    idpLogin($this)->assertUnauthorized();
});

it('l\'email in configurazione non basta a fare un super amministratore: conta l\'identificativo della persona', function (): void {
    idpPerson('titolare@example.test', []);

    idpLogin($this, ['email' => 'titolare@example.test'])->assertUnauthorized();
});

it('il comando indica e toglie un super amministratore per identificativo', function (): void {
    $id = idpPerson('titolare@example.test', []);

    $this->artisan('saas:staff:super-admin', ['email' => 'titolare@example.test'])->assertSuccessful();
    expect(DB::table('core.staff_super_admins')->where('person_id', $id)->exists())->toBeTrue();

    $this->artisan('saas:staff:super-admin', ['email' => 'titolare@example.test', '--remove' => true])->assertSuccessful();
    expect(DB::table('core.staff_super_admins')->count())->toBe(0);
    $this->artisan('saas:staff:super-admin', ['email' => 'nessuno@example.test'])->assertFailed();
});

it('i super amministratori sono admin di tutte le aree senza ruoli assegnati', function (): void {
    $id = idpPerson('titolare@example.test', []);
    DB::table('core.staff_super_admins')->insert(['person_id' => $id]);

    $token = (string) idpLogin($this, ['email' => 'titolare@example.test'])->assertOk()->json('token');
    $claims = idpVerifyStaffToken($token, $this->staffPublic, 'associazionesemplice-portale');

    expect($claims['level'])->toBe('admin')
        ->and($claims['areas'])->toEqual(['wordpress' => 'admin', 'saas' => 'admin', 'supporto' => 'admin']);
});

it('blocca dopo cinque password sbagliate, anche se poi è giusta', function (): void {
    idpPerson();
    for ($i = 0; $i < 5; $i++) {
        idpLogin($this, ['password' => 'sbagliata-sbagliata'])->assertUnauthorized();
    }

    idpLogin($this)->assertStatus(429);
    expect(DB::table('core.staff_audit')->where('action', 'staff.login_blocked')->count())->toBe(1);
});

it('«Vai a»: dalla sessione aperta dà il token per un\'altra area, solo se la persona ci ha un ruolo', function (): void {
    $id = idpPerson('persona@example.test', ['wordpress' => 'admin', 'saas' => 'vendita']);
    $sid = idpTrueSession($id);

    $go = fn (string $to, ?string $with = null) => $this->postJson('/saas/api/staff/v1/token', ['token' => idpRequest(
        $this->wordpressSeed, 'staff_token', ['sid' => $with ?? $sid, 'to' => $to], 'wordpress', 'portale-wp',
    )]);

    $claims = idpVerifyStaffToken((string) $go('saas')->assertOk()->json('token'), $this->staffPublic, 'associazionesemplice-saas');
    // Nel token va un riferimento per quell'area, mai l'identificativo vero.
    expect($claims['area'])->toBe('saas')->and($claims['level'])->toBe('vendita')
        ->and($claims['sid'])->toMatch('/^[0-9a-f]{32}$/')->and($claims['sid'])->not->toBe($sid);

    $go('supporto')->assertForbidden();
    $go('inesistente')->assertForbidden();
});

it('«Vai a» non accetta un riferimento di area al posto dell\'identificativo vero', function (): void {
    $id = idpPerson('persona@example.test', ['wordpress' => 'admin', 'saas' => 'vendita']);
    $sid = idpTrueSession($id);
    $ref = idpVerifyStaffToken(app(App\Modules\Piattaforma\Identity\StaffTokenIssuer::class)->issue(new StaffPerson($id, 'persona@example.test', 'Persona'), 'wordpress', $sid, ['wordpress' => 'admin', 'saas' => 'vendita']), $this->staffPublic, 'associazionesemplice-portale')['sid'];

    $denied = $this->postJson('/saas/api/staff/v1/token', ['token' => idpRequest($this->wordpressSeed, 'staff_token', ['sid' => $ref, 'to' => 'saas'], 'wordpress', 'portale-wp')])->assertForbidden();
    $unknown = $this->postJson('/saas/api/staff/v1/token', ['token' => idpRequest($this->wordpressSeed, 'staff_token', ['sid' => bin2hex(random_bytes(16)), 'to' => 'saas'], 'wordpress', 'portale-wp')])->assertForbidden();

    // Stessa risposta di una sessione che non esiste.
    expect($denied->json())->toBe($unknown->json());
});

it('l\'identificativo vero non compare mai in chiaro nel database', function (): void {
    $id = idpPerson('persona@example.test', ['wordpress' => 'admin', 'saas' => 'vendita']);
    $sid = idpTrueSession($id);
    app(App\Modules\Piattaforma\Identity\StaffTokenIssuer::class)->issue(new StaffPerson($id, 'persona@example.test', 'Persona'), 'saas', $sid, ['wordpress' => 'admin', 'saas' => 'vendita']);

    $dump = json_encode([DB::table('core.staff_sessions')->get(), DB::table('core.staff_area_sessions')->get()]);
    expect($dump)->not->toContain($sid)
        ->and(DB::table('core.staff_sessions')->value('sid'))->toBe(SessionFingerprint::ofSession($sid));
});

it('il riferimento di area vale solo per il controllo di sessione della sua area', function (): void {
    $id = idpPerson('persona@example.test', ['wordpress' => 'vendita', 'supporto' => 'agente']);
    $person = new StaffPerson($id, 'persona@example.test', 'Persona');
    $sid = idpTrueSession($id);
    $areas = ['wordpress' => 'vendita', 'supporto' => 'agente'];
    $issuer = app(App\Modules\Piattaforma\Identity\StaffTokenIssuer::class);
    $wpRef = idpVerifyStaffToken($issuer->issue($person, 'wordpress', $sid, $areas), $this->staffPublic, 'associazionesemplice-portale')['sid'];

    $check = fn (string $seed, string $kid, string $area, string $ref) => $this->postJson('/saas/api/staff/v1/session-check', ['token' => idpRequest(
        $seed, 'staff_session_check', ['sid' => $ref], $area, $kid,
    )]);

    $check($this->wordpressSeed, 'portale-wp', 'wordpress', $wpRef)->assertOk()->assertJson(['valid' => true, 'level' => 'vendita']);
    // La stessa stringa presentata dal supporto, o l'identificativo vero da chiunque, non valgono.
    $check($this->supportSeed, 'supporto-sp', 'supporto', $wpRef)->assertOk()->assertJson(['valid' => false]);
    $check($this->wordpressSeed, 'portale-wp', 'wordpress', $sid)->assertOk()->assertJson(['valid' => false]);
    $check($this->supportSeed, 'supporto-sp', 'supporto', $sid)->assertOk()->assertJson(['valid' => false]);
});

it('uscire (o chiudere la sessione) revoca anche i riferimenti di area', function (): void {
    $id = idpPerson('persona@example.test', ['wordpress' => 'vendita']);
    $sid = idpTrueSession($id);
    $ref = idpVerifyStaffToken(app(App\Modules\Piattaforma\Identity\StaffTokenIssuer::class)->issue(new StaffPerson($id, 'persona@example.test', 'Persona'), 'wordpress', $sid, ['wordpress' => 'vendita']), $this->staffPublic, 'associazionesemplice-portale')['sid'];
    $check = fn () => $this->postJson('/saas/api/staff/v1/session-check', ['token' => idpRequest($this->wordpressSeed, 'staff_session_check', ['sid' => $ref], 'wordpress', 'portale-wp')]);

    $check()->assertOk()->assertJson(['valid' => true]);
    app(App\Modules\Piattaforma\Identity\StaffAccess::class)->revokeSession($sid);
    $check()->assertOk()->assertJson(['valid' => false]);
});

it('una sessione dell\'epoca precedente (identificativo in chiaro nel database) non vale più', function (): void {
    $id = idpPerson('persona@example.test', ['wordpress' => 'vendita']);
    $old = bin2hex(random_bytes(16));
    DB::table('core.staff_sessions')->insert(['sid' => $old, 'person_id' => $id]);

    $check = fn (string $sid) => $this->postJson('/saas/api/staff/v1/session-check', ['token' => idpRequest($this->wordpressSeed, 'staff_session_check', ['sid' => $sid], 'wordpress', 'portale-wp')]);
    $check($old)->assertOk()->assertJson(['valid' => false]);
    $this->postJson('/saas/api/staff/v1/token', ['token' => idpRequest($this->wordpressSeed, 'staff_token', ['sid' => $old, 'to' => 'wordpress'], 'wordpress', 'portale-wp')])->assertForbidden();
});

it('il controllo di sessione dice se è valida e a che livello, e si accorge di revoca, ruolo tolto e persona spenta', function (): void {
    $id = idpPerson('persona@example.test', ['wordpress' => 'vendita']);
    $sid = idpVerifyStaffToken((string) idpLogin($this)->json('token'), $this->staffPublic, 'associazionesemplice-portale')['sid'];

    $check = fn () => $this->postJson('/saas/api/staff/v1/session-check', ['token' => idpRequest(
        $this->wordpressSeed, 'staff_session_check', ['sid' => $sid], 'wordpress', 'portale-wp',
    )]);

    $check()->assertOk()->assertJson(['valid' => true, 'level' => 'vendita']);

    DB::table('core.staff_roles')->where('person_id', $id)->update(['level' => 'admin']);
    $check()->assertOk()->assertJson(['valid' => true, 'level' => 'admin']);

    DB::table('core.staff_people')->where('id', $id)->update(['active' => false]);
    $check()->assertOk()->assertJson(['valid' => false, 'level' => null]);

    DB::table('core.staff_people')->where('id', $id)->update(['active' => true]);
    DB::table('core.staff_sessions')->update(['revoked_at' => now()]);
    $check()->assertOk()->assertJson(['valid' => false]);
});

it('la sessione centrale scade dopo otto ore', function (): void {
    idpPerson();
    $sid = idpVerifyStaffToken((string) idpLogin($this)->json('token'), $this->staffPublic, 'associazionesemplice-portale')['sid'];

    $this->travel(8 * 3600 + 120)->seconds();

    $this->postJson('/saas/api/staff/v1/session-check', ['token' => idpRequest(
        $this->wordpressSeed, 'staff_session_check', ['sid' => $sid], 'wordpress', 'portale-wp',
    )])->assertOk()->assertJson(['valid' => false]);
});

it('rifiuta richieste con chiave di un\'altra area, riusate o senza firma valida', function (): void {
    idpPerson();
    $claims = ['email' => 'persona@example.test', 'password' => IDP_PASSWORD];

    // La chiave del supporto non può parlare a nome di WordPress.
    $this->postJson('/saas/api/staff/v1/login', ['token' => idpRequest($this->supportSeed, 'staff_login', $claims, 'wordpress', 'supporto-sp')])->assertUnauthorized();
    // Chiave sconosciuta.
    $this->postJson('/saas/api/staff/v1/login', ['token' => idpRequest(random_bytes(32), 'staff_login', $claims, 'wordpress', 'portale-altro')])->assertUnauthorized();
    // Tipo sbagliato.
    $this->postJson('/saas/api/staff/v1/login', ['token' => idpRequest($this->wordpressSeed, 'staff_token', $claims, 'wordpress', 'portale-wp')])->assertUnauthorized();
    // Senza token.
    $this->postJson('/saas/api/staff/v1/login', [])->assertUnprocessable();

    // Stessa richiesta due volte: la seconda è respinta (jti monouso).
    $token = idpRequest($this->wordpressSeed, 'staff_login', $claims, 'wordpress', 'portale-wp');
    $this->postJson('/saas/api/staff/v1/login', ['token' => $token])->assertOk();
    $this->postJson('/saas/api/staff/v1/login', ['token' => $token])->assertUnauthorized();
});
