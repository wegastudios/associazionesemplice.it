<?php

namespace Tests\Feature\Site;

use Illuminate\Support\Str;
use Tests\SiteTestCase;

class AdminOrderTest extends SiteTestCase
{
    /** Senza login la risposta non deve dire se un ticket esiste (il controllo di accesso viene prima del binding della rotta). */
    public function test_unauthenticated_admin_routes_do_not_reveal_which_records_exist(): void
    {
        $t = $this->makeTicket();

        $exists = $this->get('/admin/ticket/'.$t->getRouteKey())->getStatusCode();
        $missing = $this->get('/admin/ticket/'.Str::uuid())->getStatusCode();

        $this->assertSame($exists, $missing);
        $this->assertSame(302, $exists);
        $this->assertSame(302, $this->get('/admin/articoli/999999')->getStatusCode());
        $this->assertSame(302, $this->post('/admin/ticket/'.Str::uuid().'/stato', ['status' => 'closed'])->getStatusCode());
    }
}
