<?php

namespace Tests\Feature\Site;

use App\Models\Article;
use App\Models\Plan;
use App\Models\Section;
use App\Models\Topic;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\SiteTestCase;

class SiteAdminTest extends SiteTestCase
{
    public function test_panel_requires_admin_login(): void
    {
        $this->get('/admin/ticket')->assertRedirect('/admin/login');
        $this->get('/admin/argomenti')->assertRedirect('/admin/login');
    }

    public function test_staff_manages_topics_sections_articles_and_plans_on_their_own(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/argomenti', ['name' => 'Accesso e password', 'is_public' => '1', 'sort' => 1])->assertSessionHasNoErrors();
        $topic = Topic::firstOrFail();
        $this->assertSame('accesso-e-password', $topic->slug);

        $this->actingAs($admin)->post("/admin/argomenti/{$topic->id}/sezioni", ['name' => 'Primo accesso'])->assertSessionHasNoErrors();
        $section = Section::firstOrFail();

        $this->actingAs($admin)->post('/admin/articoli', [
            'section_id' => $section->id, 'title' => 'Reimposta la password', 'body' => "Passo 1\nPasso 2", 'is_published' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Article::firstOrFail()->is_published);

        $this->actingAs($admin)->post('/admin/piani', ['name' => 'Elite', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('Elite', Plan::firstOrFail()->name);
    }

    public function test_topics_with_the_same_name_get_distinct_slugs(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/argomenti', ['name' => 'Fatture']);
        $this->actingAs($admin)->post('/admin/argomenti', ['name' => 'Fatture']);

        $this->assertSame(['fatture', 'fatture-2'], Topic::orderBy('id')->pluck('slug')->all());
    }

    public function test_deleting_a_topic_removes_its_sections_and_articles(): void
    {
        $admin = $this->admin();
        $topic = Topic::create(['name' => 'T', 'slug' => 't']);
        $section = $topic->sections()->create(['name' => 'S', 'slug' => 's']);
        $section->articles()->create(['title' => 'A', 'slug' => 'a', 'body' => 'x']);

        $this->actingAs($admin)->post("/admin/argomenti/{$topic->id}/elimina")->assertSessionHasNoErrors();

        $this->assertSame(0, Section::count());
        $this->assertSame(0, Article::count());
    }

    public function test_status_change_is_synced_with_the_hub(): void
    {
        $this->fakeHub();
        $ticket = $this->makeTicket();

        $this->actingAs($this->admin())->post("/admin/ticket/{$ticket->id}/stato", ['status' => 'solved'])->assertSessionHasNoErrors();

        $this->assertSame('solved', $ticket->refresh()->status);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/api/v1/tickets/94000001') && $r['status'] === 'solved');
    }

    public function test_reopening_refused_by_the_hub_leaves_the_ticket_closed(): void
    {
        Http::fake(['hub.test/api/v1/tickets/*' => Http::response(['error' => ['code' => 'REOPEN_DISABLED', 'message' => 'no']], 409)]);
        $ticket = $this->makeTicket(['status' => 'closed']);

        $this->actingAs($this->admin())->post("/admin/ticket/{$ticket->id}/stato", ['status' => 'open'])
            ->assertSessionHasErrors('hub');

        $this->assertSame('closed', $ticket->refresh()->status);
    }

    public function test_priority_not_allowed_by_the_hub_is_not_applied(): void
    {
        Http::fake(['hub.test/api/v1/tickets/*' => Http::response(['error' => ['code' => 'PRIORITY_NOT_ALLOWED', 'message' => 'no', 'allowed_priorities' => [0, 1, 2]]], 422)]);
        $ticket = $this->makeTicket();

        $this->actingAs($this->admin())->post("/admin/ticket/{$ticket->id}/priorita", ['priority' => 4])->assertSessionHasErrors('hub');

        $this->assertSame(2, $ticket->refresh()->priority);
    }

    public function test_priority_allowed_by_the_hub_is_applied(): void
    {
        $this->fakeHub(allowed: [0, 1, 2, 3, 4]);
        $ticket = $this->makeTicket();

        $this->actingAs($this->admin())->post("/admin/ticket/{$ticket->id}/priorita", ['priority' => 4])->assertSessionHasNoErrors();

        $this->assertSame(4, $ticket->refresh()->priority);
    }

    public function test_internal_priority_and_assignee_are_local_only(): void
    {
        Http::fake();
        $admin = $this->admin();
        $ticket = $this->makeTicket();

        $this->actingAs($admin)->post("/admin/ticket/{$ticket->id}/gestione", ['assigned_to' => $admin->id, 'internal_priority' => 'Da fare oggi'])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame($admin->id, $ticket->assigned_to);
        $this->assertSame('Da fare oggi', $ticket->internal_priority);
        Http::assertNothingSent();
    }

    public function test_pending_ticket_can_be_registered_by_hand(): void
    {
        $this->fakeHub(94000999);
        Mail::fake();
        $ticket = $this->makeTicket(['hub_id' => null]);

        $this->actingAs($this->admin())->post("/admin/ticket/{$ticket->id}/registra")->assertSessionHas('ok');

        $this->assertSame(94000999, $ticket->refresh()->hub_id);
    }

    public function test_queue_lists_pending_tickets_and_shows_the_hub_number(): void
    {
        $this->makeTicket(['hub_id' => 94000001, 'subject' => 'Registrato']);
        $this->makeTicket(['hub_id' => null, 'subject' => 'In coda']);

        $this->actingAs($this->admin())->get('/admin/ticket')
            ->assertSee('94000001')->assertSee('in attesa')->assertSee('1 ticket in attesa di registrazione');
    }

    public function test_hub_status_page_verifies_the_link_and_shows_the_rules(): void
    {
        $this->fakeHub(allowed: [0, 1, 2]);

        $this->actingAs($this->admin())->post('/admin/hub/verifica')->assertSessionHas('hub_rules');
    }

    public function test_hub_status_reports_rejected_credentials(): void
    {
        Http::fake(['hub.test/*' => Http::response(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Credenziali non valide.']], 401)]);

        $this->actingAs($this->admin())->post('/admin/hub/verifica')->assertSessionHasErrors('hub');
    }
}
