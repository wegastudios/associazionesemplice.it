<?php

namespace Tests\Feature\Site;

use App\Mail\CustomerRepliedForStaff;
use App\Mail\NewTicketForStaff;
use App\Mail\StaffReplied;
use App\Mail\TicketReceived;
use App\Models\Plan;
use App\Models\SupportTicket;
use App\Models\Topic;
use App\Models\User;
use App\Services\Site\CustomerLink;
use App\Services\Site\HumanCheck;
use App\Services\Site\TicketRegistrar;
use App\Support\ProductPlans;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\SiteTestCase;

class PublicTicketTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Il ticket si apre solo da connessi: sessione del cliente come la crea l'accesso unico dal portale
        $this->withSession(['customer' => ['email' => 'mario@example.com', 'name' => null, 'plan_id' => null, 'at' => now()->timestamp]]);
    }

    /** Dati di un modulo compilato da una persona: informativa accettata, modulo aperto da piu di qualche secondo, trappola vuota. */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'privacy_accepted' => '1',
            'form_token' => app(HumanCheck::class)->issue(now()->subSeconds(30)),
            'customer_name' => 'Mario Rossi',
            'customer_email' => 'Mario@Example.com',
            'product' => 'plugin',
            'subject' => 'Non riesco ad accedere',
            'body' => 'Dopo l\'aggiornamento non entro piu nel pannello.',
        ];
    }

    public function test_customer_opens_a_ticket_and_gets_the_number_assigned_by_the_hub(): void
    {
        $this->freezeTime();
        Mail::fake();
        $this->fakeHub(94000123);
        $topic = Topic::create(['name' => 'Accesso', 'slug' => 'accesso', 'is_public' => true]);
        $plan = Plan::create(['name' => 'Pro']);

        // Il piano arriva dalla sessione del portale, non dal modulo
        $this->withSession(['customer' => ['email' => 'mario@example.com', 'name' => null, 'plan_id' => $plan->id, 'at' => now()->timestamp]]);

        $response = $this->post('/ticket', $this->form(['topic_id' => $topic->id]));

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame(94000123, $ticket->hub_id);
        $this->assertSame('mario@example.com', $ticket->customer_email);
        $response->assertRedirect(CustomerLink::show($ticket));
        Mail::assertQueued(TicketReceived::class, fn ($m) => $m->hasTo('mario@example.com'));

        // L'hub riceve argomento, piano e l'identificativo di richiesta, mai un id scelto dal sito
        Http::assertSent(function ($request) use ($ticket) {
            return $request->method() === 'POST'
                && $request->url() === 'https://hub.test/api/v1/tickets'
                && $request['request_uuid'] === $ticket->request_uuid
                && $request['topic'] === 'Accesso'
                && $request['plan'] === 'Pro'
                && ! isset($request['id'])
                && $request->hasHeader('Authorization', 'Bearer wsc_test.segreto');
        });
    }

    public function test_staff_gets_an_email_with_the_urgency_in_the_subject(): void
    {
        Mail::fake();
        $this->fakeHub(94000124);
        User::factory()->create(['email' => 'staff@example.com']);

        $this->post('/ticket', $this->form())->assertRedirect();

        Mail::assertQueued(NewTicketForStaff::class, function ($m) {
            return $m->hasTo('staff@example.com')
                && $m->envelope()->subject === '[Urgenza: Normale] Non riesco ad accedere'
                && str_contains($m->render(), 'Mario Rossi')
                && str_contains($m->render(), 'Non riesco ad accedere');
        });
    }

    public function test_initial_priority_is_the_lowest_allowed_when_normal_is_not_enabled(): void
    {
        Mail::fake();
        $this->fakeHub(allowed: [3, 4]);

        $this->post('/ticket', $this->form());

        $this->assertSame(3, SupportTicket::firstOrFail()->priority);
    }

    public function test_when_the_hub_is_down_the_ticket_waits_and_is_registered_later(): void
    {
        Mail::fake();
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->post('/ticket', $this->form())->assertRedirect(route('ticket.received'));

        $ticket = SupportTicket::firstOrFail();
        $this->assertNull($ticket->hub_id);
        $this->assertSame(1, $ticket->registration_attempts);
        Mail::assertNothingQueued();

        // Nuovo client HTTP: le simulazioni precedenti (hub spento) non devono rispondere ancora
        Http::swap(new Factory);
        $this->fakeHub(94000555);
        $this->assertSame(1, app(TicketRegistrar::class)->registerPending());

        $this->assertSame(94000555, $ticket->refresh()->hub_id);
        $this->assertNull($ticket->registration_error);
        Mail::assertQueued(TicketReceived::class);
    }

    public function test_the_site_never_makes_up_an_id_when_the_hub_is_not_configured(): void
    {
        Mail::fake();
        config(['support.hub.url' => '', 'support.hub.token' => null]);

        $this->post('/ticket', $this->form())->assertRedirect(route('ticket.received'));

        $this->assertNull(SupportTicket::firstOrFail()->hub_id);
    }

    public function test_priority_refused_by_the_hub_is_lowered_to_the_closest_allowed_level(): void
    {
        Mail::fake();
        Http::fake([
            'hub.test/api/v1/rules' => Http::response([], 500),
            'hub.test/api/v1/tickets' => Http::sequence()
                ->push(['error' => ['code' => 'PRIORITY_NOT_ALLOWED', 'message' => 'no', 'allowed_priorities' => [0, 1]]], 422)
                ->push(['data' => ['id' => 94000777]], 201),
        ]);

        $this->post('/ticket', $this->form());

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame(94000777, $ticket->hub_id);
        $this->assertSame(1, $ticket->priority);
    }

    public function test_honeypot_discards_bot_submissions(): void
    {
        $this->fakeHub();

        $this->post('/ticket', $this->form(['website' => 'http://spam.example']))->assertRedirect(route('ticket.received'));

        $this->assertSame(0, SupportTicket::count());
        Http::assertNothingSent();
    }

    public function test_ticket_gets_product_plan_and_support_level_from_the_portal_session(): void
    {
        $this->fakeHub(94000200);
        Mail::fake();
        $this->withSession(['customer' => ['email' => 'mario@example.com', 'name' => null, 'plan_id' => null, 'at' => now()->timestamp, 'plans' => [
            ['product' => 'plugin', 'plan' => 'procontabile', 'support_level' => 'standard', 'status' => 'active'],
            ['product' => 'saas', 'plan' => 'studio', 'support_level' => 'priority', 'status' => 'active'],
        ]]]);

        $this->post('/ticket', $this->form(['product' => 'plugin']))->assertSessionHasNoErrors();

        $t = SupportTicket::firstOrFail();
        $this->assertSame(['plugin', 'procontabile', 'standard'], [$t->product, $t->plan_slug, $t->support_level]);

        // Per fatturazione e account conta il livello piu alto tra i prodotti attivi
        $this->post('/ticket', $this->form(['product' => 'billing']));
        $this->assertSame(['studio', 'priority'], [SupportTicket::where('product', 'billing')->value('plan_slug'), SupportTicket::where('product', 'billing')->value('support_level')]);
    }

    public function test_inactive_plan_counts_as_base_level_and_missing_plans_leave_fields_empty(): void
    {
        $this->assertSame(['plan_slug' => 'procontabile', 'support_level' => 'base'], ProductPlans::pick([
            ['product' => 'plugin', 'plan' => 'procontabile', 'support_level' => 'priority', 'status' => 'suspended'],
        ], 'plugin'));
        $this->assertSame(['plan_slug' => null, 'support_level' => null], ProductPlans::pick([], 'saas'));
    }

    public function test_topic_must_belong_to_the_chosen_product_or_be_for_everyone(): void
    {
        $this->fakeHub(94000201);
        Mail::fake();
        $saas = Topic::create(['name' => 'Editor', 'slug' => 'editor', 'product' => 'saas', 'is_public' => true]);
        $all = Topic::create(['name' => 'Fatture', 'slug' => 'fatture', 'product' => null, 'is_public' => true]);

        $this->post('/ticket', $this->form(['topic_id' => $saas->id]))->assertSessionHasErrors('topic_id');
        $this->assertSame(0, SupportTicket::count());
        $this->post('/ticket', $this->form(['topic_id' => $all->id]))->assertSessionHasNoErrors();
        $this->assertSame(1, SupportTicket::count());
    }

    public function test_form_is_validated(): void
    {
        $this->fakeHub();

        $this->post('/ticket', ['form_token' => app(HumanCheck::class)->issue(now()->subSeconds(30)), 'customer_email' => 'non-una-email', 'body' => 'corto'])
            ->assertSessionHasErrors(['privacy_accepted', 'customer_name', 'customer_email', 'subject', 'body']);
    }

    public function test_explicit_privacy_acceptance_is_required(): void
    {
        $this->fakeHub();

        $data = $this->form();
        unset($data['privacy_accepted']);
        $this->post('/ticket', $data)->assertSessionHasErrors('privacy_accepted');

        $this->post('/ticket', $this->form(['privacy_accepted' => '0']))->assertSessionHasErrors('privacy_accepted');

        $this->assertSame(0, SupportTicket::count());
        Http::assertNothingSent();
    }

    public function test_acceptance_is_recorded_with_date_and_policy_version_and_sent_to_the_hub(): void
    {
        $this->freezeTime();
        Mail::fake();
        $this->fakeHub();
        config(['support.privacy.version' => '3']);

        $this->post('/ticket', $this->form());

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame(now()->getTimestamp(), $ticket->privacy_accepted_at->getTimestamp());
        $this->assertSame('3', $ticket->privacy_version);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r['privacy_version'] === '3'
            && $r['privacy_accepted_at'] === now()->toIso8601String());
    }

    public function test_form_shows_the_privacy_checkbox_the_hidden_trap_and_a_fresh_token(): void
    {
        $this->get('/ticket/nuovo')
            ->assertOk()
            ->assertSee('name="privacy_accepted"', false)
            ->assertSee('name="website"', false)
            ->assertSee('name="form_token"', false);
    }

    public function test_submission_that_is_too_fast_is_refused_and_the_text_is_kept(): void
    {
        $this->fakeHub();

        $this->post('/ticket', $this->form(['form_token' => app(HumanCheck::class)->issue(now())]))
            ->assertSessionHasErrors('form')
            ->assertSessionHasInput('subject', 'Non riesco ad accedere');

        $this->assertSame(0, SupportTicket::count());
        Http::assertNothingSent();
    }

    public function test_missing_forged_or_expired_token_is_refused(): void
    {
        $this->fakeHub();

        $missing = $this->form();
        unset($missing['form_token']);
        $this->post('/ticket', $missing)->assertSessionHasErrors('form');

        $this->post('/ticket', $this->form(['form_token' => 'inventato']))->assertSessionHasErrors('form');
        $this->post('/ticket', $this->form(['form_token' => Crypt::encryptString('altro|'.now()->getTimestamp())]))->assertSessionHasErrors('form');

        $this->post('/ticket', $this->form(['form_token' => app(HumanCheck::class)->issue(now()->subDays(2))]))->assertSessionHasErrors('form');

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_a_token_waited_out_is_accepted(): void
    {
        Mail::fake();
        $this->fakeHub();

        $token = app(HumanCheck::class)->issue(now());
        $this->travel(HumanCheck::MIN_SECONDS + 1)->seconds();

        $this->post('/ticket', $this->form(['form_token' => $token]));

        $this->assertSame(1, SupportTicket::count());
    }

    public function test_ticket_page_needs_a_valid_signed_link(): void
    {
        $ticket = $this->makeTicket();

        $this->get('/ticket/'.$ticket->hub_id)->assertForbidden();
        $this->get(CustomerLink::show($ticket))->assertOk()->assertSee('Aiuto, non entro piu.');
    }

    public function test_customer_page_hides_internal_notes(): void
    {
        $ticket = $this->makeTicket();
        $ticket->messages()->create(['author_type' => 'note', 'author_name' => 'Staff', 'body' => 'Nota riservata allo staff']);

        $this->get(CustomerLink::show($ticket))->assertOk()->assertDontSee('Nota riservata allo staff');
    }

    public function test_customer_replies_through_the_signed_form(): void
    {
        $this->fakeHub();
        $ticket = $this->makeTicket(['status' => 'pending']);

        $this->post(CustomerLink::reply($ticket), ['body' => 'Ecco altri dettagli.'])->assertRedirect();

        $this->assertSame('open', $ticket->refresh()->status);
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_staff_gets_an_email_when_the_customer_replies(): void
    {
        Mail::fake();
        $this->fakeHub();
        User::factory()->create(['email' => 'staff@example.com']);
        $ticket = $this->makeTicket(['status' => 'pending', 'priority' => 3]);

        $this->post(CustomerLink::reply($ticket), ['body' => 'Ecco altri dettagli.'])->assertRedirect();

        Mail::assertQueued(CustomerRepliedForStaff::class, fn ($m) => $m->hasTo('staff@example.com')
            && $m->envelope()->subject === '[Urgenza: Alta] Risposta del cliente: Non riesco ad accedere');
    }

    public function test_reply_without_signature_is_refused(): void
    {
        $ticket = $this->makeTicket();

        $this->post('/ticket/'.$ticket->hub_id.'/risposta', ['body' => 'ciao'])->assertForbidden();
    }

    public function test_customer_reopens_a_closed_ticket_when_the_hub_allows_it(): void
    {
        $this->fakeHub();
        $ticket = $this->makeTicket(['status' => 'closed']);

        $this->post(CustomerLink::reply($ticket), ['body' => 'Il problema e tornato.'])->assertRedirect();

        $this->assertSame('open', $ticket->refresh()->status);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['status'] === 'open');
    }

    public function test_customer_cannot_reopen_when_the_hub_refuses_and_is_pointed_to_a_new_ticket(): void
    {
        Http::fake(['hub.test/api/v1/tickets/*' => Http::response(['error' => ['code' => 'REOPEN_DISABLED', 'message' => 'no']], 409)]);
        $ticket = $this->makeTicket(['status' => 'closed']);

        $this->post(CustomerLink::reply($ticket), ['body' => 'Il problema e tornato.'])
            ->assertSessionHasErrors('body')
            ->assertSessionHas('new_ticket_url', route('ticket.create', ['rif' => $ticket->hub_id]));

        $this->assertSame('closed', $ticket->refresh()->status);
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_reopening_waits_for_the_hub_when_it_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $ticket = $this->makeTicket(['status' => 'closed']);

        $this->post(CustomerLink::reply($ticket), ['body' => 'Il problema e tornato.'])->assertSessionHasErrors('body');

        $this->assertSame('closed', $ticket->refresh()->status);
    }

    public function test_new_ticket_can_reference_the_closed_one(): void
    {
        Mail::fake();
        $this->fakeHub();

        $this->get('/ticket/nuovo?rif=94000001')->assertOk()->assertSee('collegata alla richiesta n. 94000001');
        $this->post('/ticket', $this->form(['related_hub_id' => 94000001]));

        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['related_ticket_id'] ?? null) === 94000001);
    }

    public function test_staff_reply_notifies_the_customer_and_moves_the_ticket_to_pending(): void
    {
        Mail::fake();
        $this->fakeHub();
        $ticket = $this->makeTicket();

        $this->actingAs($this->admin())->post("/admin/ticket/{$ticket->id}/risposta", ['body' => 'Prova a reimpostare la password.'])->assertSessionHasNoErrors();

        $this->assertSame('pending', $ticket->refresh()->status);
        Mail::assertQueued(StaffReplied::class, fn ($m) => $m->hasTo('mario@example.com'));
    }

    public function test_internal_note_does_not_email_the_customer(): void
    {
        Mail::fake();
        $this->fakeHub();
        $ticket = $this->makeTicket();

        $this->actingAs($this->admin())->post("/admin/ticket/{$ticket->id}/risposta", ['body' => 'Da verificare sul server.', 'note' => '1']);

        $this->assertSame('open', $ticket->refresh()->status);
        Mail::assertNothingQueued();
    }
}
