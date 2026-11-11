<?php

declare(strict_types=1);

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
    expect(DB::table('core.staff_sessions')->where('sid', $claims['sid'])->where('person_id', $id)->count())->toBe(1)
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

it('non fa entrare in un\'area dove la persona non ha un ruolo', function (): void {
    idpPerson('persona@example.test', ['saas' => 'admin']);

    idpLogin($this)->assertUnauthorized();
});

it('i super amministratori sono admin di tutte le aree senza ruoli assegnati', function (): void {
    idpPerson('titolare@example.test', []);

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
    idpPerson('persona@example.test', ['wordpress' => 'admin', 'saas' => 'vendita']);
    $first = idpVerifyStaffToken((string) idpLogin($this)->json('token'), $this->staffPublic, 'associazionesemplice-portale');

    $go = fn (string $to) => $this->postJson('/saas/api/staff/v1/token', ['token' => idpRequest(
        $this->wordpressSeed, 'staff_token', ['sid' => $first['sid'], 'to' => $to], 'wordpress', 'portale-wp',
    )]);

    $claims = idpVerifyStaffToken((string) $go('saas')->assertOk()->json('token'), $this->staffPublic, 'associazionesemplice-saas');
    expect($claims['area'])->toBe('saas')->and($claims['level'])->toBe('vendita')->and($claims['sid'])->toBe($first['sid']);

    $go('supporto')->assertForbidden();
    $go('inesistente')->assertForbidden();
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
    DB::table('core.staff_sessions')->where('sid', $sid)->update(['revoked_at' => now()]);
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
