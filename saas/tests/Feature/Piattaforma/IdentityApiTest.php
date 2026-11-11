<?php

declare(strict_types=1);

use App\Modules\Portale\Jwt\EdDsaJwt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/*
 * L'identità unica come richieste firmate del portale (docs/IDENTITA_API.md). Dati di prova inventati.
 */

const ID_PASSWORD = 'una-password-di-prova-lunga';
const ID_PREFIX = 'https://associazionesemplice.it/account/';

beforeEach(function (): void {
    $this->portalSeed = random_bytes(32);
    $this->supportSeed = random_bytes(32);
    $x = static fn (string $seed): string => EdDsaJwt::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
    config([
        'saas.staff_issuer.enabled' => true,
        'saas.staff_issuer.signing_key' => base64_encode(random_bytes(32)),
        'saas.staff_issuer.area_keys' => 'portale-wp='.$x($this->portalSeed).'@wordpress,supporto-sp='.$x($this->supportSeed).'@supporto',
        'saas.staff_issuer.super_admins' => '',
        'saas.public_url' => 'https://associazionesemplice.it',
    ]);
    RateLimiter::clear('staff-login|persona@example.test|203.0.113.7');
});

/** @param  array<string, mixed>  $claims */
function idCall(Tests\TestCase $test, string $action, array $claims = [], ?string $seed = null, string $area = 'wordpress', ?string $kid = null): Illuminate\Testing\TestResponse
{
    $now = time();
    $claims += ['iss' => 'https://associazionesemplice.it', 'aud' => 'associazionesemplice-saas', 'typ' => 'identity_'.str_replace('-', '_', $action), 'iat' => $now, 'exp' => $now + 60, 'jti' => bin2hex(random_bytes(8)), 'area' => $area, 'ip' => '203.0.113.7'];
    $seed ??= $test->portalSeed;
    $head = EdDsaJwt::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => $kid ?? ($area === 'wordpress' ? 'portale-wp' : 'supporto-sp')]));
    $body = EdDsaJwt::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
    $token = $head.'.'.$body.'.'.EdDsaJwt::b64(sodium_crypto_sign_detached($head.'.'.$body, $secret));

    return $test->postJson('/saas/api/staff/v1/identity/'.$action, ['token' => $token]);
}

/** @param  array<string, string>  $roles */
function idPerson(string $email = 'persona@example.test', array $roles = ['saas' => 'admin', 'wordpress' => 'vendita']): string
{
    $id = (string) Str::uuid7();
    DB::table('core.staff_people')->insert(['id' => $id, 'email' => $email, 'name' => 'Persona di prova', 'password' => Hash::make(ID_PASSWORD), 'active' => true]);
    foreach ($roles as $area => $level) {
        DB::table('core.staff_roles')->insert(['person_id' => $id, 'area' => $area, 'level' => $level]);
    }

    return $id;
}

function idLogin(Tests\TestCase $test): string
{
    $sid = idCall($test, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD])->assertOk()->json('sid');

    return is_string($sid) ? $sid : '';
}

it('è spento (404) finché l\'emittente non è acceso', function (): void {
    config(['saas.staff_issuer.enabled' => false]);

    idCall($this, 'login', ['email' => 'a@example.test', 'password' => 'x'])->assertNotFound();
});

it('accetta solo il portale: la chiave di un\'altra area, un\'azione diversa o un token riusato no', function (): void {
    idPerson();

    idCall($this, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD], $this->supportSeed, 'supporto')->assertForbidden();
    idCall($this, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD, 'typ' => 'identity_profile'])->assertUnauthorized();
    idCall($this, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD], random_bytes(32))->assertUnauthorized();

    // Lo stesso token due volte: la seconda no.
    $claims = ['jti' => 'riuso-uno-solo', 'email' => 'persona@example.test', 'password' => ID_PASSWORD];
    idCall($this, 'login', $claims)->assertOk();
    idCall($this, 'login', $claims)->assertUnauthorized();
});

it('accede con email e password, senza dire quale delle due è sbagliata', function (): void {
    idPerson();

    $ok = idCall($this, 'login', ['email' => 'Persona@Example.test', 'password' => ID_PASSWORD])->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('person.email', 'persona@example.test')->assertJsonPath('areas.saas', 'admin');
    expect($ok->json('sid'))->toMatch('/^[0-9a-f]{32}$/');

    idCall($this, 'login', ['email' => 'persona@example.test', 'password' => 'sbagliata-sbagliata'])->assertUnauthorized()->assertExactJson(['error' => 'denied']);
    idCall($this, 'login', ['email' => 'nessuno@example.test', 'password' => ID_PASSWORD])->assertUnauthorized()->assertExactJson(['error' => 'denied']);
});

it('dopo 5 errori blocca per un minuto', function (): void {
    idPerson();
    for ($i = 0; $i < 5; $i++) {
        idCall($this, 'login', ['email' => 'persona@example.test', 'password' => 'sbagliata-sbagliata'])->assertUnauthorized();
    }

    idCall($this, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD])->assertStatus(429);
});

