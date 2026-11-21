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

it('nel database c\'è solo l\'impronta del sid; ai token delle aree va un riferimento, valido solo per l\'area e non per il salto', function (): void {
    idPerson(roles: ['saas' => 'vendita', 'supporto' => 'agente']);
    $sid = idLogin($this);

    expect(json_encode(DB::table('core.staff_sessions')->get()))->not->toContain($sid);

    $claims = static fn (string $token): array => json_decode(EdDsaJwt::unb64(explode('.', $token)[1]), true);
    $saas = $claims((string) idCall($this, 'token', ['sid' => $sid, 'to' => 'saas'])->assertOk()->json('token'));
    $help = $claims((string) idCall($this, 'token', ['sid' => $sid, 'to' => 'supporto'])->assertOk()->json('token'));
    expect($saas['sid'])->toMatch('/^[0-9a-f]{32}$/')->not->toBe($sid)->and($help['sid'])->not->toBe($saas['sid']);

    // Un riferimento di area non serve per saltare, né per leggere il profilo: come una sessione inesistente.
    idCall($this, 'token', ['sid' => $saas['sid'], 'to' => 'supporto'])->assertForbidden();
    idCall($this, 'session', ['sid' => $saas['sid']])->assertJsonPath('valid', false);

    // Il riferimento del SaaS vale per il SaaS e non per il supporto; con l'uscita cade anche quello.
    $directory = app(App\Modules\Piattaforma\Identity\StaffDirectory::class);
    expect($directory->personOfAreaRef($saas['sid'], 'saas'))->not->toBeNull()
        ->and($directory->personOfAreaRef($saas['sid'], 'supporto'))->toBeNull();
    idCall($this, 'logout', ['sid' => $sid])->assertOk();
    expect($directory->personOfAreaRef($saas['sid'], 'saas'))->toBeNull();
});

/* Operatori delle associazioni nello stesso accesso (passo 6): il portale chiede il biglietto, il gestionale lo consuma. */

it('l\'ingresso degli operatori è spento di default', function (): void {
    idPerson();
    $sid = idLogin($this);

    idCall($this, 'operator-entry', ['sid' => $sid])->assertStatus(403);
});

it('dà il biglietto solo a chi è operatore, lo accetta una volta sola e apre il gestionale', function (): void {
    config(['saas.unified_login.operators' => true]);
    idPerson();
    $sid = idLogin($this);

    // Persona senza associazioni: niente biglietto.
    idCall($this, 'operator-entry', ['sid' => $sid])->assertStatus(403);

    $user = $this->createUser('persona@example.test', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $url = idCall($this, 'operator-entry', ['sid' => $sid])->assertOk()->json('url');
    // Il biglietto sta nel frammento (mai nelle richieste, quindi mai nei log), non nella query.
    expect($url)->toBeString()->toContain('/accesso-unico#t=')->not->toContain('?t=');
    $token = opTicket((string) $url);

    $this->post('/accesso-unico', ['t' => $token])->assertRedirect(route('two-factor.login'));
    $this->assertGuest(); // entrato nel gestionale solo dopo il secondo livello
    expect(session('login.id'))->toBe($user->id);

    // Il biglietto non si riusa.
    $this->post('/accesso-unico', ['t' => $token])->assertRedirect('https://associazionesemplice.it/account');
});

it('rifiuta un biglietto contraffatto o con l\'ingresso spento', function (): void {
    config(['saas.unified_login.operators' => true]);

    $this->post('/accesso-unico', ['t' => 'non-un-biglietto'])->assertRedirect('https://associazionesemplice.it/account');
    $this->assertGuest();

    config(['saas.unified_login.operators' => false]);
    $this->post('/accesso-unico', ['t' => 'x'])->assertRedirect('https://associazionesemplice.it/account');
    $this->get('/accesso-unico')->assertRedirect('https://associazionesemplice.it/account');
});

/** Il biglietto dell'indirizzo d'ingresso (sta nel frammento). */
function opTicket(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_FRAGMENT), $parts);

    return is_string($parts['t'] ?? null) ? $parts['t'] : '';
}

/** Persona centrale di prova entrata + biglietto d'ingresso nuovo. */
function opNewTicket(Tests\TestCase $test, string $sid): string
{
    return opTicket((string) idCall($test, 'operator-entry', ['sid' => $sid])->assertOk()->json('url'));
}

