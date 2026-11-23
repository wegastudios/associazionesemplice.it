<?php

declare(strict_types=1);

use App\Modules\Audit\Log\Partitions;
use App\Modules\Core\Contracts\DatabaseNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/*
 * Il backup orario (pg_dump) tiene un blocco leggero su ogni tabella del registro per tutta la sua durata. Le istruzioni
 * che chiedono un blocco forte non devono restare in fila dietro di lui (e fermare tutte le query dietro di sé):
 * aspettano poco, riprovano tre volte e poi rinunciano senza lasciare nulla a metà.
 */

/** Sessione che fa come pg_dump: blocco leggero su audit.log e su tutte le sue partizioni, fino al rollBack. */
function registroInLettura(): Connection
{
    $owner = app(DatabaseNames::class)->ownerConnection(null);
    config(['database.connections.prova_dump_registro' => config("database.connections.{$owner}")]);
    $dump = DB::connection('prova_dump_registro');
    $dump->beginTransaction();
    $dump->statement('LOCK TABLE audit.log IN ACCESS SHARE MODE');

    return $dump;
}

function proprietarioCondiviso(): Connection
{
    return DB::connection(app(DatabaseNames::class)->ownerConnection(null));
}

beforeEach(function (): void {
    config(['saas.db.lock_timeout' => '200ms']);
    Sleep::fake();
    // Rete di sicurezza: un'istruzione senza limite proprio resterebbe in fila per sempre, e il test non finirebbe.
    proprietarioCondiviso()->statement("SET lock_timeout = '2s'");
});

it('con un backup in corso la partizione nuova riprova tre volte, poi rinuncia senza lasciare tabelle', function (): void {
    $this->travelTo(CarbonImmutable::parse('2040-01-15 02:13', 'UTC'));
    $dump = registroInLettura();

    try {
        $this->artisan('saas:audit:partitions', ['--months' => 1])->expectsOutputToContain('ERRORE shared')->assertFailed();
        Sleep::assertSleptTimes(2);
        expect(proprietarioCondiviso()->scalar("SELECT to_regclass('audit.log_2040_01') IS NULL"))->toBeTrue();
    } finally {
        $dump->rollBack();
        $dump->disconnect();
    }

    // Finito il backup, la partizione si aggancia con RLS, chiave primaria, trigger di sola aggiunta e rifiuto del TRUNCATE.
    $this->artisan('saas:audit:partitions', ['--months' => 1])->expectsOutputToContain('ok shared')->assertSuccessful();
    $partition = proprietarioCondiviso()->selectOne(<<<'SQL'
        SELECT c.relispartition AS attached, c.relforcerowsecurity AS rls, pg_get_expr(c.relpartbound, c.oid) AS bounds,
               EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = c.oid AND i.indisprimary) AS pk,
               EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgrelid = c.oid AND t.tgname = 'log_append_only') AS append_only,
               EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgrelid = c.oid AND t.tgname = 'log_no_truncate') AS no_truncate,
               EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = 'backup_read') AS backup_read
        FROM pg_class c WHERE c.oid = 'audit.log_2040_01'::regclass
    SQL);
    expect($partition->attached)->toBeTrue()
        ->and($partition->rls)->toBeTrue()
        ->and($partition->bounds)->toContain('2040-01-01')->toContain('2040-02-01')
        ->and($partition->pk)->toBeTrue()
        ->and($partition->append_only)->toBeTrue()
        ->and($partition->no_truncate)->toBeTrue()
        ->and($partition->backup_read)->toBeTrue();
});

it('con un backup in corso la potatura riprova tre volte, poi rinuncia e lascia la partizione', function (): void {
    Partitions::ensure(proprietarioCondiviso(), CarbonImmutable::parse('2019-12-01', 'UTC'), 1);
    $dump = registroInLettura();

    try {
        $this->artisan('saas:audit:prune', ['--before' => '2020-01-01', '--force' => true])
            ->expectsOutputToContain('riprova più tardi')->assertFailed();
        Sleep::assertSleptTimes(2);
    } finally {
        $dump->rollBack();
        $dump->disconnect();
    }

    $this->artisan('saas:audit:prune', ['--before' => '2020-01-01', '--dry-run' => true])->expectsOutputToContain('log_2019_12')->assertSuccessful();
});

