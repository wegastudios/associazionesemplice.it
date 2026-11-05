<?php

declare(strict_types=1);

use App\Modules\Portale\Jwt\EdDsaJwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/PortalHelpers.php';

// Nomi e indirizzi inventati.
beforeEach(function (): void {
    Notification::fake();
    $this->portal = fakePortal();
    $this->orgId = $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($this->portal, 'provision', [
        'ref' => 'sub_123',
        'org' => ['name' => 'Polisportiva Alfa', 'slug' => 'polisportiva-alfa'],
        'owner' => ['email' => 'titolare@example.com', 'name' => 'Anna Rossi'],
    ])])->json('org_id');
    $this->putJson("/api/portale/v1/orgs/{$this->orgId}/entitlements", ['token' => portalToken($this->portal, 'entitlements', entitlementClaims($this->orgId, 'sub_123', 1))])->assertOk();

    $this->read = fn (string $path, array $claims = []) => $this->postJson("/api/portale/v1/admin/{$path}", ['token' => portalToken($this->portal, 'admin_read', $claims + ['act' => 'staff@example.com', 'scope' => 'read'])]);
    $this->write = fn (string $path, array $claims) => $this->postJson("/api/portale/v1/admin/{$path}", ['token' => portalToken($this->portal, 'admin_write', $claims + ['act' => 'staff@example.com', 'scope' => 'write'])]);
});

it('elenca le associazioni con numeri e limiti, senza dati dei soci', function (): void {
    $res = ($this->read)('orgs')->assertOk();

    expect($res->json('total'))->toBe(1)
        ->and($res->json('orgs.0'))->toMatchArray([
            'org_id' => $this->orgId, 'ref' => 'sub_123', 'name' => 'Polisportiva Alfa', 'slug' => 'polisportiva-alfa',
            'status' => 'active', 'rev' => 1, 'members' => 0, 'members_limit' => 150, 'storage_limit_gb' => 5,
        ])
        ->and($res->json('orgs.0.operators'))->toBe(['active' => 1, 'disabled' => 0, 'limit' => 2]);

    ($this->read)('orgs', ['q' => 'alfa'])->assertJsonPath('total', 1);
    ($this->read)('orgs', ['q' => 'zzz'])->assertJsonPath('total', 0);
    ($this->read)('orgs', ['status' => 'suspended'])->assertJsonPath('total', 0);
    ($this->read)('orgs', ['status' => 'boh'])->assertStatus(422);
    ($this->read)('orgs', ['per_page' => 1000])->assertStatus(422);
});

it('mostra il dettaglio con i pacchetti e gli operatori con nome, email, ruolo e stato', function (): void {
    ($this->read)("orgs/{$this->orgId}")->assertOk()
        ->assertJsonPath('packages.einvoice.yearly', 50)->assertJsonPath('name', 'Polisportiva Alfa');

    $res = ($this->read)("orgs/{$this->orgId}/operators")->assertOk();
    expect($res->json('operators.0'))->toMatchArray(['email' => 'titolare@example.com', 'name' => 'Anna Rossi', 'role' => 'owner', 'disabled' => false, 'two_factor' => false])
        ->and(array_keys($res->json('operators.0')))->not->toContain('password');

    ($this->read)('orgs/'.fake()->uuid())->assertStatus(404)->assertJson(['error' => 'unknown_org']);
    ($this->read)('orgs/'.fake()->uuid().'/operators')->assertStatus(404);
});

it('disattiva e riattiva un utente, una volta per token, con chi l\'ha chiesto nel registro', function (): void {
    $userId = (string) DB::table('core.users')->where('email', 'titolare@example.com')->value('id');

    $token = portalToken($this->portal, 'admin_write', ['act' => 'staff@example.com', 'scope' => 'write', 'disabled' => true, 'reason' => 'prova']);
    $this->postJson("/api/portale/v1/admin/users/{$userId}/state", ['token' => $token])->assertOk()->assertExactJson(['user_id' => $userId, 'disabled' => true]);
    expect(DB::table('core.users')->where('id', $userId)->value('disabled_at'))->not->toBeNull()
        ->and(DB::table('core.security_events')->where('event', 'platform.user_disabled')->exists())->toBeTrue();

    // Lo stesso token non si riusa.
    $this->postJson("/api/portale/v1/admin/users/{$userId}/state", ['token' => $token])->assertStatus(409)->assertJson(['error' => 'replayed']);

    ($this->read)("orgs/{$this->orgId}/operators")->assertJsonPath('operators.0.disabled', true);
    ($this->read)("orgs/{$this->orgId}")->assertJsonPath('operators.disabled', 1);

    ($this->write)("users/{$userId}/state", ['disabled' => false])->assertOk();
    expect(DB::table('core.users')->where('id', $userId)->value('disabled_at'))->toBeNull();

    ($this->write)('users/'.fake()->uuid().'/state', ['disabled' => true])->assertStatus(404)->assertJson(['error' => 'unknown_user']);
    ($this->write)("users/{$userId}/state", ['disabled' => 'sì'])->assertStatus(422);
});

it('un token di sola lettura non scrive e uno di scrittura non legge', function (): void {
    $userId = (string) DB::table('core.users')->where('email', 'titolare@example.com')->value('id');

    // typ admin_write ma scope read: rifiutato; e viceversa.
    $this->postJson("/api/portale/v1/admin/users/{$userId}/state", ['token' => portalToken($this->portal, 'admin_write', ['act' => 'staff@example.com', 'scope' => 'read', 'disabled' => true])])
        ->assertStatus(403)->assertJson(['error' => 'scope']);
    $this->postJson("/api/portale/v1/admin/users/{$userId}/state", ['token' => portalToken($this->portal, 'admin_read', ['act' => 'staff@example.com', 'scope' => 'write', 'disabled' => true])])
        ->assertStatus(401);
    $this->postJson('/api/portale/v1/admin/orgs', ['token' => portalToken($this->portal, 'admin_read', ['act' => 'staff@example.com', 'scope' => 'write'])])
        ->assertStatus(403);

    expect(DB::table('core.users')->where('id', $userId)->value('disabled_at'))->toBeNull();
});

it('rifiuta token non firmati dal portale, senza act e senza token', function (): void {
    $this->postJson('/api/portale/v1/admin/orgs', ['token' => EdDsaJwt::sign(['typ' => 'admin_read'], random_bytes(32))])
        ->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
    $this->postJson('/api/portale/v1/admin/orgs', [])->assertStatus(400);
    $this->postJson('/api/portale/v1/admin/orgs', ['token' => portalToken($this->portal, 'admin_read', ['scope' => 'read'])])->assertStatus(422);
    // Il tipo dell'attestazione dei diritti non apre l'amministrazione.
    $this->postJson('/api/portale/v1/admin/orgs', ['token' => portalToken($this->portal, 'entitlements', ['act' => 'x', 'scope' => 'read'])])->assertStatus(401);
});
