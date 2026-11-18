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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use IlluminateSupportFacadesStorage;
use IlluminateSupportStr;

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
    // Lavori in coda e falliti dell'associazione (portano il suo identificativo): si cancellano con lei, quelli di altri restano.
    $db = DB::connection('pgsql_owner');
    $db->table('core.jobs')->insert([['queue' => 'default', 'payload' => '{"orgId":"'.$alfa.'"}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
        ['queue' => 'default', 'payload' => '{"orgId":"altra"}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]]);
    $db->table('core.failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{"orgId":"'.$alfa.'"}', 'exception' => 'x', 'failed_at' => now()]);

    app(OrgPurger::class)->purge($alfa);

    expect($db->table('core.jobs')->where('payload', 'like', '%'.$alfa.'%')->exists())->toBeFalse()
        ->and($db->table('core.jobs')->where('payload', 'like', '%altra%')->count())->toBe(1)
        ->and($db->table('core.failed_jobs')->where('payload', 'like', '%'.$alfa.'%')->exists())->toBeFalse();
    $db->table('core.jobs')->where('payload', 'like', '%altra%')->delete();
    $org = Org::withTrashed()->findOrFail($alfa);
    expect($org->status)->toBe('closed')->and($org->deleted_at)->not->toBeNull()
        ->and($db->table('core.org_keys')->where('org_id', $alfa)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'solo-alfa@example.com')->exists())->toBeFalse()
        ->and(Storage::disk('org_files')->exists("{$alfa}/prova.enc"))->toBeFalse();
});

it('l\'utente dell\'applicazione non cancella i movimenti nemmeno impostando la variabile della cancellazione', function (): void {
    $alfa = ($this->provision)('sub_a', 'Alfa', 'alfa-circolo', 'alfa@example.com');
    $this->orgContext()->run($alfa, function () use ($alfa): void {
        $cash = Account::query()->where('type', 'cash')->sole();
        $donation = Category::query()->where('kind', CategoryKind::Donation)->sole();
        app(Ledger::class)->recordIncome(new Income(now()->toDateString(), $cash->id, [new IncomeLine($donation->id, 5000, 'Offerta')], null, null, false));

        DB::select("SELECT set_config('app.purging', ?, true)", [$alfa]);
        $refused = false;
        try {
            DB::transaction(fn () => DB::delete('DELETE FROM app.transactions WHERE org_id = ?', [$alfa]));
        } catch (QueryException) {
            $refused = true;
        }

        expect($refused)->toBeTrue()->and(DB::table('app.transactions')->count())->toBeGreaterThan(0);
    });
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
