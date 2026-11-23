<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Soglie dell'autovacuum per tabella (H29): ci sono sulle tabelle calde e su quelle solo in aggiunta, e impostarle non
 * aspetta il dump orario, che tiene un blocco leggero (ACCESS SHARE) su ogni tabella finché dura.
 */

/** @return array<string, string> le opzioni della tabella, nome => valore */
function autovacuumOptions(string $table): array
{
    $row = DB::connection('pgsql_owner')->selectOne('SELECT coalesce(array_to_string(reloptions, ?), ?) AS o FROM pg_class WHERE oid = ?::regclass', [',', '', $table]);
    $out = [];
    foreach (array_filter(explode(',', is_object($row) && is_string($row->o ?? null) ? $row->o : '')) as $pair) {
        [$k, $v] = explode('=', $pair, 2);
        $out[$k] = $v;
    }

    return $out;
}

it('le tabelle calde e quelle solo in aggiunta hanno le loro soglie dell\'autovacuum', function (): void {
    $hot = ['audit.heads', 'app.account_balances', 'app.counters', 'app.matricola_counters', 'app.member_sessions', 'core.sessions', 'core.jobs', 'core.cache', 'core.cache_locks'];
    $append = ['app.transactions', 'app.receipts', 'app.consent_events', 'core.security_events', 'core.platform_audit', 'core.staff_audit'];

    foreach ($hot as $table) {
        expect(autovacuumOptions($table))->toMatchArray([
            'autovacuum_vacuum_scale_factor' => '0.01',
            'autovacuum_vacuum_threshold' => '100',
            'autovacuum_vacuum_cost_delay' => '0',
        ], $table);
    }
    foreach ($append as $table) {
        expect(autovacuumOptions($table))->toMatchArray([
            'autovacuum_vacuum_insert_scale_factor' => '0.05',
            'autovacuum_vacuum_insert_threshold' => '1000',
            'autovacuum_freeze_min_age' => '0',
        ], $table);
    }
});

it('impostare le soglie non aspetta il dump e non ferma le scritture: blocco SHARE UPDATE EXCLUSIVE', function (): void {
    DB::commit();
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql_owner')]);
    $probe = DB::connection('pgsql_probe');
    $owner = DB::connection('pgsql_owner');
    try {
        // Come pg_dump: un blocco leggero tenuto per tutta la durata.
        $probe->beginTransaction();
        $probe->statement('LOCK TABLE app.transactions IN ACCESS SHARE MODE');

        $owner->beginTransaction();
        $owner->statement("SET LOCAL lock_timeout = '1s'");
        $owner->statement('ALTER TABLE app.transactions SET (autovacuum_vacuum_insert_threshold = 1000)');
        $mode = $owner->selectOne("SELECT string_agg(mode, ',') AS m FROM pg_locks WHERE pid = pg_backend_pid() AND relation = 'app.transactions'::regclass");
        $owner->rollBack();

        expect(is_object($mode) ? $mode->m : null)->toBe('ShareUpdateExclusiveLock');
    } finally {
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        if ($probe->transactionLevel() > 0) {
            $probe->rollBack();
        }
        DB::purge('pgsql_probe');
        DB::beginTransaction();
    }
});
