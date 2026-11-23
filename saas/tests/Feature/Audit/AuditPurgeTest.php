<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Audit\Log\ChainVerifier;
use App\Modules\Audit\Log\Partitions;
use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Org\DatabaseOrgPurger;
use App\Modules\Soci\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Il registro delle azioni nella cancellazione definitiva di un'associazione: si cancella a blocchi, senza togliere il
 * trigger di sola aggiunta (nessun blocco sull'intera tabella), e solo il proprietario, solo per l'associazione indicata.
 * Il proprietario non vede la transazione del test: qui i dati si confermano davvero e si cancellano alla fine.
 */

beforeEach(function (): void {
    DB::commit();
    $this->owner = DB::connection('pgsql_owner');
    $this->alfa = $this->createOrg('Polisportiva Alfa')->id;
    $this->beta = $this->createOrg('Circolo Beta')->id;
    foreach ([$this->alfa => 25, $this->beta => 3] as $orgId => $count) {
        $this->orgContext()->run($orgId, function () use ($count): void {
            foreach (range(1, $count) as $i) {
                app(AuditLog::class)->record('prova.riga');
            }
        });
    }
    $this->orgContext()->run($this->alfa, function (): void {
        Person::create(['first_name' => 'Socio', 'last_name' => 'Prova']);
        Person::create(['first_name' => 'Socia', 'last_name' => 'Prova']);
    });
    $this->owner->table('core.orgs')->where('id', $this->alfa)->update(['status' => 'suspended']);
});

afterEach(function (): void {
    DB::purge('pgsql_probe');
    $this->owner->statement('DROP TABLE IF EXISTS audit.log_2019_12');
    $this->owner->statement('DROP TABLE IF EXISTS app.blocco_prova');
    $this->owner->statement('DROP FUNCTION IF EXISTS app.blocco_prova_fn()');
    $this->owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $this->owner->statement('TRUNCATE core.org_keys, core.org_users, core.orgs CASCADE');
    DB::beginTransaction();
});

/** Prova una DELETE (o UPDATE) sul registro con associazione corrente e varco indicati: true se il trigger la rifiuta. */
function auditChangeRefused(ConnectionInterface $connection, string $orgId, string $purging, string $sql, string $message = '/solo in aggiunta/'): bool
{
    try {
        $connection->transaction(function () use ($connection, $orgId, $purging, $sql): void {
            $connection->select("SELECT set_config('app.org_id', ?, true), set_config('app.purging', ?, true)", [$orgId, $purging]);
            $connection->statement($sql, str_contains($sql, '?') ? [$orgId] : []);
        });
    } catch (QueryException $e) {
        return preg_match($message, $e->getMessage()) === 1;
    }

    return false;
}

/** Righe del registro di un'associazione, viste dal proprietario. */
function auditRowsOf(ConnectionInterface $owner, string $table, string $orgId): int
{
    return (int) $owner->transaction(function () use ($owner, $table, $orgId): mixed {
        $owner->select("SELECT set_config('app.org_id', ?, true)", [$orgId]);

        return $owner->scalar("SELECT count(*) FROM {$table} WHERE org_id = ?", [$orgId]);
    });
}

/** Da un'altra connessione: una scrittura nel registro di un'altra associazione passa entro un secondo? (poi si annulla) */
function auditProbeWrite(ConnectionInterface $probe, string $orgId): bool
{
    $probe->beginTransaction();
    try {
        $probe->statement("SET LOCAL lock_timeout = '1s'");
        $probe->select("SELECT set_config('app.org_id', ?, true)", [$orgId]);
        $probe->insert(
            "INSERT INTO audit.log (org_id, occurred_at, seq, actor_type, action, hash) VALUES (?, now(), 999999, 'system', 'prova.scrittura', decode('00', 'hex'))",
            [$orgId],
        );

        return true;
    } catch (QueryException) {
        return false;
    } finally {
        $probe->rollBack();
    }
}

/** Blocchi forti (più di quelli di lettura e scrittura di righe) sulle tabelle del registro, tenuti in questo momento. */
function auditStrongLocks(ConnectionInterface $probe): int
{
    return (int) $probe->scalar(
        "SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE l.database = (SELECT oid FROM pg_database WHERE datname = current_database()) AND n.nspname = 'audit' AND l.granted
           AND l.mode NOT IN ('AccessShareLock', 'RowShareLock', 'RowExclusiveLock')",
    );
}

/** Lo stato confermato della catena è coerente: ancora, righe rimaste senza buchi, testa. */
function auditChainConsistent(ConnectionInterface $probe, string $orgId): bool
{
    return $probe->transaction(function () use ($probe, $orgId): bool {
        $probe->select("SELECT set_config('app.org_id', ?, true)", [$orgId]);
        $anchor = (int) ($probe->scalar('SELECT seq FROM audit.anchors WHERE org_id = ?', [$orgId]) ?? 0);
        $head = (int) $probe->scalar('SELECT seq FROM audit.heads WHERE org_id = ?', [$orgId]);
        $log = $probe->selectOne('SELECT min(seq) AS low, count(*) AS n FROM audit.log WHERE org_id = ?', [$orgId]);
        $count = is_object($log) ? (int) $log->n : -1;

        return $count === $head - $anchor && ($count === 0 || (int) $log->low === $anchor + 1);
    });
}

it('nessun altro cancella righe del registro: senza varco, con il varco di un\'altra associazione, con un altro ruolo; e nessuno le modifica', function (): void {
    $delete = 'DELETE FROM audit.log WHERE org_id = ?';
    $before = auditRowsOf($this->owner, 'audit.log', $this->alfa);
    $betaRows = auditRowsOf($this->owner, 'audit.log', $this->beta);

    // Il proprietario senza varco, o con il varco aperto per un'altra associazione (in un verso e nell'altro).
    expect(auditChangeRefused($this->owner, $this->alfa, '', $delete))->toBeTrue()
        ->and(auditChangeRefused($this->owner, $this->alfa, $this->beta, $delete))->toBeTrue()
        ->and(auditChangeRefused($this->owner, $this->beta, $this->alfa, $delete))->toBeTrue()
        // Nemmeno col varco giusto si modifica una riga.
        ->and(auditChangeRefused($this->owner, $this->alfa, $this->alfa, "UPDATE audit.log SET action = 'prova.cambiata' WHERE org_id = ?"))->toBeTrue();

    // Un altro ruolo col permesso DELETE (in produzione il ruolo dell'applicazione lo può ereditare; qui si concede per
    // la prova) e col varco giusto: lo ferma il controllo su session_user del trigger, non il permesso.
    $appRole = config('database.connections.pgsql_owner.app_role');
    expect($appRole)->toBeString();
    $this->owner->statement('GRANT DELETE ON audit.log TO "'.$appRole.'"');
    try {
        expect(auditChangeRefused(DB::connection('pgsql'), $this->alfa, $this->alfa, $delete))->toBeTrue();
    } finally {
        $this->owner->statement('REVOKE DELETE ON audit.log FROM "'.$appRole.'"');
    }

    expect(auditRowsOf($this->owner, 'audit.log', $this->alfa))->toBe($before)
        ->and(auditRowsOf($this->owner, 'audit.log', $this->beta))->toBe($betaRows)
        ->and(app(ChainVerifier::class)->verify($this->alfa)['ok'])->toBeTrue();
});

it('cancella il registro a blocchi senza fermare le scritture delle altre associazioni, e alla fine non ne resta niente', function (): void {
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('pgsql_probe');
    $alfaRows = auditRowsOf($this->owner, 'audit.log', $this->alfa);
    $betaRows = auditRowsOf($this->owner, 'audit.log', $this->beta);
    expect($alfaRows)->toBeGreaterThan(20);

    // A ogni cancellazione nel registro, mentre la transazione del proprietario è ancora aperta, si guarda da fuori.
    $seen = [];
    DB::listen(function (QueryExecuted $query) use ($probe, &$seen): void {
        if ($query->connectionName !== 'pgsql_owner' || ! str_starts_with($query->sql, 'DELETE FROM audit.log')) {
            return;
        }
        $seen[] = [
            'strong_locks' => auditStrongLocks($probe),
            'other_org_writes' => auditProbeWrite($probe, $this->beta),
            'chain_consistent' => auditChainConsistent($probe, $this->alfa),
        ];
    });

    $done = (new DatabaseOrgPurger(app(DatabaseManager::class), app(DatabaseNames::class), 10))->purge($this->alfa);

    // Almeno tre blocchi più la cancellazione finale; in nessun momento un blocco sulla tabella, l'altra associazione
    // scrive sempre, e quel che resta confermato della catena di Alfa è sempre coerente.
    expect(count($seen))->toBeGreaterThanOrEqual(4);
    foreach ($seen as $moment) {
        expect($moment)->toBe(['strong_locks' => 0, 'other_org_writes' => true, 'chain_consistent' => true]);
    }

    // Esito come prima: niente del registro di Alfa, il registro di Beta intatto e verificabile.
    foreach (['audit.log', 'audit.heads', 'audit.anchors', 'audit.verify_checkpoints'] as $table) {
        expect(auditRowsOf($this->owner, $table, $this->alfa))->toBe(0);
    }
    expect($done['rows'])->toBeGreaterThan($alfaRows)
        ->and(auditRowsOf($this->owner, 'audit.log', $this->beta))->toBe($betaRows)
        ->and(app(ChainVerifier::class)->verify($this->beta)['ok'])->toBeTrue();
});

it('se i dati non si cancellano restano tutti, e la catena rimasta del registro si verifica ancora', function (): void {
    $this->owner->statement('CREATE TABLE app.blocco_prova (org_id uuid NOT NULL, x int)');
    $this->owner->statement('CREATE FUNCTION app.blocco_prova_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'blocco\'; END $$');
    $this->owner->statement('CREATE TRIGGER blocco_prova_t BEFORE DELETE ON app.blocco_prova FOR EACH ROW EXECUTE FUNCTION app.blocco_prova_fn()');
    $this->owner->statement("INSERT INTO app.blocco_prova (org_id, x) VALUES ('{$this->alfa}', 1)");
    expect(fn () => (new DatabaseOrgPurger(app(DatabaseManager::class), app(DatabaseNames::class), 10))->purge($this->alfa))
        ->toThrow(RuntimeException::class, 'Tabelle non svuotate');

    expect(auditRowsOf($this->owner, 'app.people', $this->alfa))->toBe(2)
        ->and(auditRowsOf($this->owner, 'audit.log', $this->alfa))->toBe(0)
        ->and(auditRowsOf($this->owner, 'audit.heads', $this->alfa))->toBe(1)
        ->and(app(ChainVerifier::class)->verify($this->alfa))->toMatchArray(['ok' => true]);
});

it('TRUNCATE è rifiutato al ruolo dell\'applicazione, anche col varco aperto: registro, sue partizioni (anche nuove) e movimenti', function (): void {
    // Partizione creata dopo la migrazione: deve ricevere lo stesso rifiuto.
    Partitions::ensure($this->owner, CarbonImmutable::parse('2019-12-01', 'UTC'), 1);

    // In produzione il ruolo dell'applicazione può ereditare TRUNCATE su tutte le tabelle: qui lo si concede per la prova.
    $appRole = config('database.connections.pgsql_owner.app_role');
    expect($appRole)->toBeString();
    $schemas = array_values(array_filter(['app', 'audit', 'sites', 'portal'], fn (string $s): bool => $this->owner->scalar('SELECT to_regnamespace(?) IS NOT NULL', [$s]) === true));
    $grant = fn (string $verb): bool => $this->owner->statement($verb.' TRUNCATE ON ALL TABLES IN SCHEMA '.implode(', ', $schemas).($verb === 'GRANT' ? ' TO ' : ' FROM ').'"'.$appRole.'"');
    $grant('GRANT');
    try {
        $app = DB::connection('pgsql');
        foreach (['audit.log', 'audit.log_default', 'audit.log_'.now()->utc()->format('Y_m'), 'audit.log_2019_12'] as $table) {
            expect(auditChangeRefused($app, $this->alfa, $this->alfa, "TRUNCATE {$table}"))->toBeTrue("TRUNCATE {$table}");
        }
        expect(auditChangeRefused($app, $this->alfa, $this->alfa, 'TRUNCATE app.transactions CASCADE', '/non si eliminano/'))->toBeTrue()
            ->and(auditChangeRefused($app, $this->alfa, $this->alfa, 'TRUNCATE app.orgs CASCADE', '/solo in aggiunta|non si eliminano/'))->toBeTrue();
    } finally {
        $grant('REVOKE');
    }

    // Niente è stato tolto, e la cancellazione definitiva a blocchi funziona come prima.
    $betaRows = auditRowsOf($this->owner, 'audit.log', $this->beta);
    expect(auditRowsOf($this->owner, 'audit.log', $this->alfa))->toBeGreaterThan(20);
    (new DatabaseOrgPurger(app(DatabaseManager::class), app(DatabaseNames::class), 10))->purge($this->alfa);
    expect(auditRowsOf($this->owner, 'audit.log', $this->alfa))->toBe(0)
        ->and(auditRowsOf($this->owner, 'audit.log', $this->beta))->toBe($betaRows)
        ->and(app(ChainVerifier::class)->verify($this->beta)['ok'])->toBeTrue();
});
