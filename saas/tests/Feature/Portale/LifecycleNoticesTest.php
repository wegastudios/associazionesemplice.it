<?php

declare(strict_types=1);

use App\Modules\Portale\Console\SendLifecycleNoticesCommand;
use App\Modules\Portale\Notifications\OrgLifecycleNotification;
use App\Modules\Portale\PortalConfig;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/PortalHelpers.php';

it('sceglie l\'avviso giusto per ogni giorno del ciclo', function (): void {
    $config = new PortalConfig(new Repository(['saas' => ['portal' => ['retention_days' => 180, 'trial_purge_days' => 7]]]));
    $n = static fn (string $e, string $o, int $offset): ?string => SendLifecycleNoticesCommand::notice($e, $o, $offset, $config)[0] ?? null;

    expect($n('cancelled', 'active', -30))->toBe('expiry_30')
        ->and($n('past_due', 'active', -8))->toBe('expiry_30')
        ->and($n('cancelled', 'active', -7))->toBe('expiry_7')
        ->and($n('active', 'active', -7))->toBeNull()          // chi paga non riceve l'avviso di scadenza
        ->and($n('active', 'suspended', 1))->toBe('suspended')
        ->and($n('active', 'suspended', 30))->toBe('purge_30')
        ->and($n('active', 'suspended', 90))->toBe('purge_90')
        ->and($n('active', 'suspended', 150))->toBe('purge_150')
        ->and($n('active', 'suspended', 170))->toBe('purge_170')
        ->and($n('active', 'suspended', 179))->toBe('purge_1')
        ->and($n('active', 'suspended', 180))->toBeNull()
        ->and($n('trial', 'active', -7))->toBe('trial_7')
        ->and($n('trial', 'active', -2))->toBe('trial_2')
        ->and($n('trial', 'active', 0))->toBe('trial_0')
        ->and($n('trial', 'suspended', 3))->toBe('trial_3')
        ->and($n('trial', 'suspended', 6))->toBe('trial_6')
        ->and($n('trial', 'suspended', 8))->toBeNull();
});

it('manda l\'avviso al titolare una sola volta per scadenza', function (): void {
    Notification::fake();
    $portal = fakePortal();
    $orgId = (string) $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($portal, 'provision', [
        'ref' => 'sub_n', 'org' => ['name' => 'Alfa', 'slug' => 'alfa-n'], 'owner' => ['email' => 'titolare@example.com', 'name' => 'Anna'],
    ])])->json('org_id');
    $this->putJson("/api/portale/v1/orgs/{$orgId}/entitlements", ['token' => portalToken($portal, 'entitlements', entitlementClaims($orgId, 'sub_n', 1, ['status' => 'suspended', 'until' => now()->subDays(31)->toDateString()]))])->assertOk();

    $this->artisan('saas:portal:lifecycle-notices')->assertSuccessful();
    Notification::assertSentTimes(OrgLifecycleNotification::class, 1);

    $this->artisan('saas:portal:lifecycle-notices')->assertSuccessful();
    Notification::assertSentTimes(OrgLifecycleNotification::class, 1);
});
