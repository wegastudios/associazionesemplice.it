<?php

namespace Tests\Feature\Site;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Site\CustomerLink;
use App\Services\Site\HumanCheck;
use App\Services\Site\SsoToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\SiteTestCase;

class AttackSiteTest extends SiteTestCase
{
    private const SECRET = 'segreto-di-prova-lungo-almeno-trentadue-caratteri';

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.sso.secret' => self::SECRET, 'support.sso.portal' => 'https://portale.test', 'app.url' => 'https://supporto.test']);
        Cache::flush();
    }

    private function b(string $x): string
    {
        return rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    }

    private function token(array $o = [], ?string $secret = null, ?array $drop = null): string
    {
        $claims = $o + ['v' => 1, 'iss' => 'https://portale.test', 'aud' => 'https://supporto.test', 'sub' => 'staff@example.it', 'role' => 'support', 'iat' => time(), 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(16))];
        foreach ($drop ?? [] as $k) {
            unset($claims[$k]);
        }
        $p = $this->b(json_encode($claims));

        return $p.'.'.$this->b(hash_hmac('sha256', $p, $secret ?? self::SECRET, true));
    }

    public function test_sso_token_fuzz_is_all_rejected(): void
    {
        $cases = [
            'v=2' => $this->token(['v' => 2]), 'v stringa' => $this->token(['v' => '1']),
            'iat nel futuro' => $this->token(['iat' => time() + 3600, 'exp' => time() + 3700]),
            'senza jti' => $this->token([], null, ['jti']), 'jti corto' => $this->token(['jti' => 'abc']), 'jti non stringa' => $this->token(['jti' => 12345678901234567]),
            'senza aud' => $this->token([], null, ['aud']), 'senza iss' => $this->token([], null, ['iss']), 'senza sub' => $this->token([], null, ['sub']),
            'role array' => $this->token(['role' => ['admin']]), 'role Admin maiuscolo' => $this->token(['role' => 'Admin']), 'role con spazio' => $this->token(['role' => 'admin ']),
            'sub con newline' => $this->token(['sub' => "a@b.it\nBcc: x@y.it"]), 'sub senza dominio' => $this->token(['sub' => 'a@']),
            'exp stringa nel passato' => $this->token(['exp' => '1']), 'exp negativo' => $this->token(['exp' => -1]),
            'due punti nel mezzo' => $this->token().'.x', 'vuoto' => '', 'solo punto' => '.', 'firma vuota' => $this->b('{}').'.',
            'payload non json' => $this->b('xx').'.'.$this->b(hash_hmac('sha256', $this->b('xx'), self::SECRET, true)),
            'aud con altra scheme' => $this->token(['aud' => 'http://supporto.test']),
            'enorme' => str_repeat('A', 500000).'.'.str_repeat('B', 100),
            'null byte' => $this->token()."\0",
        ];
        foreach ($cases as $name => $t) {
            $this->assertNull(SsoToken::verify((string) $t), "token accettato per errore: $name");
        }
    }

    public function test_sso_signature_bit_flips_are_rejected(): void
    {
        $t = $this->token();
        [$p, $s] = explode('.', $t);
        for ($i = 0; $i < strlen($s); $i += 7) {
            $s2 = $s;
            $s2[$i] = $s2[$i] === 'A' ? 'B' : 'A';
            $this->assertNull(SsoToken::verify($p.'.'.$s2));
        }
        // payload alterato con la firma vecchia
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $claims['role'] = 'admin';
        $this->assertNull(SsoToken::verify($this->b(json_encode($claims)).'.'.$s));
    }

    public function test_sso_trailing_slash_and_case_of_urls(): void
    {
        $this->assertNotNull(SsoToken::verify($this->token(['aud' => 'https://supporto.test/', 'iss' => 'https://portale.test/'])));
        $this->assertNull(SsoToken::verify($this->token(['aud' => 'https://SUPPORTO.test'])), 'host in maiuscolo: confronto stretto');
    }

    public function test_sso_replay_across_cache_flush_window(): void
    {
        $t = $this->token();
        $this->get('/sso?t='.$t)->assertRedirect();
        $this->get('/sso?t='.$t)->assertRedirect(route('help.index'));
    }

    public function test_sso_staff_does_not_downgrade_into_customer_privileges_and_customer_never_admin(): void
    {
        $this->get('/sso?t='.$this->token(['role' => 'customer', 'sub' => 'cli@example.it']))->assertRedirect(route('ticket.mine'));
        $this->assertNull(User::where('email', 'cli@example.it')->first(), 'un cliente non crea utenti locali');
        $this->get('/admin/ticket')->assertRedirect();
        $this->assertGuest();
    }

    public function test_customer_with_existing_local_admin_email_does_not_gain_admin_session(): void
    {
        User::factory()->create(['email' => 'boss@example.it', 'global_role' => 'admin', 'sso_managed' => false]);
        $this->get('/sso?t='.$this->token(['role' => 'customer', 'sub' => 'boss@example.it']));
        $this->assertGuest();
        $this->get('/admin/ticket')->assertRedirect();
    }

    public function test_every_admin_route_requires_login(): void
    {
        $n = 0;
        foreach (Route::getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'admin') || in_array($r->uri(), ['admin/login', 'admin/password-dimenticata', 'admin/nuova-password', 'admin/nuova-password/{token}'], true)) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', '1', $r->uri());
            foreach ($r->methods() as $m) {
                if ($m === 'HEAD') {
                    continue;
                }
                $resp = $this->call($m, '/'.$uri);
                $this->assertContains($resp->getStatusCode(), [302, 401, 403, 419], "$m /$uri risponde ".$resp->getStatusCode().' senza login');
                $n++;
            }
        }
        $this->assertGreaterThan(20, $n);
    }

    public function test_customer_session_cannot_reach_admin_routes(): void
    {
        $this->get('/sso?t='.$this->token(['role' => 'customer', 'sub' => 'cli@example.it']));
        foreach (['/admin/ticket', '/admin/articoli', '/admin/piani', '/admin/hub', '/admin/email', '/admin/password'] as $u) {
            $this->assertContains($this->get($u)->getStatusCode(), [302, 403], $u);
        }
    }

    public function test_signed_links_cannot_be_tampered_or_swapped(): void
    {
        $this->fakeHub();
        $a = $this->makeTicket(['hub_id' => 94000001]);
        $b = $this->makeTicket(['hub_id' => 94000002, 'request_uuid' => (string) Str::uuid(), 'customer_email' => 'altro@example.com']);
        $urlA = CustomerLink::show($a);
        $this->get($urlA)->assertOk();
        $this->get(str_replace('94000001', '94000002', $urlA))->assertForbidden();           // id cambiato, firma di A
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=00', $urlA))->assertForbidden();
        $this->get(preg_replace('/&?signature=[0-9a-f]+/', '', $urlA))->assertForbidden();   // senza firma
        $this->get(str_replace('expires=', 'expires=9', $urlA))->assertForbidden();           // scadenza spostata
        $this->get('/ticket/94000001')->assertForbidden();                                       // senza query
        $this->get('/ticket/'.$b->hub_id.'?signature=abc')->assertForbidden();
        $this->travel(25)->hours();
        $this->get(CustomerLink::show($a, 24))->assertOk();
        $old = CustomerLink::show($a, 1);
        $this->travel(2)->hours();
        $this->get($old)->assertForbidden();
        // il link di sola lettura (show) non vale per rispondere (route diversa)
        $reply = str_replace('/ticket/94000001', '/ticket/94000001/risposta', CustomerLink::show($a));
        $this->post($reply, ['body' => 'ciao ciao ciao'])->assertForbidden();
    }

    public function test_ticket_content_is_escaped_everywhere(): void
    {
        $xss = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $t = $this->makeTicket(['subject' => $xss, 'customer_name' => $xss]);
        $t->messages()->create(['author_type' => 'customer', 'author_name' => $xss, 'body' => $xss]);
        $this->get(CustomerLink::show($t))->assertOk()->assertDontSee('<script>alert(1)', false)->assertDontSee('<img src=x', false);
        $this->actingAs($this->admin())->get('/admin/ticket/'.$t->id)->assertOk()->assertDontSee('<script>alert(1)', false)->assertDontSee('<img src=x', false);
        $this->actingAs($this->admin())->get('/admin/ticket')->assertOk()->assertDontSee('<script>alert(1)', false);
    }

    public function test_mass_assignment_on_public_ticket_form_is_ignored(): void
    {
        $this->fakeHub(94000555);
        Mail::fake();
        $this->withSession(['customer' => ['email' => 'cli@example.it', 'name' => 'Cliente', 'plan_id' => null, 'at' => now()->timestamp]]);
        $this->post('/ticket', [
            'privacy_accepted' => '1', 'form_token' => app(HumanCheck::class)->issue(now()->subSeconds(30)),
            'customer_name' => 'X', 'customer_email' => 'vittima@example.com', 'subject' => 'Prova', 'body' => 'Corpo del messaggio lungo',
            'status' => 'closed', 'hub_id' => 1, 'id' => 999, 'priority' => 4, 'created_at' => '2000-01-01',
        ]);
        $t = SupportTicket::firstOrFail();
        $this->assertSame('cli@example.it', $t->customer_email, 'email non falsificabile dal modulo');
        $this->assertSame('Cliente', $t->customer_name);
        $this->assertNotSame('closed', $t->status);
        $this->assertNotSame(1, (int) $t->hub_id);
        $this->assertNotSame(999, (int) $t->id);
        $this->assertNotSame('2000-01-01', $t->created_at?->format('Y-m-d'));
    }

    public function test_mine_lists_only_own_tickets_and_case_insensitive_email_does_not_leak(): void
    {
        $this->makeTicket(['hub_id' => 94000010, 'customer_email' => 'cli@example.it', 'subject' => 'MIO']);
        $this->makeTicket(['hub_id' => 94000011, 'request_uuid' => (string) Str::uuid(), 'customer_email' => 'altro@example.it', 'subject' => 'ALTRUI']);
        $this->makeTicket(['hub_id' => 94000012, 'request_uuid' => (string) Str::uuid(), 'customer_email' => 'cli@example.it.evil.com', 'subject' => 'ALTRUI2']);
        $this->makeTicket(['hub_id' => 94000013, 'request_uuid' => (string) Str::uuid(), 'customer_email' => '%@example.it', 'subject' => 'JOLLY']);
        $this->get('/sso?t='.$this->token(['role' => 'customer', 'sub' => 'cli@example.it']));
        $r = $this->get('/i-miei-ticket')->assertOk()->assertSee('MIO');
        $r->assertDontSee('ALTRUI')->assertDontSee('JOLLY');
    }

    public function test_help_center_search_resists_sql_and_like_wildcards(): void
    {
        foreach (["' OR 1=1 --", '%', '_', '\\', str_repeat('a', 5000), "a\0b", '<script>', '"; DROP TABLE users; --'] as $q) {
            $r = $this->get('/cerca?q='.rawurlencode($q));
            $this->assertContains($r->getStatusCode(), [200, 302, 422], 'ricerca: '.substr($q, 0, 20));
            $this->assertStringNotContainsString('<script>', (string) $r->getContent());
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_login_is_rate_limited_and_does_not_enumerate(): void
    {
        User::factory()->create(['email' => 'adm@example.it', 'global_role' => 'admin', 'password' => bcrypt('Giusta-123456')]);
        $unknown = $this->post('/admin/login', ['email' => 'nessuno@example.it', 'password' => 'x'])->assertSessionHasErrors('email');
        $known = $this->post('/admin/login', ['email' => 'adm@example.it', 'password' => 'x'])->assertSessionHasErrors('email');
        $this->assertSame(session('errors')->get('email'), ['Credenziali non valide.'], 'stesso messaggio per email nota');
        for ($i = 0; $i < 6; $i++) {
            $this->post('/admin/login', ['email' => 'adm@example.it', 'password' => 'no'.$i]);
        }
        $this->post('/admin/login', ['email' => 'adm@example.it', 'password' => 'Giusta-123456'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_security_headers_on_errors_and_404(): void
    {
        foreach (['/non-esiste', '/admin/ticket', '/ticket/1'] as $u) {
            $r = $this->get($u);
            $this->assertSame('DENY', $r->headers->get('X-Frame-Options'), $u);
            $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'), $u);
            $this->assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'), $u);
        }
    }

    public function test_debug_pages_do_not_leak_on_exceptions(): void
    {
        config(['app.debug' => false]);
        $r = $this->get('/articoli/abc');
        $this->assertSame(404, $r->getStatusCode());
        $r = $this->get('/argomenti/'.str_repeat('a', 3000));
        $this->assertSame(404, $r->getStatusCode());
        $this->assertStringNotContainsString('vendor/laravel', (string) $r->getContent());
    }
}
