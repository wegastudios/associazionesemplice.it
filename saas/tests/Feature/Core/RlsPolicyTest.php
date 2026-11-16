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
