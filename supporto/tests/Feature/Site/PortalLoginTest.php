<?php

namespace Tests\Feature\Site;

use App\Models\User;
use Tests\SiteTestCase;

class PortalLoginTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['support.staff_sso.issuer' => 'https://portale.test/saas']);
    }

    public function test_login_page_is_kept_while_portal_login_is_off(): void
    {
        config(['support.staff_sso.portal_login' => false, 'support.staff_sso.local_login' => true]);

        $this->get('/admin/login')->assertOk()->assertSee('Accesso amministratore');
    }

    public function test_with_portal_login_the_support_has_no_login_or_reset_forms(): void
    {
        config(['support.staff_sso.portal_login' => true]);

        $this->get('/admin/login')->assertRedirect('https://portale.test/accedi');
        $this->post('/admin/login', ['email' => 'a@b.it', 'password' => 'x'])->assertNotFound();
        $this->get('/admin/password-dimenticata')->assertNotFound();
        $this->post('/admin/password-dimenticata', ['email' => 'a@b.it'])->assertNotFound();
    }

    public function test_urls_can_be_configured_and_logout_goes_to_the_portal(): void
    {
        config(['support.staff_sso.portal_login' => true, 'support.staff_sso.login_url' => 'https://portale.test/accedi?da=supporto']);
        $this->get('/admin/login')->assertRedirect('https://portale.test/accedi?da=supporto');

        config(['support.staff_sso.logout_url' => null]);
        $user = User::factory()->create(['global_role' => 'admin']);
        $this->actingAs($user)->post('/admin/logout')->assertRedirect('https://portale.test/account/esci');
    }
}
