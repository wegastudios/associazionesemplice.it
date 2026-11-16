<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Forma delle policy: l'associazione corrente letta con un sottoselect (così PostgreSQL usa gli indici su org_id anche
 * nelle query senza org_id) e il ruolo dei backup con una policy sua di sola lettura. Vale per ogni tabella e partizione
 * di app, audit e sites, presente e futura.
 */

/** @return list<object{table: string, policy: string, cmd: string, roles: string, qual: string|null, check: string|null}> */
function orgPolicies(): array
{
    /** @var list<object{table: string, policy: string, cmd: string, roles: string, qual: string|null, check: string|null}> */
    return DB::select("SELECT schemaname || '.' || tablename AS table, policyname AS policy, cmd, roles::text AS roles, qual, with_check AS check FROM pg_policies WHERE schemaname IN ('app', 'audit', 'sites') ORDER BY 1, 2");
}

it('la policy delle associazioni legge app.org_id con un sottoselect e non nomina nessun ruolo', function (): void {
    $isolation = array_values(array_filter(orgPolicies(), static fn (object $p): bool => $p->policy === 'org_isolation'));

    expect(count($isolation))->toBeGreaterThan(10);
    foreach ($isolation as $p) {
        expect($p->qual)->toContain('( SELECT (NULLIF(current_setting(')->not->toContain('current_user', "{$p->table}: nome di ruolo nella policy")
            ->and($p->check)->toBe($p->qual, "{$p->table}: scrittura diversa dalla lettura")
            ->and($p->cmd)->toBe('ALL')
            ->and($p->roles)->toBe('{public}');
    }
});

it('ogni tabella ha la policy di sola lettura per il ruolo dei backup, e nessun\'altra', function (): void {
    $backup = DB::connection('pgsql_owner')->getConfig('backup_role');
    $policies = orgPolicies();
    $byTable = [];
    foreach ($policies as $p) {
        $byTable[$p->table][$p->policy] = $p;
    }

    foreach ($byTable as $table => $list) {
        expect(array_keys($list))->toEqualCanonicalizing(['org_isolation', 'backup_read'], "{$table}: policy inattese");
        expect($list['backup_read']->cmd)->toBe('SELECT')
            ->and($list['backup_read']->roles)->toBe('{'.$backup.'}')
            ->and($list['backup_read']->qual)->toBe('true');
    }
});

it('il ruolo dei backup non ha nessun permesso di scrittura su nessuna tabella', function (): void {
    $backup = (string) DB::connection(app(DatabaseNames::class)->ownerConnection(null))->getConfig('backup_role');
    expect($backup)->not->toBe('');

    $rows = DB::connection(app(DatabaseNames::class)->ownerConnection(null))->select(
        "SELECT n.nspname || '.' || c.relname AS t, p.priv
         FROM pg_class c
         JOIN pg_namespace n ON n.oid = c.relnamespace
         CROSS JOIN (VALUES ('INSERT'), ('UPDATE'), ('DELETE'), ('TRUNCATE'), ('REFERENCES'), ('TRIGGER')) AS p(priv)
         WHERE c.relkind IN ('r', 'p') AND n.nspname IN ('app', 'audit', 'sites', 'core', 'public')
           AND has_table_privilege(?, c.oid, p.priv)
         ORDER BY 1, 2",
        [$backup],
    );

    expect($rows)->toBe([]);
});
