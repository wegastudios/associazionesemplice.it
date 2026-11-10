<?php

declare(strict_types=1);

use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use App\Modules\Portale\Jwt\EdDsaJwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/PortalHelpers.php';

/** @return array<string, mixed> */
function subscriptionClaims(string $orgId, int $rev, array $overrides = []): array
{
    return $overrides + [
        'sub' => $orgId,
        'ref' => 'sub_123',
        'rev' => $rev,
        'status' => 'active',
        'until' => now()->addMonth()->toDateString(),
        'trial_ends' => null,
        'plan' => 'gestionale',
        'plan_version' => 1,
        'members' => 150,
        'addons' => [['code' => 'operators_extra', 'qty' => 2], ['code' => 'member_area', 'qty' => 1], ['code' => 'resources_hourly_web', 'qty' => 1]],
        'promo' => 'PRIMAVERA',
        'price_cents' => 29000,
        'partner' => null,
    ];
}

beforeEach(function (): void {
    Notification::fake();
    $this->portal = fakePortal();
    $this->orgId = (string) $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($this->portal, 'provision', [
        'ref' => 'sub_123',
        'org' => ['name' => 'Polisportiva Alfa', 'slug' => 'polisportiva-alfa'],
        'owner' => ['email' => 'titolare@example.com', 'name' => 'Anna Rossi'],
    ])])->json('org_id');
    $this->put = fn (array $claims, ?string $org = null) => $this->putJson('/api/portale/v2/orgs/'.($org ?? $this->orgId).'/subscription', [
        'token' => portalToken($this->portal, 'subscription', $claims),
    ]);
});

it('ricava operatori, spazio e pacchetti dal piano e dagli addon', function (): void {
    ($this->put)(subscriptionClaims($this->orgId, 1))
        ->assertOk()->assertExactJson(['org_id' => $this->orgId, 'rev' => 1, 'plan' => 'gestionale', 'plan_version' => 1]);

    $e = app(Entitlements::class)->for($this->orgId);
    expect($e->operators)->toBe(3)                       // 1 del piano + 2 aggiuntivi
        ->and($e->storageGb)->toBe(1)                    // 150 soci × 5 MB = 750 MB, arrotondato al GB
        ->and($e->members)->toBe(150)
        ->and($e->has(Package::MemberArea))->toBeTrue()
        ->and($e->has(Package::Site))->toBeFalse()
        ->and($e->limit(Package::Resources, 'hourly.tier'))->toBe(2)
        ->and($e->status)->toBe('active');

    // Il fatto commerciale resta nel registro dei diritti salvati.
    $stored = json_decode((string) DB::table('core.org_entitlements')->where('org_id', $this->orgId)->value('claims'), true);
    expect($stored['subscription']['plan'])->toBe('gestionale')->and($stored['subscription']['promo'])->toBe('PRIMAVERA');
});

it('con piano o addon sconosciuti risponde 409 e non applica nulla', function (): void {
    ($this->put)(subscriptionClaims($this->orgId, 1, ['plan_version' => 9]))->assertStatus(409)->assertExactJson(['error' => 'unknown_plan']);
    ($this->put)(subscriptionClaims($this->orgId, 1, ['plan' => 'non_esiste']))->assertStatus(409)->assertExactJson(['error' => 'unknown_plan']);
    ($this->put)(subscriptionClaims($this->orgId, 1, ['addons' => [['code' => 'jackpot', 'qty' => 1]]]))->assertStatus(409)->assertExactJson(['error' => 'unknown_addon']);

    expect(DB::table('core.org_entitlements')->where('org_id', $this->orgId)->exists())->toBeFalse()
        ->and(app(Entitlements::class)->for($this->orgId)->operators)->toBe(1);
});

it('applica solo revisioni crescenti, nella stessa serie dei diritti v1', function (): void {
    ($this->put)(subscriptionClaims($this->orgId, 5))->assertOk();
    ($this->put)(subscriptionClaims($this->orgId, 5))->assertStatus(409)->assertJson(['error' => 'stale_revision', 'current_rev' => 5]);
    ($this->put)(subscriptionClaims($this->orgId, 4))->assertStatus(409);
    $this->putJson("/api/portale/v1/orgs/{$this->orgId}/entitlements", ['token' => portalToken($this->portal, 'entitlements', entitlementClaims($this->orgId, 'sub_123', 3))])
        ->assertStatus(409);
    ($this->put)(subscriptionClaims($this->orgId, 6, ['status' => 'suspended']))->assertOk();

    expect(app(Entitlements::class)->for($this->orgId)->status)->toBe('suspended');
});

it('rifiuta firma, tipo, associazione e campi non validi', function (): void {
    $claims = subscriptionClaims($this->orgId, 1);

    $this->putJson("/api/portale/v2/orgs/{$this->orgId}/subscription", ['token' => EdDsaJwt::sign(['typ' => 'subscription'], random_bytes(32))])->assertUnauthorized();
    $this->putJson("/api/portale/v2/orgs/{$this->orgId}/subscription", ['token' => portalToken($this->portal, 'entitlements', $claims)])->assertUnauthorized();
    $this->putJson("/api/portale/v2/orgs/{$this->orgId}/subscription", [])->assertStatus(400);
    ($this->put)($claims, '00000000-0000-7000-8000-000000000000')->assertNotFound();
    ($this->put)(subscriptionClaims($this->orgId, 1, ['ref' => 'sub_999']))->assertNotFound();
    ($this->put)(subscriptionClaims($this->orgId, 1, ['operators' => 999]))->assertStatus(422);
    ($this->put)(subscriptionClaims($this->orgId, 1, ['addons' => [['code' => 'operators_extra', 'qty' => 0]]]))->assertStatus(422);
    ($this->put)(subscriptionClaims($this->orgId, 1, ['status' => 'gratis']))->assertStatus(422);
    ($this->put)(subscriptionClaims($this->orgId, 1, ['promo' => 'minuscolo']))->assertStatus(422);
});

it('lascia le associazioni create a mano con i soli limiti di base', function (): void {
    $e = app(Entitlements::class)->for('00000000-0000-7000-8000-000000000001');

    expect($e->operators)->toBe(1)->and($e->packages)->toBe([]);
});
