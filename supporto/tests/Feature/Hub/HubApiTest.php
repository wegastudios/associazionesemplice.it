<?php

namespace Tests\Feature\Hub;

use App\Mail\HighPriorityTicket;
use App\Models\Ticket;
use App\Services\Hub\SiteService;
use App\Services\Hub\SupportSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\HubTestCase;

class HubApiTest extends HubTestCase
{
    private function newTicket(string $token, array $data = []): TestResponse
    {
        return $this->postJson('/api/v1/tickets', $data + [
            'request_uuid' => (string) Str::uuid(),
            'subject' => 'Non riesco ad accedere',
            'customer_name' => 'Mario Rossi',
            'customer_email' => 'mario@example.com',
        ], $this->bearer($token));
    }

    public function test_api_requires_valid_credentials(): void
    {
        ['site' => $site, 'token' => $token] = $this->makeSite();

        $this->getJson('/api/v1/rules')->assertStatus(401);
        $this->getJson('/api/v1/rules', $this->bearer($site->client_id.'.sbagliato'))->assertStatus(401);
        $this->getJson('/api/v1/rules', $this->bearer($token))->assertOk();
    }

    public function test_revoked_site_cannot_use_the_api(): void
    {
        ['site' => $site, 'token' => $token] = $this->makeSite();
        app(SiteService::class)->revoke($site);

        $this->getJson('/api/v1/rules', $this->bearer($token))->assertStatus(401);
    }

    public function test_secret_rotation_keeps_the_old_secret_for_the_transition_window(): void
    {
        ['site' => $site, 'token' => $old] = $this->makeSite();
        $new = app(SiteService::class)->rotate($site);

        $this->getJson('/api/v1/rules', $this->bearer($old))->assertOk();
        $this->getJson('/api/v1/rules', $this->bearer($new))->assertOk();

        $this->travel(25)->hours();
        $this->getJson('/api/v1/rules', $this->bearer($old))->assertStatus(401);
        $this->getJson('/api/v1/rules', $this->bearer($new))->assertOk();
    }

    public function test_rules_expose_allowed_priorities_and_reopen(): void
    {
        ['token' => $token] = $this->makeSite(priorities: [0, 1, 4], allowReopen: false);

        $this->getJson('/api/v1/rules', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.allowed_priorities', [0, 1, 4])
            ->assertJsonPath('data.allow_reopen', false);
    }

    public function test_ticket_gets_an_anonymous_numeric_id_from_the_hub(): void
    {
        ['token' => $token] = $this->makeSite();

        $response = $this->newTicket($token)->assertCreated();
        $id = $response->json('data.id');

        $this->assertIsInt($id);
        $this->assertGreaterThanOrEqual(1, $id);
        $this->assertDatabaseHas('tickets', ['id' => $id, 'status' => 'open']);
    }

    public function test_ids_are_continuous_across_portals(): void
    {
        ['token' => $a] = $this->makeSite('Portale A', email: 'a@example.com');
        ['token' => $b] = $this->makeSite('Portale B', email: 'b@example.com');

        $first = $this->newTicket($a)->json('data.id');
        $second = $this->newTicket($b)->json('data.id');
        $third = $this->newTicket($a)->json('data.id');

        $this->assertGreaterThan($first, $second);
        $this->assertGreaterThan($second, $third);
    }

    public function test_same_request_uuid_returns_the_same_ticket(): void
    {
        ['token' => $token] = $this->makeSite();
        $uuid = (string) Str::uuid();

        $first = $this->newTicket($token, ['request_uuid' => $uuid])->assertCreated();
        $second = $this->newTicket($token, ['request_uuid' => $uuid])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Ticket::count());
    }

    public function test_priority_not_allowed_is_rejected_with_allowed_levels(): void
    {
        ['token' => $token] = $this->makeSite(priorities: [0, 1, 2]);

        $this->newTicket($token, ['priority' => 4])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PRIORITY_NOT_ALLOWED')
            ->assertJsonPath('error.allowed_priorities', [0, 1, 2]);

        $this->assertSame(0, Ticket::count());
    }

    public function test_default_priority_is_the_lowest_allowed_when_normal_is_not_enabled(): void
    {
        ['token' => $token] = $this->makeSite(priorities: [3, 4]);

        $this->newTicket($token)->assertCreated()->assertJsonPath('data.priority', 3);
    }

