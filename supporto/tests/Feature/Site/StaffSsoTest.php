<?php

namespace Tests\Feature\Site;

use App\Models\User;
use App\Services\Staff\StaffJwt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\SiteTestCase;

class StaffSsoTest extends SiteTestCase
{
    private string $portalSecret;

    private string $ourFile;

    private string $ourPublic;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->portalSecret = sodium_crypto_sign_secretkey($pair);
        $this->ourFile = tempnam(sys_get_temp_dir(), 'staffkey');
        $ours = sodium_crypto_sign_keypair();
        $this->ourPublic = StaffJwt::b64(sodium_crypto_sign_publickey($ours));
        file_put_contents($this->ourFile, json_encode(['kid' => 'supporto-test', 'd' => StaffJwt::b64(sodium_crypto_sign_secretkey($ours))]));

        config([
            'app.url' => 'https://supporto.test',
            'support.sso.portal' => 'https://portale.test',
            'support.staff_sso.issuer' => 'https://portale.test',
            'support.staff_sso.portal_keys' => 'staff-test:'.StaffJwt::b64(sodium_crypto_sign_publickey($pair)),
            'support.staff_sso.private_key_file' => $this->ourFile,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->ourFile);
        parent::tearDown();
    }

    /** Accesso dal portale; la sessione creata passa alle richieste seguenti (i test non conservano i cookie). */
    private function staffLogin(string $token): void
    {
        $this->post('/staff/sso', ['token' => $token])->assertRedirect('/admin');
        $this->withSession(session()->all());
    }

