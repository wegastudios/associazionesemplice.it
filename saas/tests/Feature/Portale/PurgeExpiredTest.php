<?php

declare(strict_types=1);

use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/PortalHelpers.php';

/*
 * Cancellazione dei dati scaduti (docs/CANCELLAZIONE_DATI.md): 180 giorni dalla scadenza, 7 dopo la fine della prova.
 */

beforeEach(function (): void {
    DB::commit();
    Notification::fake();
    Http::fake();
    $this->portal = fakePortal();
    $this->provision = fn (string $ref, string $name, string $slug, string $email) => $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($this->portal, 'provision', [
        'ref' => $ref,
        'org' => ['name' => $name, 'slug' => $slug],
        'owner' => ['email' => $email, 'name' => 'Titolare'],
    ])])->json('org_id');
    $this->entitle = fn (string $orgId, string $ref, array $overrides) => $this->putJson("/api/portale/v1/orgs/{$orgId}/entitlements", ['token' => portalToken($this->portal, 'entitlements', entitlementClaims($orgId, $ref, 1, $overrides))])->assertOk();
    // «Maturo» per la cancellazione: sospeso dal SaaS da tempo e avviso finale partito da più di 24 ore.
    $this->ripen = function (string $orgId, string $until, int $suspendedDaysAgo = 400, int $noticeHoursAgo = 48, string $kind = 'purge_1'): void {
        $owner = DB::connection('pgsql_owner');
        $owner->table('core.orgs')->where('id', $orgId)->update(['suspended_at' => now()->subDays($suspendedDaysAgo)]);
        $owner->table('core.org_notices')->insert(['org_id' => $orgId, 'kind' => $kind, 'until' => $until, 'sent_at' => now()->subHours($noticeHoursAgo)]);
    };
    $this->people = function (string $orgId): int {
        $owner = DB::connection('pgsql_owner');

        return $owner->transaction(function () use ($owner, $orgId): int {
            $owner->select("SELECT set_config('app.org_id', ?, true)", [$orgId]);

            return $owner->table('app.people')->count();
        });
    };
});

it('cancella i dati scaduti da più di 180 giorni e lascia le altre associazioni', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'solo-alfa@example.com');
    $beta = ($this->provision)('sub_b', 'Beta', 'beta-circolo', 'solo-beta@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'suspended', 'until' => now()->subDays(181)->toDateString()]);
    ($this->ripen)($alfa, now()->subDays(181)->toDateString());
    ($this->entitle)($beta, 'sub_b', ['status' => 'active', 'until' => now()->addDays(10)->toDateString()]);
    $this->orgContext()->run($alfa, fn () => Person::create(['type' => 'founder', 'first_name' => 'Mario', 'last_name' => 'Rossi']));
    $this->orgContext()->run($beta, fn () => Person::create(['type' => 'founder', 'first_name' => 'Bruno', 'last_name' => 'Bianchi']));
    config(['saas.portal.purge_enabled' => true]);

    $exit = Artisan::call('saas:portal:purge-expired');
    expect($exit)->toBe(0, Artisan::output());

    $org = Org::withTrashed()->findOrFail($alfa);
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
    expect(Org::withTrashed()->findOrFail($prova)->deleted_at)->not->toBeNull();
})->skip('Da definire con la regola della prova: serve il segno «prova» sui diritti.');

it('non cancella nulla con l\'interruttore spento, e con --dry-run elenca soltanto', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'suspended', 'until' => now()->subDays(300)->toDateString()]);
    ($this->ripen)($alfa, now()->subDays(300)->toDateString());

    $this->artisan('saas:portal:purge-expired')->assertSuccessful();
    expect(Org::withTrashed()->findOrFail($alfa)->deleted_at)->toBeNull();

    config(['saas.portal.purge_enabled' => true]);
    $this->artisan('saas:portal:purge-expired', ['--dry-run' => true])->assertSuccessful();
    expect(Org::withTrashed()->findOrFail($alfa)->deleted_at)->toBeNull();
});

