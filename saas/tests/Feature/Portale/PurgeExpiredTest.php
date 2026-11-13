<?php

declare(strict_types=1);

use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/PortalHelpers.php';

/*
 * Cancellazione dei dati scaduti (docs/CANCELLAZIONE_DATI.md): 180 giorni dalla scadenza, 7 dopo la fine della prova.
 */

beforeEach(function (): void {
    Notification::fake();
    Http::fake();
    $this->portal = fakePortal();
    $this->provision = fn (string $ref, string $name, string $slug, string $email) => $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($this->portal, 'provision', [
        'ref' => $ref,
        'org' => ['name' => $name, 'slug' => $slug],
        'owner' => ['email' => $email, 'name' => 'Titolare'],
    ])])->json('org_id');
    $this->entitle = fn (string $orgId, string $ref, array $overrides) => $this->putJson("/api/portale/v1/orgs/{$orgId}/entitlements", ['token' => portalToken($this->portal, 'entitlements', entitlementClaims($orgId, $ref, 1, $overrides))])->assertOk();
    $this->people = fn (string $orgId): int => $this->orgContext()->run($orgId, fn (): int => Person::query()->count());
});

it('cancella i dati scaduti da più di 180 giorni e lascia le altre associazioni', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'solo-alfa@example.com');
    $beta = ($this->provision)('sub_b', 'Beta', 'beta-circolo', 'solo-beta@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'suspended', 'until' => now()->subDays(181)->toDateString()]);
    ($this->entitle)($beta, 'sub_b', ['status' => 'active', 'until' => now()->addDays(10)->toDateString()]);
    $this->orgContext()->run($alfa, fn () => Person::create(['type' => 'founder', 'first_name' => 'Mario', 'last_name' => 'Rossi']));
    $this->orgContext()->run($beta, fn () => Person::create(['type' => 'founder', 'first_name' => 'Bruno', 'last_name' => 'Bianchi']));
    config(['saas.portal.purge_enabled' => true]);

    $this->artisan('saas:portal:purge-expired')->assertSuccessful();

    $org = Org::query()->findOrFail($alfa);
    expect($org->status)->toBe('closed')->and($org->deleted_at)->not->toBeNull()
        ->and(($this->people)($alfa))->toBe(0)
        ->and(($this->people)($beta))->toBe(1)
        ->and(DB::table('core.org_keys')->where('org_id', $alfa)->exists())->toBeFalse()
        ->and(DB::table('core.org_keys')->where('org_id', $beta)->exists())->toBeTrue()
        ->and(User::query()->where('email', 'solo-alfa@example.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'solo-beta@example.com')->exists())->toBeTrue();
});

it('chi non ha scelto un piano si cancella 7 giorni dopo la fine della prova, non prima', function (): void {
    $prova = ($this->provision)('sub_p', 'Prova', 'prova-circolo', 'prova@example.com');
    ($this->entitle)($prova, 'sub_p', ['status' => 'suspended', 'until' => now()->subDays(6)->toDateString()]);
    config(['saas.portal.purge_enabled' => true]);

    $this->artisan('saas:portal:purge-expired')->assertSuccessful();
    expect(Org::query()->findOrFail($prova)->deleted_at)->not->toBeNull();
})->skip('Da definire con la regola della prova: serve il segno «prova» sui diritti.');

it('non cancella nulla con l\'interruttore spento, e con --dry-run elenca soltanto', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'suspended', 'until' => now()->subDays(300)->toDateString()]);

    $this->artisan('saas:portal:purge-expired')->assertSuccessful();
    expect(Org::query()->findOrFail($alfa)->deleted_at)->toBeNull();

    config(['saas.portal.purge_enabled' => true]);
    $this->artisan('saas:portal:purge-expired', ['--dry-run' => true])->assertSuccessful();
    expect(Org::query()->findOrFail($alfa)->deleted_at)->toBeNull();
});

it('si ferma se i candidati di una notte sono troppi', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    $beta = ($this->provision)('sub_b', 'Beta', 'beta-circolo', 'beta@example.com');
    foreach ([[$alfa, 'sub_a'], [$beta, 'sub_b']] as [$id, $ref]) {
        ($this->entitle)($id, $ref, ['status' => 'suspended', 'until' => now()->subDays(300)->toDateString()]);
    }
    config(['saas.portal.purge_enabled' => true, 'saas.portal.purge_max_per_night' => 1]);

    $this->artisan('saas:portal:purge-expired')->assertFailed();

    expect(Org::query()->findOrFail($alfa)->deleted_at)->toBeNull()
        ->and(Org::query()->findOrFail($beta)->deleted_at)->toBeNull();
});

it('non cancella un\'associazione attiva anche se la data è passata', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'active', 'until' => now()->subDays(300)->toDateString()]);
    config(['saas.portal.purge_enabled' => true]);

    $this->artisan('saas:portal:purge-expired')->assertSuccessful();

    expect(Org::query()->findOrFail($alfa)->deleted_at)->toBeNull();
});
