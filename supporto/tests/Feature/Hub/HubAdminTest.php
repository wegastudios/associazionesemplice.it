<?php

namespace Tests\Feature\Hub;

use App\Models\Site;
use App\Models\User;
use App\Services\Hub\SupportSettings;
use Tests\HubTestCase;

class HubAdminTest extends HubTestCase
{
    public function test_panel_requires_admin_login(): void
    {
        $this->get('/admin/casella')->assertRedirect('/admin/login');
        $this->get('/admin/portali')->assertRedirect('/admin/login');
    }

    public function test_non_admin_users_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/portali')->assertForbidden();
    }

    public function test_admin_registers_a_portal_and_sees_credentials_once(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/portali', [
            'name' => 'AssociazioneSemplice',
            'portal_email' => 'portale@associazionesemplice.it',
            'url' => 'https://supporto.associazionesemplice.it',
            'allowed_priorities' => [0, 1, 2],
            'allow_reopen' => '1',
            'notify_emails' => "tecnico@example.com\nlui@example.com",
        ]);

        $response->assertOk()->assertSee('wsc_');

        $site = Site::firstOrFail();
        $this->assertSame([0, 1, 2], $site->priorities());
        $this->assertTrue($site->allow_reopen);
        $this->assertSame(['tecnico@example.com', 'lui@example.com'], $site->notify_emails);

        // Il segreto non e salvato in chiaro e la pagina del portale non lo mostra piu
        preg_match('/wsc_[a-f0-9]+\.[A-Za-z0-9_-]+/', $response->getContent(), $m);
        $this->assertNotEmpty($m);
        $secret = explode('.', $m[0], 2)[1];
        $this->assertStringNotContainsString($secret, json_encode($site->getAttributes()));
        $this->actingAs($this->admin())->get("/admin/portali/{$site->id}")->assertDontSee($secret, false);
    }

    public function test_at_least_one_priority_level_is_required(): void
    {
        $this->actingAs($this->admin())->post('/admin/portali', [
            'name' => 'X', 'portal_email' => 'x@example.com',
        ])->assertSessionHasErrors('allowed_priorities');

        $this->assertSame(0, Site::count());
    }

    public function test_admin_changes_rules_and_reopen(): void
    {
        ['site' => $site] = $this->makeSite();

        $this->actingAs($this->admin())->post("/admin/portali/{$site->id}/regole", [
            'name' => 'AssociazioneSemplice',
            'portal_email' => 'nuova@example.com',
            'allowed_priorities' => [0, 3, 4],
        ])->assertSessionHasNoErrors();

        $site->refresh();
        $this->assertSame([0, 3, 4], $site->priorities());
        $this->assertFalse($site->allow_reopen); // casella non spuntata
        $this->assertSame('nuova@example.com', $site->portal_email);
    }

    public function test_admin_rotates_and_revokes(): void
    {
        ['site' => $site, 'token' => $old] = $this->makeSite();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/portali/{$site->id}/ruota")->assertOk()->assertSee($site->client_id);
        $this->actingAs($admin)->post("/admin/portali/{$site->id}/revoca", ['confirm' => '1'])->assertRedirect();

        $this->assertTrue($site->refresh()->isRevoked());
        $this->getJson('/api/v1/rules', $this->bearer($old))->assertStatus(401);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.revoked']);
    }

    public function test_inbox_lists_tickets_of_all_portals_and_filters_by_portal_and_priority(): void
    {
        ['site' => $a] = $this->makeSite('Portale A', email: 'a@example.com');
        ['site' => $b] = $this->makeSite('Portale B', email: 'b@example.com');
        $this->makeTicket($a, ['subject' => 'Problema accesso A', 'priority' => 4]);
        $this->makeTicket($b, ['subject' => 'Domanda fattura B', 'priority' => 1]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/casella')->assertSee('Problema accesso A')->assertSee('Domanda fattura B');
        $this->actingAs($admin)->get('/admin/casella?site='.$a->id)->assertSee('Problema accesso A')->assertDontSee('Domanda fattura B');
        $this->actingAs($admin)->get('/admin/casella?priority=1')->assertSee('Domanda fattura B')->assertDontSee('Problema accesso A');
    }

    public function test_ticket_detail_page_shows_the_numeric_id_only(): void
    {
        ['site' => $site] = $this->makeSite();
        $ticket = $this->makeTicket($site, ['subject' => 'Dettaglio']);

        $this->actingAs($this->admin())->get("/admin/casella/{$ticket->id}")->assertOk()->assertSee('Ticket '.$ticket->id);
    }

    public function test_global_settings_save_labels_and_extra_addresses(): void
    {
        $this->actingAs($this->admin())->post('/admin/impostazioni', [
            'labels' => [0 => 'Trascurabile', 1 => '', 2 => 'Normale', 3 => 'Urgente', 4 => 'Critica'],
            'notify_emails' => "globale@example.com\nnon-valida",
        ])->assertSessionHasNoErrors();

        $settings = app(SupportSettings::class);
        $this->assertSame(['globale@example.com'], $settings->extraNotifyEmails());
        $labels = $settings->priorityLabels();
        $this->assertSame('Trascurabile', $labels[0]);
        $this->assertSame('Bassa', $labels[1]); // etichetta vuota: resta il nome predefinito
        $this->assertSame('Critica', $labels[4]);
    }
}
