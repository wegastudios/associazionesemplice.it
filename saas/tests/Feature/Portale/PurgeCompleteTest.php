<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Core\Contracts\OrgPurger;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/PortalHelpers.php';

/*
 * Cancellazione definitiva di un'associazione (Core\Org\DatabaseOrgPurger), chiamata direttamente: completa anche con
 * movimenti in prima nota e accessi registrati, e senza toccare stato e file se i dati non si cancellano.
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
});

it('cancella anche un\'associazione con movimenti in prima nota e un titolare con accessi registrati', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'solo-alfa@example.com');
    DB::connection('pgsql_owner')->table('core.orgs')->where('id', $alfa)->update(['status' => 'suspended']);
    $owner = User::query()->where('email', 'solo-alfa@example.com')->sole();
    app(SecurityLog::class)->record('auth.login', $owner->id);
    $this->orgContext()->run($alfa, function (): void {
        $cash = Account::query()->where('type', 'cash')->sole();
        $donation = Category::query()->where('kind', CategoryKind::Donation)->sole();
        app(Ledger::class)->recordIncome(new Income(now()->toDateString(), $cash->id, [new IncomeLine($donation->id, 5000, 'Offerta')], null, null, false));
    });
    Storage::disk('org_files')->put("{$alfa}/prova.enc", 'x');

    app(OrgPurger::class)->purge($alfa);

    $db = DB::connection('pgsql_owner');
    $org = Org::withTrashed()->findOrFail($alfa);
    expect($org->status)->toBe('closed')->and($org->deleted_at)->not->toBeNull()
        ->and($db->table('core.org_keys')->where('org_id', $alfa)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'solo-alfa@example.com')->exists())->toBeFalse()
        ->and(Storage::disk('org_files')->exists("{$alfa}/prova.enc"))->toBeFalse();
});

it('se la cancellazione dei dati si ferma lascia stato e file com\'erano', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    $db = DB::connection('pgsql_owner');
    $db->table('core.orgs')->where('id', $alfa)->update(['status' => 'suspended']);
    Storage::disk('org_files')->put("{$alfa}/prova.enc", 'x');
    // Un blocco imprevisto sul database: la cancellazione si ferma prima di toccare file e stato.
    $db->statement('CREATE TABLE app.blocco_prova (org_id uuid NOT NULL, x int)');
    $db->statement('CREATE FUNCTION app.blocco_prova_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'blocco\'; END $$');
    $db->statement('CREATE TRIGGER blocco_prova_t BEFORE DELETE ON app.blocco_prova FOR EACH ROW EXECUTE FUNCTION app.blocco_prova_fn()');
    $db->statement("INSERT INTO app.blocco_prova (org_id, x) VALUES ('{$alfa}', 1)");

    expect(fn () => app(OrgPurger::class)->purge($alfa))->toThrow(RuntimeException::class, 'Tabelle non svuotate');

    $org = Org::withTrashed()->findOrFail($alfa);
    expect($org->status)->toBe('suspended')->and($org->deleted_at)->toBeNull()
        ->and(Storage::disk('org_files')->exists("{$alfa}/prova.enc"))->toBeTrue();
    $db->statement('DROP TABLE app.blocco_prova');
    $db->statement('DROP FUNCTION app.blocco_prova_fn()');
    Storage::disk('org_files')->deleteDirectory($alfa);
});

afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $owner->statement('TRUNCATE core.security_events, core.org_keys, core.org_users, core.org_entitlements, core.orgs, core.users CASCADE');
    DB::beginTransaction();
});
