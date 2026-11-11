<?php

namespace Tests\Feature\Site;

use Tests\SiteTestCase;

class CommonBarTest extends SiteTestCase
{
    public function test_public_pages_have_the_common_bar_with_absolute_links(): void
    {
        config(['support.sso.portal' => 'https://portale.test']);

        $this->get('/')->assertOk()
            ->assertSee('href="https://portale.test/"', false)
            ->assertSee('href="https://portale.test/accedi"', false)
            ->assertSee('Supporto')
            ->assertDontSee('Esci');
    }

    public function test_error_pages_always_offer_a_way_out(): void
    {
        config(['support.sso.portal' => 'https://portale.test']);

        $this->get('/pagina-che-non-esiste')->assertNotFound()
            ->assertSee('Torna al tuo account')->assertSee('Vai alla home')
            ->assertSee('href="https://portale.test/account"', false);
    }
}