it('si ferma se i candidati di una notte sono troppi', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    $beta = ($this->provision)('sub_b', 'Beta', 'beta-circolo', 'beta@example.com');
    foreach ([[$alfa, 'sub_a'], [$beta, 'sub_b']] as [$id, $ref]) {
        ($this->entitle)($id, $ref, ['status' => 'suspended', 'until' => now()->subDays(300)->toDateString()]);
        ($this->ripen)($id, now()->subDays(300)->toDateString());
    }
    config(['saas.portal.purge_enabled' => true, 'saas.portal.purge_max_per_night' => 1]);

    $this->artisan('saas:portal:purge-expired')->assertFailed();

    expect(Org::withTrashed()->findOrFail($alfa)->deleted_at)->toBeNull()
        ->and(Org::withTrashed()->findOrFail($beta)->deleted_at)->toBeNull();
});

it('non cancella se la sospensione non l\'ha scritta il SaaS da abbastanza tempo, anche con una scadenza vecchissima dal portale', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'suspended', 'until' => now()->subDays(400)->toDateString()]);
    ($this->ripen)($alfa, now()->subDays(400)->toDateString(), suspendedDaysAgo: 2);
    config(['saas.portal.purge_enabled' => true]);

    $this->artisan('saas:portal:purge-expired')->assertSuccessful();

    expect(Org::withTrashed()->findOrFail($alfa)->deleted_at)->toBeNull()
        ->and(DB::table('core.orgs')->where('id', $alfa)->value('suspended_at'))->not->toBeNull();
});

it('non cancella senza l\'avviso finale partito da almeno 24 ore', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    $beta = ($this->provision)('sub_b', 'Beta', 'beta-circolo', 'beta@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'suspended', 'until' => now()->subDays(300)->toDateString()]);
    ($this->entitle)($beta, 'sub_b', ['status' => 'suspended', 'until' => now()->subDays(300)->toDateString()]);
    // Alfa: nessun avviso. Beta: avviso partito due ore fa.
    DB::connection('pgsql_owner')->table('core.orgs')->whereIn('id', [$alfa, $beta])->update(['suspended_at' => now()->subDays(300)]);
    DB::connection('pgsql_owner')->table('core.org_notices')->insert(['org_id' => $beta, 'kind' => 'purge_1', 'until' => now()->subDays(300)->toDateString(), 'sent_at' => now()->subHours(2)]);
    config(['saas.portal.purge_enabled' => true]);

    $this->artisan('saas:portal:purge-expired')->assertSuccessful();

    expect(Org::withTrashed()->findOrFail($alfa)->deleted_at)->toBeNull()
        ->and(Org::withTrashed()->findOrFail($beta)->deleted_at)->toBeNull();
});

it('il momento della sospensione lo scrive il SaaS una volta sola e si azzera se l\'associazione torna attiva', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    $orgs = app(App\Modules\Core\Contracts\OrgDirectory::class);

    expect(DB::table('core.orgs')->where('id', $alfa)->value('suspended_at'))->toBeNull();
    $orgs->setStatus($alfa, 'suspended');
    $first = DB::table('core.orgs')->where('id', $alfa)->value('suspended_at');
    expect($first)->not->toBeNull();
    $this->travel(2)->days();
    $orgs->setStatus($alfa, 'closed');
    expect(DB::table('core.orgs')->where('id', $alfa)->value('suspended_at'))->toBe($first);
    $orgs->setStatus($alfa, 'active');
    expect(DB::table('core.orgs')->where('id', $alfa)->value('suspended_at'))->toBeNull();
});

it('una scadenza che arretra di molto finisce nel registro dell\'associazione', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'active', 'until' => now()->addDays(300)->toDateString()]);
    $this->putJson("/api/portale/v1/orgs/{$alfa}/entitlements", ['token' => portalToken($this->portal, 'entitlements', entitlementClaims($alfa, 'sub_a', 2, ['status' => 'suspended', 'until' => now()->subDays(400)->toDateString()]))])->assertOk();

    $details = DB::connection('pgsql_owner')->table('audit.log')->where('org_id', $alfa)->where('action', 'subscription.updated')->orderByDesc('created_at')->value('details');
    expect(json_decode((string) $details, true))->toHaveKey('until_back_days');
});

afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $owner->statement('TRUNCATE core.org_keys, core.org_users, core.org_entitlements, core.orgs, core.users CASCADE');
    DB::beginTransaction();
});
