<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/*
 * Misure sullo schema, prima di togliere qualcosa (H28, H31). Il proprietario non vede la transazione del test: qui
 * l'associazione si conferma davvero e si cancella alla fine; le tabelle di prova stanno in una transazione annullata.
 */

beforeEach(function (): void {
    DB::commit();
    $this->owner = DB::connection('pgsql_owner');
    $this->orgId = $this->createOrg('Polisportiva Alfa')->id;
});

afterEach(function (): void {
    $this->owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $this->owner->statement('TRUNCATE core.org_keys, core.org_users, core.orgs CASCADE');
    DB::beginTransaction();
});

/** Il piano di una query sul registro con le scansioni complete vietate: se un indice serve, il piano lo mostra. */
function h28Plan(ConnectionInterface $owner, string $orgId, string $sql): string
{
    return $owner->transaction(function () use ($owner, $orgId, $sql): string {
        $owner->select("SELECT set_config('app.org_id', ?, true), set_config('enable_seqscan', 'off', true)", [$orgId]);

        return implode("\n", array_map(static fn (object $r): string => (string) current((array) $r), $owner->select('EXPLAIN '.$sql)));
    });
}

it('H28: nessuna lettura del registro usa l\'indice per scheda (log_org_subject)', function (): void {
    $org = "'".$this->orgId."'::uuid";
    // Le letture del registro che il codice fa: verifica della catena, copia della testa, esportazione, cancellazione.
    $queries = [
        'verifica, blocchi' => "SELECT * FROM audit.log WHERE org_id = {$org} AND seq > 0 ORDER BY seq LIMIT 500",
        'verifica e copia della testa, una riga' => "SELECT hash FROM audit.log WHERE org_id = {$org} AND seq = 10 LIMIT 1",
        'verifica, conteggio' => "SELECT count(*) FROM audit.log WHERE org_id = {$org}",
        'esportazione' => 'SELECT * FROM audit.log WHERE seq > 0 ORDER BY seq LIMIT 1000',
        'cancellazione, blocco' => "SELECT max(seq) FROM (SELECT seq FROM audit.log WHERE org_id = {$org} ORDER BY seq LIMIT 500) AS b",
        'cancellazione' => "DELETE FROM audit.log WHERE org_id = {$org} AND seq <= 500",
    ];
    foreach ($queries as $label => $sql) {
        $plan = h28Plan($this->owner, $this->orgId, $sql);
        // Nomi delle partizioni dell'indice: log_AAAA_MM_org_id_subject_type_subject_id_occurred_at_idx.
        expect($plan)->not->toContain('subject', $label.": il piano usa l'indice per scheda\n".$plan)
            ->and($plan)->not->toContain('Seq Scan', $label.": senza un indice utilizzabile\n".$plan);
    }
});

it('H28: il comando delle letture degli indici del registro somma le partizioni con il ruolo dei backup', function (): void {
    $this->artisan('saas:audit:index-usage')
        ->expectsOutputToContain('== shared')
        ->expectsOutputToContain('log_org_subject: letture')
        ->expectsOutputToContain('log_org_seq: letture')
        ->assertSuccessful();
});

it('H31: misura delle chiavi esterne verso l\'associazione (quali sono doppie, quanto costano)', function (): void {
    // Le chiavi esterne verso app.orgs e, per ognuna, se la stessa garanzia c'è già: un'altra chiave esterna della
    // tabella, con org_id in testa e tutte le colonne obbligatorie, verso una tabella che a sua volta punta ad app.orgs.
    $rows = $this->owner->select(<<<'SQL'
        WITH org_fk AS (
            SELECT c.conrelid, c.conname FROM pg_constraint c
            WHERE c.contype = 'f' AND c.conparentid = 0 AND c.confrelid = 'app.orgs'::regclass
        ),
        covering AS (
            SELECT c.conrelid, c.confrelid FROM pg_constraint c
            WHERE c.contype = 'f' AND c.conparentid = 0 AND c.confrelid <> 'app.orgs'::regclass AND cardinality(c.conkey) > 1
              AND (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = c.conrelid AND a.attnum = c.conkey[1]) = 'org_id'
              AND NOT EXISTS (SELECT 1 FROM unnest(c.conkey) k JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k WHERE NOT a.attnotnull)
              AND EXISTS (SELECT 1 FROM org_fk o WHERE o.conrelid = c.confrelid)
        )
        SELECT o.conrelid::regclass::text AS tbl, string_agg(DISTINCT x.confrelid::regclass::text, ', ') AS via
        FROM org_fk o LEFT JOIN covering x ON x.conrelid = o.conrelid
        GROUP BY 1 ORDER BY 1
    SQL);
    $double = [];
    $single = [];
    foreach ($rows as $r) {
        /** @var object{tbl: string, via: string|null} $r */
        if ($r->via !== null) {
            $double[] = $r->tbl.' (già da '.$r->via.')';
        } else {
            $single[] = $r->tbl;
        }
    }

    // Costo: le stesse righe in due tabelle di prova, con e senza la chiave esterna verso app.orgs.
    $n = 5000;
    $this->owner->beginTransaction();
    try {
        $this->owner->statement("SET LOCAL lock_timeout = '5s'");
        $this->owner->statement('CREATE TABLE app.h31_con (org_id uuid NOT NULL REFERENCES app.orgs(id), n int NOT NULL)');
        $this->owner->statement('CREATE TABLE app.h31_senza (org_id uuid NOT NULL, n int NOT NULL)');
        $time = function (string $table) use ($n): float {
            $t = hrtime(true);
            for ($i = 0; $i < $n; $i += 100) {
                // A gruppi di 100, come un inserimento multiplo: un controllo della chiave esterna per riga.
                $this->owner->insert("INSERT INTO {$table} (org_id, n) SELECT ?::uuid, g FROM generate_series(?::int, ?::int) g", [$this->orgId, $i, $i + 99]);
            }

            return (hrtime(true) - $t) / 1e6;
        };
        $withoutMs = $time('app.h31_senza');
        $withMs = $time('app.h31_con');
        // Il controllo blocca la riga dell'associazione (FOR KEY SHARE) fino alla fine della transazione che inserisce.
        $locks = $this->owner->selectOne("SELECT count(*) AS n FROM pg_locks WHERE pid = pg_backend_pid() AND relation = 'app.orgs'::regclass AND mode = 'RowShareLock'");
    } finally {
        $this->owner->rollBack(); // le tabelle di prova spariscono con la transazione
    }
    $locks = is_object($locks) && is_numeric($locks->n ?? null) ? (int) $locks->n : 0;

    fwrite(STDERR, sprintf(
        "\nH31 · chiavi esterne verso app.orgs\n  doppie (%d): %s\n  uniche garanzie (%d): %s\n  %d righe: con la chiave esterna %.1f ms, senza %.1f ms; blocchi su app.orgs: %d\n",
        count($double), implode('; ', $double), count($single), implode(', ', $single), $n, $withMs, $withoutMs, $locks,
    ));

    expect($double)->toContain('app.transactions (già da app.accounts, app.categories, app.fiscal_years)')
        ->and($single)->toContain('audit.log')
        ->and($locks)->toBe(1);
});
