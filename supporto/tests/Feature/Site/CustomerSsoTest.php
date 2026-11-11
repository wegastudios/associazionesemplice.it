<?php

namespace Tests\Feature\Site;

use App\Models\SupportTicket;
use App\Services\Site\HumanCheck;
use App\Services\Staff\StaffJwt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\SiteTestCase;

class CustomerSsoTest extends SiteTestCase
{
    private string $secret;

    private string $staffSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $cust = sodium_crypto_sign_keypair();
        $staff = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($cust);
        $this->staffSecret = sodium_crypto_sign_secretkey($staff);
        config([
            'app.url' => 'https://supporto.test',
            'support.staff_sso.issuer' => 'https://portale.test/saas',
            'support.customer_sso.keys' => 'cust-test:'.StaffJwt::b64(sodium_crypto_sign_publickey($cust)),
            'support.staff_sso.portal_keys' => 'staff-test:'.StaffJwt::b64(sodium_crypto_sign_publickey($staff)),
        ]);
        Cache::flush();
    }

    private function token(array $override = [], ?string $secret = null, string $kid = 'cust-test', array $drop = []): string
    {
        $claims = array_diff_key($override + [
            'iss' => 'https://portale.test/saas', 'aud' => 'associazionesemplice-supporto', 'typ' => 'customer_sso',
            'iat' => time(), 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(16)),
            'sub' => 'cus_123', 'email' => 'mario@example.it', 'name' => 'Mario Rossi',
            'plans' => [['product' => 'plugin', 'plan' => 'procontabile', 'support_level' => 'paid', 'status' => 'active']],
        ], array_flip($drop));
        $h = StaffJwt::b64(json_encode(['typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => $kid]));
        $p = StaffJwt::b64(json_encode($claims));

        return $h.'.'.$p.'.'.StaffJwt::b64(sodium_crypto_sign_detached($h.'.'.$p, $secret ?? $this->secret));
    }

    public function test_valid_token_opens_the_customer_session_and_links_old_tickets_by_email(): void
    {
        $old = $this->makeTicket(['hub_id' => 94000500, 'customer_email' => 'mario@example.it', 'subject' => 'VECCHIO']);
        $other = $this->makeTicket(['hub_id' => 94000501, 'request_uuid' => (string) Str::uuid(), 'customer_email' => 'altro@example.it', 'subject' => 'ALTRUI']);

        $this->post('/accedi/sso', ['token' => $this->token()])->assertRedirect(route('ticket.mine'));

        $this->assertSame('cus_123', $old->fresh()->customer_id);
        $this->assertNull($other->fresh()->customer_id);
        $this->assertSame('cus_123', session('customer.customer_id'));
        $this->assertSame('paid', session('customer.plans.0.support_level'));

        $this->withSession(session()->all())->get('/i-miei-ticket')->assertOk()->assertSee('VECCHIO')->assertDontSee('ALTRUI');
    }

    public function test_bad_tokens_are_all_refused(): void
    {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $cases = [
            'firma di altri' => $this->token([], $other),
            'chiave staff (non vale per i clienti)' => $this->token([], $this->staffSecret, 'staff-test'),
            'kid sconosciuto' => $this->token([], null, 'altro'),
            'typ staff' => $this->token(['typ' => 'staff_sso']),
            'aud sbagliato' => $this->token(['aud' => 'associazionesemplice-saas']),
            'iss sbagliato' => $this->token(['iss' => 'https://altro.test']),
            'scaduto' => $this->token(['iat' => time() - 400, 'exp' => time() - 300]),
            'troppo lungo' => $this->token(['exp' => time() + 600]),
            'campo sconosciuto' => $this->token(['role' => 'admin']),
            'senza sub' => $this->token([], null, 'cust-test', ['sub']),
            'sub con caratteri strani' => $this->token(['sub' => 'a b;c']),
            'senza email' => $this->token([], null, 'cust-test', ['email']),
            'email maiuscola' => $this->token(['email' => 'Mario@Example.it']),
            'non un token' => 'abc',
        ];
        foreach ($cases as $name => $token) {
            $this->post('/accedi/sso', ['token' => $token])->assertRedirect(route('help.index'));
            $this->assertNull(session('customer'), $name);
        }
    }

    public function test_token_is_single_use_and_staff_tokens_do_not_open_customer_sessions(): void
    {
        $t = $this->token();
        $this->post('/accedi/sso', ['token' => $t])->assertRedirect(route('ticket.mine'));
        $this->flushSession();
        $this->post('/accedi/sso', ['token' => $t])->assertRedirect(route('help.index'));
        $this->assertNull(session('customer'));
    }

    public function test_ticket_keeps_the_customer_id_from_the_session(): void
    {
        Mail::fake();
        $this->fakeHub(94000600);
        $this->post('/accedi/sso', ['token' => $this->token()]);
        $this->withSession(session()->all());

        $this->post('/ticket', [
            'privacy_accepted' => '1', 'form_token' => app(HumanCheck::class)->issue(now()->subSeconds(30)),
            'customer_name' => 'X', 'customer_email' => 'finto@example.com', 'product' => 'plugin',
            'subject' => 'Aiuto', 'body' => 'Non riesco ad entrare nel pannello.',
        ])->assertSessionHasNoErrors();

        $t = SupportTicket::firstOrFail();
        $this->assertSame('cus_123', $t->customer_id);
        $this->assertSame('mario@example.it', $t->customer_email);
        $this->assertSame(['procontabile', 'paid'], [$t->plan_slug, $t->support_level]);
    }
}
