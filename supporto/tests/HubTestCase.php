<?php

namespace Tests;

use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Hub\SiteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

abstract class HubTestCase extends RoleTestCase
{
    use RefreshDatabase;

    protected string $role = 'hub';

    /** @return array{site: Site, token: string} */
    protected function makeSite(string $name = 'AssociazioneSemplice', array $priorities = [0, 1, 2], bool $allowReopen = true, array $notify = [], string $email = 'portale@associazionesemplice.it'): array
    {
        $result = app(SiteService::class)->create($name, $email, $priorities, 'https://supporto.associazionesemplice.it', $notify, $allowReopen);

        return ['site' => $result['site'], 'token' => $result['token']];
    }

    protected function admin(): User
    {
        return User::factory()->create(['global_role' => 'admin']);
    }

    /** @return array<string, string> */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    protected function makeTicket(Site $site, array $attributes = []): Ticket
    {
        static $next = 1000;

        return Ticket::create($attributes + [
            'id' => $next++,
            'site_id' => $site->id,
            'request_uuid' => (string) Str::uuid(),
            'subject' => 'Oggetto',
            'priority' => 2,
            'status' => 'open',
            'last_activity_at' => now(),
        ]);
    }
}