it('il vecchio indirizzo con il biglietto in GET non entra e non lo consuma: c\'è solo la pagina intermedia', function (): void {
    config(['saas.unified_login.operators' => true]);
    idPerson();
    $sid = idLogin($this);
    $user = $this->createUser('persona@example.test', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    $token = opNewTicket($this, $sid);

    $this->get('/accesso-unico?t='.rawurlencode($token))->assertOk()->assertSee('Apertura del gestionale')->assertDontSee($token, false);
    $this->assertGuest();
    $this->get('/accesso-unico/inoltra.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8')->assertSee('location.hash', false);

    // Il biglietto è ancora buono per il POST.
    $this->post('/accesso-unico', ['t' => $token])->assertRedirect(route('two-factor.login'));
});

it('senza secondo livello superato in quella sessione manda alla sfida del gestionale, e il legame nasce dopo il codice giusto', function (): void {
    config(['saas.unified_login.operators' => true]);
    $personId = idPerson();
    $sid = idLogin($this);
    $user = $this->createUser('persona@example.test', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->post('/accesso-unico', ['t' => opNewTicket($this, $sid)])->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id)
        ->and(session(App\Modules\Piattaforma\Identity\OperatorEntry::LINK_KEY.'.person'))->toBe($personId)
        ->and(session('operator.sso_mfa'))->toBeNull()
        ->and(DB::table('core.operator_links')->count())->toBe(0); // ancora nessun legame: manca il codice

    // Il codice sbagliato non entra e non lega.
    $link = session(App\Modules\Piattaforma\Identity\OperatorEntry::LINK_KEY);
    $this->withSession(['login.id' => $user->id, 'login.remember' => false, App\Modules\Piattaforma\Identity\OperatorEntry::LINK_KEY => $link])
        ->post('/verifica-accesso', ['code' => '000000']);
    $this->assertGuest();
    expect(DB::table('core.operator_links')->count())->toBe(0);

    // Il codice giusto entra e crea il legame.
    $this->withSession(['login.id' => $user->id, 'login.remember' => false, 'url.intended' => '/gestionale', App\Modules\Piattaforma\Identity\OperatorEntry::LINK_KEY => $link])
        ->post('/verifica-accesso', ['code' => $this->totp($user)])->assertRedirect('/gestionale');
    $this->assertAuthenticatedAs($user);
    expect(DB::table('core.operator_links')->where('user_id', $user->id)->value('person_id'))->toBe($personId)
        ->and(DB::table('core.staff_audit')->where('action', 'operator.linked')->count())->toBe(1);
});

it('l\'email da sola non basta: senza legame né secondo livello l\'utente non entra e non viene legato', function (): void {
    config(['saas.unified_login.operators' => true]);
    idPerson();
    $sid = idLogin($this);
    $user = $this->createUser('persona@example.test'); // senza secondo livello
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->post('/accesso-unico', ['t' => opNewTicket($this, $sid)])->assertOk()->assertSee('Accesso non collegato');
    $this->assertGuest();
    expect(DB::table('core.operator_links')->count())->toBe(0);
});

it('chi è già legato entra dalla sfida anche con un\'email diversa; un\'altra persona con quell\'email non prende il suo posto', function (): void {
    config(['saas.unified_login.operators' => true]);
    $personId = idPerson();
    $sid = idLogin($this);
    $org = $this->createOrg('Polisportiva Alfa');
    $legata = $this->createUser('altra@example.test', twoFactor: true);
    $omonima = $this->createUser('persona@example.test', twoFactor: true);
    $this->addOperator($org, $legata);
    $this->addOperator($org, $omonima);
    DB::table('core.operator_links')->insert(['user_id' => $legata->id, 'person_id' => $personId]);

    $this->post('/accesso-unico', ['t' => opNewTicket($this, $sid)])->assertRedirect(route('two-factor.login'));
    expect(session('login.id'))->toBe($legata->id);

    // L'utente con l'email della persona, legato a un'altra persona, non è proposto a nessun altro.
    DB::table('core.operator_links')->where('user_id', $legata->id)->delete();
    $altra = (string) Str::uuid7();
    DB::table('core.staff_people')->insert(['id' => $altra, 'email' => 'terza@example.test', 'name' => 'Terza', 'password' => Hash::make(ID_PASSWORD), 'active' => true]);
    DB::table('core.operator_links')->insert(['user_id' => $omonima->id, 'person_id' => $altra]);
    expect(idCall($this, 'operator-entry', ['sid' => $sid])->status())->toBe(403);
});

it('un utente legato senza secondo livello segue la regola degli operatori: prima lo attiva', function (): void {
    config(['saas.unified_login.operators' => true]);
    $personId = idPerson();
    $sid = idLogin($this);
    $user = $this->createUser('persona@example.test');
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    DB::table('core.operator_links')->insert(['user_id' => $user->id, 'person_id' => $personId]);

    $this->post('/accesso-unico', ['t' => opNewTicket($this, $sid)])->assertRedirect('/gestionale');
    $this->get('/gestionale')->assertRedirect(route('account.two-factor'));
});

it('se nel browser c\'è la sessione di un altro utente chiede conferma invece di sostituirla', function (): void {
    config(['saas.unified_login.operators' => true]);
    $personId = idPerson();
    $sid = idLogin($this);
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('persona@example.test', twoFactor: true);
    $altro = $this->createUser('altro@example.test', twoFactor: true);
    $this->addOperator($org, $user);
    $this->addOperator($org, $altro);
    DB::table('core.operator_links')->insert(['user_id' => $user->id, 'person_id' => $personId]);

    $this->actingAs($altro)->post('/accesso-unico', ['t' => opNewTicket($this, $sid)])
        ->assertOk()->assertSee('Stai per cambiare utente')->assertSee('altro@example.test')->assertSee('persona@example.test');
    $this->assertAuthenticatedAs($altro); // la sessione dell'altro è intatta
    expect(session('login.id'))->toBeNull();

    // Conferma: si va alla sfida del nuovo utente; senza attesa in sessione non succede nulla.
    $pending = session(App\Modules\Piattaforma\Identity\OperatorEntry::PENDING_KEY);
    session()->forget(App\Modules\Piattaforma\Identity\OperatorEntry::PENDING_KEY);
    $this->post('/accesso-unico/conferma')->assertRedirect('/gestionale');
    $this->withSession([App\Modules\Piattaforma\Identity\OperatorEntry::PENDING_KEY => $pending])->post('/accesso-unico/conferma')->assertRedirect(route('two-factor.login'));
    expect(session('login.id'))->toBe($user->id);

    // Un'attesa scaduta non vale.
    $pending['until'] = time() - 1;
    $this->withSession([App\Modules\Piattaforma\Identity\OperatorEntry::PENDING_KEY => $pending])->post('/accesso-unico/conferma')->assertRedirect('/gestionale');
});

it('chi è già dentro come lo stesso utente va dritto al gestionale', function (): void {
    config(['saas.unified_login.operators' => true]);
    $personId = idPerson();
    $sid = idLogin($this);
    $user = $this->createUser('persona@example.test', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    DB::table('core.operator_links')->insert(['user_id' => $user->id, 'person_id' => $personId]);

    $this->actingAs($user)->post('/accesso-unico', ['t' => opNewTicket($this, $sid)])->assertRedirect('/gestionale');
});

it('con l\'ingresso spento il gestionale resta quello di prima: modulo di accesso proprio e nessun ingresso unico', function (): void {
    idPerson();
    $this->createUser('persona@example.test', twoFactor: true);

    $this->get('/accesso-unico')->assertRedirect('https://associazionesemplice.it/account');
    $this->post('/accesso-unico/conferma')->assertRedirect('/gestionale');
    $this->get('/accesso')->assertOk();
    $this->assertGuest();
});

/* Codici sbagliati contati per persona, non per biglietto. */

function idEnableTwoFactor(Tests\TestCase $test): Closure
{
    $sid = idLogin($test);
    $secret = (string) idCall($test, '2fa-start', ['sid' => $sid, 'password' => ID_PASSWORD])->json('secret');
    $otp = static function () use ($secret): string {
        $code = (new Google2FA)->getCurrentOtp($secret);
        Cache::forget('fortify.2fa_codes.'.md5($code));

        return $code;
    };
    idCall($test, '2fa-confirm', ['sid' => $sid, 'code' => $otp()])->assertOk();

    return $otp;
}

/** Accede con la password e sbaglia il codice $errors volte sullo stesso biglietto. */
function idWrongCodes(Tests\TestCase $test, int $errors): void
{
    $ticket = idCall($test, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD])->json('ticket');
    for ($i = 0; $i < $errors; $i++) {
        idCall($test, 'code', ['ticket' => $ticket, 'code' => '000000'])->assertUnauthorized();
    }
}

function idCodeWith(Tests\TestCase $test, string $code): Illuminate\Testing\TestResponse
{
    $ticket = idCall($test, 'login', ['email' => 'persona@example.test', 'password' => ID_PASSWORD])->json('ticket');

    return idCall($test, 'code', ['ticket' => $ticket, 'code' => $code]);
}

it('troppi codici sbagliati su biglietti diversi bloccano i codici della persona per un\'ora', function (): void {
    $id = idPerson();
    $otp = idEnableTwoFactor($this);

    idWrongCodes($this, 5);
    idWrongCodes($this, 5);
    expect(DB::table('core.staff_audit')->where('action', 'staff.code_locked')->where('person_id', $id)->count())->toBe(1);

    // Biglietto nuovo e codice giusto: stesso rifiuto di un errore normale, e il login con password non sblocca.
    idCodeWith($this, $otp())->assertUnauthorized()->assertExactJson(['error' => 'denied']);

    $this->travel(61)->minutes();
    idCodeWith($this, $otp())->assertOk()->assertJsonPath('status', 'ok');
});

it('il codice giusto azzera gli errori della persona, il login con password no', function (): void {
    idPerson();
    $otp = idEnableTwoFactor($this);

    // 9 errori su due biglietti: i login con password in mezzo non li azzerano.
    idWrongCodes($this, 5);
    idWrongCodes($this, 4);
    idCodeWith($this, $otp())->assertOk();

    // Il codice giusto ha azzerato: altri 9 errori non bloccano.
    idWrongCodes($this, 5);
    idWrongCodes($this, 4);
    idCodeWith($this, $otp())->assertOk();
});