    private function token(array $override = [], ?string $secret = null, array $drop = [], array $header = []): string
    {
        $claims = array_diff_key($override + [
            'iss' => 'https://portale.test', 'aud' => 'associazionesemplice-supporto', 'typ' => 'staff_sso',
            'iat' => time(), 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(16)), 'sub' => 'agente@example.it',
            'area' => 'supporto', 'level' => 'agente', 'mfa' => false, 'sid' => bin2hex(random_bytes(16)),
        ], array_flip($drop));
        $h = StaffJwt::b64(json_encode($header + ['typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => 'staff-test']));
        $p = StaffJwt::b64(json_encode($claims));

        return $h.'.'.$p.'.'.StaffJwt::b64(sodium_crypto_sign_detached($h.'.'.$p, $secret ?? $this->portalSecret));
    }

    public function test_valid_token_opens_a_session_with_the_level_from_the_portal(): void
    {
        // Il SaaS aggiunge `areas` (area => livello) per il selettore: ammesso
        $this->post('/staff/sso', ['token' => $this->token(['areas' => ['supporto' => 'agente', 'wordpress' => 'vendita']])])->assertRedirect('/admin');

        $user = User::where('email', 'agente@example.it')->firstOrFail();
        $this->assertSame('agent', $user->global_role);
        $this->assertTrue($user->sso_managed);
        $this->assertAuthenticatedAs($user);
        $this->get('/admin/ticket')->assertOk();

        $this->post('/staff/sso', ['token' => $this->token(['sub' => 'capo@example.it', 'level' => 'admin', 'mfa' => true, 'mfa_at' => time()])])->assertRedirect('/admin');
        $this->assertSame('admin', User::where('email', 'capo@example.it')->value('global_role'));
    }

    public function test_bad_tokens_are_all_refused(): void
    {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $cases = [
            'firma di altri' => $this->token([], $other),
            'kid sconosciuto' => $this->token([], null, [], ['kid' => 'altro']),
            'alg diverso' => $this->token([], null, [], ['alg' => 'HS256']),
            'aud sbagliato' => $this->token(['aud' => 'associazionesemplice-saas']),
            'iss sbagliato' => $this->token(['iss' => 'https://altro.test']),
            'typ sbagliato' => $this->token(['typ' => 'staff_people']),
            'scaduto' => $this->token(['iat' => time() - 400, 'exp' => time() - 300]),
            'troppo lungo' => $this->token(['exp' => time() + 600]),
            'campo sconosciuto' => $this->token(['admin' => true]),
            'senza sid' => $this->token([], null, ['sid']),
            'sid corto' => $this->token(['sid' => 'abc']),
            'area saas' => $this->token(['area' => 'saas']),
            'livello vendita' => $this->token(['level' => 'vendita']),
            'email maiuscola' => $this->token(['sub' => 'Agente@Example.it']),
            'areas non valide' => $this->token(['areas' => ['supporto' => ['admin']]]),
            'mfa non booleano' => $this->token(['mfa' => 'si']),
            'non un token' => 'abc.def',
        ];
        foreach ($cases as $name => $token) {
            $this->post('/staff/sso', ['token' => $token])->assertRedirect(route('admin.login'));
            $this->assertGuest();
        }
        $this->assertSame(0, User::count());
    }

    public function test_token_is_single_use(): void
    {
        $t = $this->token();
        $this->post('/staff/sso', ['token' => $t])->assertRedirect('/admin');
        $this->post('/logout');
        $this->post('/staff/sso', ['token' => $t])->assertRedirect(route('admin.login'));
    }

    public function test_agent_can_answer_tickets_but_not_manage_the_rest(): void
    {
        $this->staffLogin($this->token());

        $this->get('/admin/ticket')->assertOk();
        foreach (['/admin/argomenti', '/admin/articoli', '/admin/piani', '/admin/hub', '/admin/utenti', '/admin/email'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_admin_sees_the_users_page_and_it_calls_the_portal_with_the_actor(): void
    {
        $this->staffLogin($this->token(['sub' => 'capo@example.it', 'level' => 'admin']));
        Http::fake(['portale.test/api/staff/v1/people/list' => Http::response(['people' => [['email' => 'agente@example.it', 'name' => 'Agente', 'level' => 'agente', 'active' => true, 'locked' => false]]])]);

        $this->get('/admin/utenti')->assertOk()->assertSee('agente@example.it');

        Http::assertSent(function ($request) {
            $claims = StaffJwt::decode((string) $request['token'], ['supporto-test' => $this->ourPublic]);

            return $claims !== null && $claims['typ'] === 'staff_people' && $claims['act'] === 'capo@example.it'
                && $claims['area'] === 'supporto' && $claims['aud'] === 'https://portale.test' && $claims['exp'] - $claims['iat'] <= 120;
        });
    }

    public function test_invite_errors_from_the_portal_are_shown_and_reconfirm_redirects(): void
    {
        $this->staffLogin($this->token(['sub' => 'capo@example.it', 'level' => 'admin']));

        Http::fake(['portale.test/*' => Http::sequence()
            ->push(['error' => 'livello_troppo_alto'], 403)
            ->push(['error' => 'riconferma_richiesta'], 403)
            ->push(['ok' => true])]);
        $this->post('/admin/utenti/invita', ['email' => 'x@example.it', 'level' => 'admin'])->assertSessionHasErrors('staff');

        $this->post('/admin/utenti/disattiva', ['email' => 'x@example.it'])->assertRedirect('https://portale.test/staff/riconferma');

        $this->post('/admin/utenti/invita', ['email' => 'x@example.it', 'level' => 'agente'])->assertSessionHas('ok');
    }

    public function test_session_expires_after_idle_and_after_max_duration(): void
    {
        $this->staffLogin($this->token());
        $this->get('/admin/ticket')->assertOk();

        $this->travel(121)->minutes();
        $this->get('/admin/ticket')->assertRedirect('https://portale.test/staff/accedi?area=supporto');
        $this->assertGuest();
    }

    public function test_recheck_every_five_minutes_adopts_level_and_closes_when_revoked(): void
    {
        $this->staffLogin($this->token(['level' => 'admin']));
        Http::fake(['portale.test/api/staff/v1/session-check' => Http::sequence()
            ->push(['valid' => true, 'level' => 'agente'])
            ->push(['valid' => false, 'level' => null])]);

        $this->get('/admin/ticket')->assertOk();
        Http::assertNothingSent();

        $this->travel(6)->minutes();
        $this->get('/admin/argomenti')->assertForbidden();
        $this->assertSame('agent', User::where('email', 'agente@example.it')->value('global_role'));

        $this->travel(6)->minutes();
        $this->get('/admin/ticket')->assertRedirect('https://portale.test/staff/accedi?area=supporto');
        $this->assertGuest();
        $this->assertNull(User::where('email', 'agente@example.it')->value('global_role'));
    }

    public function test_session_closes_after_two_failed_checks_in_a_row(): void
    {
        $this->staffLogin($this->token());
        Http::fake(['portale.test/*' => Http::response('', 500)]);

        $this->travel(6)->minutes();
        $this->get('/admin/ticket')->assertOk();
        $this->travel(6)->minutes();
        $this->get('/admin/ticket')->assertRedirect('https://portale.test/staff/accedi?area=supporto');
    }

    public function test_local_login_can_be_switched_off(): void
    {
        config(['support.staff_sso.local_login' => false]);

        $this->post('/admin/login', ['email' => 'a@example.it', 'password' => 'x'])->assertForbidden();
        $this->get('/admin/login')->assertOk()->assertSee('Entra dal portale')->assertDontSee('Password dimenticata');
    }
}