it('profilo e controllo sessione: persona, ruoli, sessioni; una sessione revocata non vale', function (): void {
    idPerson();
    $sid = idLogin($this);

    idCall($this, 'session', ['sid' => $sid])->assertOk()->assertJsonPath('valid', true)->assertJsonPath('two_factor', false);
    $profile = idCall($this, 'profile', ['sid' => $sid])->assertOk()->assertJsonPath('areas.wordpress', 'vendita')->assertJsonPath('sessions.0.current', true);
    expect(json_encode($profile->json()))->not->toContain($sid);

    idCall($this, 'logout', ['sid' => $sid])->assertOk();
    idCall($this, 'session', ['sid' => $sid])->assertOk()->assertJsonPath('valid', false);
    idCall($this, 'profile', ['sid' => $sid])->assertUnauthorized();
});

it('cambia la password chiedendo quella attuale e chiude le altre sessioni', function (): void {
    $id = idPerson();
    $sid = idLogin($this);
    $other = idLogin($this);

    idCall($this, 'password', ['sid' => $sid, 'current' => 'sbagliata-sbagliata', 'new' => 'una-nuova-password-1'])->assertStatus(422);
    idCall($this, 'password', ['sid' => $sid, 'current' => ID_PASSWORD, 'new' => 'corta'])->assertStatus(422);
    idCall($this, 'password', ['sid' => $sid, 'current' => ID_PASSWORD, 'new' => 'una-nuova-password-1'])->assertOk();

    expect(Hash::check('una-nuova-password-1', (string) DB::table('core.staff_people')->where('id', $id)->value('password')))->toBeTrue();
    idCall($this, 'session', ['sid' => $sid])->assertJsonPath('valid', true);
    idCall($this, 'session', ['sid' => $other])->assertJsonPath('valid', false);
});

it('il cambio email si conferma dal link al nuovo indirizzo, solo se il link è del portale', function (): void {
    Mail::fake();
    $id = idPerson();
    idPerson('occupata@example.test', []);
    $sid = idLogin($this);

    idCall($this, 'email', ['sid' => $sid, 'password' => ID_PASSWORD, 'new_email' => 'nuova@example.test', 'link_base' => 'https://attaccante.example/account/'])->assertStatus(422);
    idCall($this, 'email', ['sid' => $sid, 'password' => 'sbagliata-sbagliata', 'new_email' => 'nuova@example.test', 'link_base' => ID_PREFIX])->assertStatus(422);
    // Un indirizzo già usato dà la stessa risposta e non cambia nulla.
    idCall($this, 'email', ['sid' => $sid, 'password' => ID_PASSWORD, 'new_email' => 'occupata@example.test', 'link_base' => ID_PREFIX])->assertOk();
    expect(DB::table('core.staff_email_changes')->count())->toBe(0);

    idCall($this, 'email', ['sid' => $sid, 'password' => ID_PASSWORD, 'new_email' => 'Nuova@Example.test', 'link_base' => ID_PREFIX])->assertOk();
    expect(DB::table('core.staff_email_changes')->count())->toBe(1)
        ->and(DB::table('core.staff_people')->where('id', $id)->value('email'))->toBe('persona@example.test');

    $code = bin2hex(random_bytes(24));
    DB::table('core.staff_email_changes')->update(['token_hash' => hash('sha256', $code)]);
    idCall($this, 'email-confirm', ['code' => $code])->assertOk();
    expect(DB::table('core.staff_people')->where('id', $id)->value('email'))->toBe('nuova@example.test');
    idCall($this, 'email-confirm', ['code' => $code])->assertUnauthorized();
});