    public function test_priority_out_of_range_fails_validation(): void
    {
        ['token' => $token] = $this->makeSite(priorities: [0, 1, 2, 3, 4]);

        $this->newTicket($token, ['priority' => 5])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_high_priority_ticket_notifies_info_the_portal_and_extra_addresses(): void
    {
        Mail::fake();
        ['token' => $token] = $this->makeSite(priorities: [0, 1, 2, 3, 4], notify: ['tecnico@example.com'], email: 'portale@example.com');
        app(SupportSettings::class)->saveExtraNotifyEmails(['globale@example.com']);

        $this->newTicket($token, ['priority' => 3])->assertCreated();

        Mail::assertQueued(HighPriorityTicket::class, function (HighPriorityTicket $mail) {
            foreach (['info@wegastudios.com', 'portale@example.com', 'tecnico@example.com', 'globale@example.com'] as $to) {
                if (! $mail->hasTo($to)) {
                    return false;
                }
            }

            return $mail->level === 3;
        });
    }

    public function test_level_four_also_notifies(): void
    {
        Mail::fake();
        ['token' => $token] = $this->makeSite(priorities: [0, 1, 2, 3, 4]);

        $this->newTicket($token, ['priority' => 4])->assertCreated();

        Mail::assertQueued(HighPriorityTicket::class, fn ($mail) => $mail->level === 4 && $mail->hasTo('info@wegastudios.com'));
    }

    public function test_low_priority_does_not_notify(): void
    {
        Mail::fake();
        ['token' => $token] = $this->makeSite(priorities: [0, 1, 2, 3, 4]);

        $this->newTicket($token, ['priority' => 2])->assertCreated();

        Mail::assertNothingQueued();
    }

    public function test_raising_priority_to_high_notifies_once_per_level(): void
    {
        Mail::fake();
        ['token' => $token] = $this->makeSite(priorities: [0, 1, 2, 3, 4]);
        $id = $this->newTicket($token, ['priority' => 1])->json('data.id');

        $this->patchJson("/api/v1/tickets/{$id}", ['priority' => 3], $this->bearer($token))->assertOk();
        $this->patchJson("/api/v1/tickets/{$id}", ['priority' => 1], $this->bearer($token))->assertOk();
        $this->patchJson("/api/v1/tickets/{$id}", ['priority' => 3], $this->bearer($token))->assertOk();

        Mail::assertQueuedCount(1);

        $this->patchJson("/api/v1/tickets/{$id}", ['priority' => 4], $this->bearer($token))->assertOk();
        Mail::assertQueuedCount(2);
    }

    public function test_update_cannot_use_a_level_the_portal_may_not_assign(): void
    {
        ['token' => $token] = $this->makeSite(priorities: [0, 1, 2]);
        $id = $this->newTicket($token)->json('data.id');

        $this->patchJson("/api/v1/tickets/{$id}", ['priority' => 4], $this->bearer($token))
            ->assertStatus(422)->assertJsonPath('error.code', 'PRIORITY_NOT_ALLOWED');

        $this->assertDatabaseHas('tickets', ['id' => $id, 'priority' => 2]);
    }

    public function test_closed_ticket_can_be_reopened_by_default(): void
    {
        ['token' => $token] = $this->makeSite();
        $id = $this->newTicket($token)->json('data.id');

        $this->patchJson("/api/v1/tickets/{$id}", ['status' => 'closed'], $this->bearer($token))->assertOk();
        $this->assertNotNull(Ticket::find($id)->closed_at);

        $this->patchJson("/api/v1/tickets/{$id}", ['status' => 'open'], $this->bearer($token))
            ->assertOk()->assertJsonPath('data.status', 'open');
        $this->assertNull(Ticket::find($id)->closed_at);
    }

    public function test_reopening_is_refused_when_the_portal_is_not_allowed(): void
    {
        ['token' => $token] = $this->makeSite(allowReopen: false);
        $id = $this->newTicket($token)->json('data.id');

        $this->patchJson("/api/v1/tickets/{$id}", ['status' => 'closed'], $this->bearer($token))->assertOk();
        $this->patchJson("/api/v1/tickets/{$id}", ['status' => 'open'], $this->bearer($token))
            ->assertStatus(409)->assertJsonPath('error.code', 'REOPEN_DISABLED');

        $this->assertSame('closed', Ticket::find($id)->status);
    }

    public function test_reopen_setting_change_applies_to_later_reopenings(): void
    {
        ['site' => $site, 'token' => $token] = $this->makeSite();
        $id = $this->newTicket($token)->json('data.id');
        $this->patchJson("/api/v1/tickets/{$id}", ['status' => 'closed'], $this->bearer($token))->assertOk();

        app(SiteService::class)->update($site, ['allow_reopen' => false]);

        $this->patchJson("/api/v1/tickets/{$id}", ['status' => 'open'], $this->bearer($token))->assertStatus(409);
    }

    public function test_privacy_acceptance_sent_by_the_site_is_stored(): void
    {
        ['token' => $token] = $this->makeSite();

        $id = $this->newTicket($token, ['privacy_accepted_at' => '2026-10-17T10:00:00+00:00', 'privacy_version' => '2'])->assertCreated()->json('data.id');

        $ticket = Ticket::findOrFail($id);
        $this->assertSame('2026-10-17 10:00:00', $ticket->privacy_accepted_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2', $ticket->privacy_version);
    }

    public function test_a_site_cannot_touch_the_tickets_of_another(): void
    {
        ['token' => $a] = $this->makeSite('A', email: 'a@example.com');
        ['token' => $b] = $this->makeSite('B', email: 'b@example.com');
        $id = $this->newTicket($a)->json('data.id');

        $this->getJson("/api/v1/tickets/{$id}", $this->bearer($b))->assertNotFound();
        $this->patchJson("/api/v1/tickets/{$id}", ['status' => 'closed'], $this->bearer($b))->assertNotFound();
    }

    public function test_related_ticket_must_belong_to_the_same_portal(): void
    {
        ['token' => $a] = $this->makeSite('A', email: 'a@example.com');
        ['token' => $b] = $this->makeSite('B', email: 'b@example.com');
        $idA = $this->newTicket($a)->json('data.id');

        $this->newTicket($b, ['related_ticket_id' => $idA])->assertStatus(422)->assertJsonPath('error.code', 'RELATED_TICKET_NOT_FOUND');
        $this->newTicket($a, ['related_ticket_id' => $idA])->assertCreated();
    }
}