it('il controllo di salute vede le partizioni del mese e del prossimo e la partizione predefinita vuota', function (): void {
    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    partizioni del registro')
        ->assertSuccessful();
});

it('il controllo di salute avvisa se manca la partizione del mese', function (): void {
    $this->travelTo(CarbonImmutable::parse('2045-06-10 10:00', 'UTC'));

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('manca audit.log_2045_06')
        ->assertSuccessful();
});

it('il controllo di salute avvisa se la partizione predefinita ha righe', function (): void {
    $this->useDedicatedDatabase();
    $org = $this->createOrg('Associazione Fuori Mese', $this::DEDICATED);
    $owner = DB::connection(app(DatabaseNames::class)->ownerConnection($this::DEDICATED));

    // Una riga di un mese senza partizione finisce nella predefinita.
    $owner->transaction(function () use ($owner, $org): void {
        $owner->select("SELECT set_config('app.org_id', ?, true)", [$org->id]);
        $owner->insert("INSERT INTO audit.log (org_id, occurred_at, seq, actor_type, action, hash) VALUES (?, '1999-01-01 00:00:00+00', 1, 'system', 'prova.fuori_mese', '\\x00'::bytea)", [$org->id]);
    });

    try {
        $this->artisan('saas:health', ['--skip-migrations' => true])
            ->expectsOutputToContain('righe nella partizione predefinita')
            ->assertSuccessful();
    } finally {
        $owner->statement('TRUNCATE audit.log_default');
    }
});

it('il comando delle partizioni crea la funzione del rifiuto del TRUNCATE se manca, e la partizione nuova la usa', function (): void {
    $this->travelTo(CarbonImmutable::parse('2041-03-15 02:13', 'UTC'));
    $owner = proprietarioCondiviso();
    $owner->beginTransaction();

    try {
        // Database rimasto indietro: niente funzione (e quindi niente trigger, che dipendono da lei).
        $owner->statement('DROP FUNCTION audit.reject_truncate() CASCADE');
        expect($owner->scalar("SELECT to_regprocedure('audit.reject_truncate()') IS NULL"))->toBeTrue();

        $this->artisan('saas:audit:partitions', ['--months' => 1])->expectsOutputToContain('ok shared')->assertSuccessful();

        expect($owner->scalar("SELECT to_regprocedure('audit.reject_truncate()') IS NOT NULL"))->toBeTrue()
            ->and($owner->scalar("SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = 'audit.log_2041_03'::regclass AND tgname = 'log_no_truncate')"))->toBeTrue();
    } finally {
        $owner->rollBack();
    }
    expect($owner->scalar("SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = 'audit.log'::regclass AND tgname = 'log_no_truncate')"))->toBeTrue();
});

it('il controllo di salute dà errore se una tabella protetta non ha il rifiuto del TRUNCATE', function (string $table, string $trigger, string $function): void {
    $owner = proprietarioCondiviso();
    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ok    rifiuto del TRUNCATE')->assertSuccessful();

    $owner->statement("DROP TRIGGER {$trigger} ON {$table}");
    try {
        $this->artisan('saas:health', ['--skip-migrations' => true])
            ->expectsOutputToContain("ERRORE rifiuto del TRUNCATE: shared: senza rifiuto del TRUNCATE {$table}")
            ->assertFailed();
    } finally {
        $owner->statement("CREATE OR REPLACE TRIGGER {$trigger} BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION {$function}()");
    }

    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ok    rifiuto del TRUNCATE')->assertSuccessful();
})->with([
    'una partizione' => ['audit.log_default', 'log_no_truncate', 'audit.reject_truncate'],
    'la tabella del registro' => ['audit.log', 'log_no_truncate', 'audit.reject_truncate'],
    'i movimenti della prima nota' => ['app.transactions', 'transactions_no_truncate', 'app.reject_transactions_truncate'],
]);