it('attiva il secondo livello, poi lo chiede all\'accesso, e si può disattivare', function (): void {
    $id = idPerson();
    $sid = idLogin($this);

    idCall($this, '2fa-start', ['sid' => $sid, 'password' => 'sbagliata-sbagliata'])->assertUnauthorized();
    $start = idCall($this, '2fa-start', ['sid' => $sid, 'password' => ID_PASSWORD])->assertOk();
    expect($start->json('secret'))->toBeString()->and($start->json('qr_svg'))->toContain('<svg');
    $secret = (string) $start->json('secret');
    // Fortify non accetta due volte lo stesso codice: nella prova si dimentica l'uso precedente, come farebbe il passare del tempo.
    $otp = static function () use ($secret): string {
        $code = (new Google2FA)->getCurrentOtp($secret);
        Cache::forget('fortify.2fa_codes.'.md5($code));

        return $code;
    };

    // Finché non è confermato, l'accesso resta senza codice.
    idCall($this, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD])->assertJsonPath('status', 'ok');
    idCall($this, '2fa-confirm', ['sid' => $sid, 'code' => '000000'])->assertUnauthorized();
    idCall($this, '2fa-confirm', ['sid' => $sid, 'code' => $otp()])->assertOk();
    idCall($this, 'session', ['sid' => $sid])->assertJsonPath('two_factor', true);

    $ticket = idCall($this, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD])->assertJsonPath('status', 'code_required')->json('ticket');
    // Il segreto sta nel database solo cifrato.
    expect(DB::table('core.staff_people')->where('id', $id)->value('two_factor_secret'))->not->toBe($secret)
        ->and(Fortify::currentEncrypter()->decrypt((string) DB::table('core.staff_people')->where('id', $id)->value('two_factor_secret')))->toBe($secret);

    idCall($this, 'code', ['ticket' => $ticket, 'code' => '000000'])->assertUnauthorized();
    idCall($this, 'code', ['ticket' => $ticket, 'code' => $otp()])->assertOk()->assertJsonPath('status', 'ok');
    // Un ticket si usa una volta sola.
    idCall($this, 'code', ['ticket' => $ticket, 'code' => $otp()])->assertUnauthorized();

    idCall($this, '2fa-disable', ['sid' => $sid, 'password' => ID_PASSWORD, 'code' => '000000'])->assertUnauthorized();
    idCall($this, '2fa-disable', ['sid' => $sid, 'password' => ID_PASSWORD, 'code' => $otp()])->assertOk();
    idCall($this, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD])->assertJsonPath('status', 'ok');
});

it('il ticket del codice si brucia dopo 5 errori', function (): void {
    idPerson();
    $sid = idLogin($this);
    $secret = (string) idCall($this, '2fa-start', ['sid' => $sid, 'password' => ID_PASSWORD])->json('secret');
    idCall($this, '2fa-confirm', ['sid' => $sid, 'code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    $ticket = idCall($this, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD])->json('ticket');

    for ($i = 0; $i < 5; $i++) {
        idCall($this, 'code', ['ticket' => $ticket, 'code' => '000000'])->assertUnauthorized();
    }
    idCall($this, 'code', ['ticket' => $ticket, 'code' => (new Google2FA)->getCurrentOtp($secret)])->assertUnauthorized();
});

it('elenca e chiude le sessioni della stessa persona, senza mai mostrare il sid', function (): void {
    idPerson();
    idPerson('altra@example.test', []);
    $sid = idLogin($this);
    $other = idLogin($this);

    $sessions = idCall($this, 'sessions', ['sid' => $sid])->assertOk()->json('sessions');
    expect($sessions)->toHaveCount(2);
    $target = collect($sessions)->firstWhere('current', false)['id'];

    idCall($this, 'session-revoke', ['sid' => $sid, 'target' => 'inventato123456'])->assertUnauthorized();
    idCall($this, 'session-revoke', ['sid' => $sid, 'target' => $target])->assertOk();
    idCall($this, 'session', ['sid' => $other])->assertJsonPath('valid', false);
    idCall($this, 'session', ['sid' => $sid])->assertJsonPath('valid', true);
});

it('il recupero risponde sempre ok e scrive solo a chi esiste; con il link si sceglie la nuova password', function (): void {
    Mail::fake();
    $id = idPerson();
    $sid = idLogin($this);

    idCall($this, 'reset-request', ['email' => 'nessuno@example.test', 'link_base' => ID_PREFIX])->assertOk()->assertExactJson(['ok' => true]);
    idCall($this, 'reset-request', ['email' => 'persona@example.test', 'link_base' => 'https://attaccante.example/'])->assertStatus(422);
    idCall($this, 'reset-request', ['email' => 'persona@example.test', 'link_base' => ID_PREFIX])->assertOk()->assertExactJson(['ok' => true]);
    expect(DB::table('core.staff_resets')->count())->toBe(1);

    $code = bin2hex(random_bytes(24));
    DB::table('core.staff_resets')->update(['token_hash' => hash('sha256', $code)]);
    idCall($this, 'reset-complete', ['code' => $code, 'password' => 'corta'])->assertStatus(422);
    idCall($this, 'reset-complete', ['code' => $code, 'password' => 'una-nuova-password-1'])->assertOk();
    idCall($this, 'session', ['sid' => $sid])->assertJsonPath('valid', false);
    expect(Hash::check('una-nuova-password-1', (string) DB::table('core.staff_people')->where('id', $id)->value('password')))->toBeTrue();
});

it('dà il token di un\'area solo se la persona ha quel ruolo', function (): void {
    idPerson(roles: ['saas' => 'vendita']);
    $sid = idLogin($this);

    expect(idCall($this, 'token', ['sid' => $sid, 'to' => 'saas'])->assertOk()->json('token'))->toMatch('/^[\w-]+\.[\w-]+\.[\w-]+$/');
    idCall($this, 'token', ['sid' => $sid, 'to' => 'wordpress'])->assertForbidden();
    idCall($this, 'token', ['sid' => $sid, 'to' => 'inventata'])->assertForbidden();
});
