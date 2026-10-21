<?php

namespace Tests\Feature\Site;

use App\Models\Plan;
use App\Models\User;
use App\Services\Site\SsoToken;
use Illuminate\Support\Facades\Cache;
use Tests\SiteTestCase;

class SsoAndSecurityTest extends SiteTestCase
{
    private const SECRET = 'segreto-di-prova-lungo-almeno-trentadue-caratteri';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'support.sso.secret' => self::SECRET,
            'support.sso.portal' => 'https://portale.test',
            'app.url' => 'https://supporto.test',
        ]);
        Cache::flush();
    }

    private function token(array $override = [], ?string $secret = null): string
    {
        $b = fn (string $x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
        $payload = $b(json_encode($override + [
            'v' => 1, 'iss' => 'https://portale.test', 'aud' => 'https://supporto.test', 'sub' => 'staff@example.it',
            'role' => 'support', 'iat' => time(), 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(16)),
        ]));

        return $payload.'.'.$b(hash_hmac('sha256', $payload, $secret ?? self::SECRET, true));
    }

    public function test_valid_token_is_accepted_once(): void
    {
        $t = $this->token();
        $this->assertSame('staff@example.it', SsoToken::verify($t)['sub']);
        $this->assertNull(SsoToken::verify($t), 'un token si usa una sola volta');
    }

    public function test_wrong_signature_audience_issuer_or_expiry_is_rejected(): void
    {
        $this->assertNull(SsoToken::verify($this->token([], 'altro-segreto-altro-segreto-altro-segreto')));
        $this->assertNull(SsoToken::verify($this->token(['aud' => 'https://altro.test'])));
        $this->assertNull(SsoToken::verify($this->token(['iss' => 'https://altro.test'])));
        $this->assertNull(SsoToken::verify($this->token(['exp' => time() - 1])));
        $this->assertNull(SsoToken::verify($this->token(['role' => 'root'])));
        $this->assertNull(SsoToken::verify($this->token(['sub' => 'non-una-email'])));
    }

    public function test_sso_is_off_without_a_long_secret(): void
    {
        config(['support.sso.secret' => 'corto']);
        $this->assertNull(SsoToken::verify($this->token([], 'corto')));
    }

    public function test_staff_token_creates_a_portal_managed_admin(): void
    {
        $this->get('/sso?t='.$this->token())->assertRedirect(route('admin.home'));

        $user = User::where('email', 'staff@example.it')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->sso_managed);
    }

    public function test_portal_managed_users_cannot_use_password_login_or_reset(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.it', 'password' => 'Password-lunga-123', 'global_role' => 'admin', 'sso_managed' => true]);

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'Password-lunga-123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/admin/password-dimenticata', ['email' => $user->email])->assertSessionHas('ok');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_removed_staff_loses_admin_when_the_portal_says_customer(): void
    {
        $this->get('/sso?t='.$this->token())->assertRedirect(route('admin.home'));
        $this->post('/admin/logout');

        $this->get('/sso?t='.$this->token(['role' => 'customer']))->assertRedirect(route('ticket.mine'));

        $this->assertFalse(User::where('email', 'staff@example.it')->firstOrFail()->isAdmin());
    }

    public function test_portal_managed_admin_session_expires_after_eight_hours(): void
    {
        $this->get('/sso?t='.$this->token())->assertRedirect(route('admin.home'));
        $this->get('/admin/ticket')->assertOk();

        $this->travel(9)->hours();
        $this->get('/admin/ticket')->assertRedirect('https://portale.test/supporto/accedi');
    }

    public function test_local_admin_is_not_affected_by_sso_expiry(): void
    {
        $this->actingAs($this->admin());
        $this->travel(9)->hours();
        $this->get('/admin/ticket')->assertOk();
    }

    public function test_customer_must_be_logged_in_to_open_a_ticket_and_identity_is_locked(): void
    {
        $this->get('/ticket/nuovo')->assertRedirect('https://portale.test/supporto/accedi');
        $this->post('/ticket', ['subject' => 'x'])->assertRedirect('https://portale.test/supporto/accedi');

        $pro = Plan::create(['name' => 'Pro', 'sort' => 1, 'is_active' => true]);
        $this->get('/sso?t='.$this->token(['role' => 'customer', 'sub' => 'cli@example.it', 'plan' => 'pro']))->assertRedirect(route('ticket.mine'));

        $this->get('/ticket/nuovo')->assertOk()->assertSee('readonly', false)->assertSee('cli@example.it');
        $this->assertSame($pro->id, session('customer.plan_id'));
    }

    public function test_customer_session_expires_after_inactivity(): void
    {
        $this->get('/sso?t='.$this->token(['role' => 'customer', 'sub' => 'cli@example.it']));
        $this->travel(31)->minutes();

        $this->get('/ticket/nuovo')->assertRedirect('https://portale.test/supporto/accedi');
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('X-Powered-By'));
    }
}
