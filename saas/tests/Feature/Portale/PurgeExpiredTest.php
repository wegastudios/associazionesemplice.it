<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

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
    }
    config(['saas.portal.purge_enabled' => true, 'saas.portal.purge_max_per_night' => 1]);

    $this->artisan('saas:portal:purge-expired')->assertFailed();

    expect(Org::withTrashed()->findOrFail($alfa)->deleted_at)->toBeNull()
        ->and(Org::withTrashed()->findOrFail($beta)->deleted_at)->toBeNull();
});

it('cancella anche un\'associazione con movimenti in prima nota e un titolare con accessi registrati', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'solo-alfa@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'suspended', 'until' => now()->subDays(181)->toDateString()]);
    ($this->ripen)($alfa, now()->subDays(181)->toDateString());
    $owner = User::query()->where('email', 'solo-alfa@example.com')->sole();
    app(SecurityLog::class)->record('auth.login', $owner->id);
    $this->orgContext()->run($alfa, function (): void {
        $cash = Account::query()->where('type', 'cash')->sole();
        $donation = Category::query()->where('kind', CategoryKind::Donation)->sole();
        app(Ledger::class)->recordIncome(new Income(now()->toDateString(), $cash->id, [new IncomeLine($donation->id, 5000, 'Offerta')], null, null, false));
    });
    Storage::disk('org_files')->put("{$alfa}/prova.enc", 'x');
    config(['saas.portal.purge_enabled' => true]);

    $exit = Artisan::call('saas:portal:purge-expired');
    expect($exit)->toBe(0, Artisan::output());

    $owner = DB::connection('pgsql_owner');
    expect(Org::withTrashed()->findOrFail($alfa)->deleted_at)->not->toBeNull()
        ->and($owner->table('core.org_keys')->where('org_id', $alfa)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'solo-alfa@example.com')->exists())->toBeFalse()
        ->and(Storage::disk('org_files')->exists("{$alfa}/prova.enc"))->toBeFalse();
});

it('se la cancellazione dei dati fallisce lascia stato e file com\'erano', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    ($this->entitle)($alfa, 'sub_a', ['status' => 'suspended', 'until' => now()->subDays(181)->toDateString()]);
    ($this->ripen)($alfa, now()->subDays(181)->toDateString());
    DB::connection('pgsql_owner')->table('core.orgs')->where('id', $alfa)->update(['status' => 'suspended']);
    Storage::disk('org_files')->put("{$alfa}/prova.enc", 'x');
    // Un blocco imprevisto sul database: la cancellazione si ferma prima di toccare file e stato.
    DB::connection('pgsql_owner')->statement('CREATE TABLE app.blocco_prova (org_id uuid NOT NULL, x int)');
    DB::connection('pgsql_owner')->statement('CREATE FUNCTION app.blocco_prova_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'blocco\'; END $$');
    DB::connection('pgsql_owner')->statement('CREATE TRIGGER blocco_prova_t BEFORE DELETE ON app.blocco_prova FOR EACH ROW EXECUTE FUNCTION app.blocco_prova_fn()');
    DB::connection('pgsql_owner')->statement("INSERT INTO app.blocco_prova (org_id, x) VALUES ('{$alfa}', 1)");
    config(['saas.portal.purge_enabled' => true]);

    $exit = Artisan::call('saas:portal:purge-expired');

    expect($exit)->toBe(1);
    $org = Org::withTrashed()->findOrFail($alfa);
    expect($org->status)->toBe('suspended')->and($org->deleted_at)->toBeNull()
        ->and(Storage::disk('org_files')->exists("{$alfa}/prova.enc"))->toBeTrue();
    DB::connection('pgsql_owner')->statement('DROP TABLE app.blocco_prova');
    DB::connection('pgsql_owner')->statement('DROP FUNCTION app.blocco_prova_fn()');
    Storage::disk('org_files')->deleteDirectory($alfa);
});

afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $owner->statement('TRUNCATE core.security_events, core.org_keys, core.org_users, core.org_entitlements, core.orgs, core.users CASCADE');
    DB::beginTransaction();
});
