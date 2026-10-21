<?php

namespace Tests;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class SiteTestCase extends RoleTestCase
{
    use RefreshDatabase;

    protected string $role = 'site';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'support.hub.url' => 'https://hub.test',
            'support.hub.token' => 'wsc_test.segreto',
        ]);
    }

    /** Hub finto che accetta tutto: regole con priorita 0-2 e registrazione dei ticket con un id fisso. */
    protected function fakeHub(int $ticketId = 94000001, array $allowed = [0, 1, 2]): void
    {
        Http::fake([
            'hub.test/api/v1/rules' => Http::response(['data' => ['allowed_priorities' => $allowed, 'allow_reopen' => true, 'priority_labels' => [], 'statuses' => []]]),
            'hub.test/api/v1/tickets' => Http::response(['data' => ['id' => $ticketId, 'status' => 'open', 'priority' => 2]], 201),
            'hub.test/api/v1/tickets/*' => Http::response(['data' => ['id' => $ticketId]]),
        ]);
    }

    protected function admin(): User
    {
        return User::factory()->create(['global_role' => 'admin']);
    }

    protected function makeTicket(array $attributes = []): SupportTicket
    {
        $ticket = SupportTicket::create($attributes + [
            'hub_id' => 94000001,
            'request_uuid' => (string) Str::uuid(),
            'subject' => 'Non riesco ad accedere',
            'customer_name' => 'Mario Rossi',
            'customer_email' => 'mario@example.com',
            'priority' => 2,
            'status' => 'open',
            'last_activity_at' => now(),
        ]);
        $ticket->messages()->create(['author_type' => 'customer', 'author_name' => 'Mario Rossi', 'body' => 'Aiuto, non entro piu.']);

        return $ticket;
    }
}
