<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Regole dello schema, verificate sul database vero: valgono per ogni tabella presente e futura di app,
 * audit e sites. Una nuova tabella che non le rispetta fa fallire questi test.
 */

const ORG_SCHEMAS = ['app', 'audit', 'sites'];

/** @return list<object{table: string, rls: bool, forced: bool, policies: int, owner: string}> */
function orgTables(): array
{
    /** @var list<object{table: string, rls: bool, forced: bool, policies: int, owner: string}> */
    return DB::select(<<<'SQL'
        SELECT n.nspname || '.' || c.relname AS table,
               c.relrowsecurity AS rls,
               c.relforcerowsecurity AS forced,
               (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid) AS policies,
               pg_get_userbyid(c.relowner) AS owner
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = ANY (?) AND c.relkind IN ('r', 'p')
        ORDER BY 1
    SQL, ['{'.implode(',', ORG_SCHEMAS).'}']);
}

it('ha almeno le tabelle di base', function (): void {
    expect(array_column(orgTables(), 'table'))->toContain('app.orgs', 'app.people');
});

it('forza la Row-Level Security con una policy su ogni tabella', function (): void {
    foreach (orgTables() as $t) {
        expect($t->rls)->toBeTrue("{$t->table}: RLS non attiva")
            ->and($t->forced)->toBeTrue("{$t->table}: RLS non forzata")
            ->and($t->policies)->toBeGreaterThan(0, "{$t->table}: nessuna policy");
    }
});

it('mette org_id in testa alla chiave primaria di ogni tabella', function (): void {
    $rows = DB::select(<<<'SQL'
        SELECT n.nspname || '.' || c.relname AS table,
               (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attnum = con.conkey[1]) AS first_column
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        LEFT JOIN pg_constraint con ON con.conrelid = c.oid AND con.contype = 'p'
        WHERE n.nspname = ANY (?) AND c.relkind IN ('r', 'p')
    SQL, ['{'.implode(',', ORG_SCHEMAS).'}']);

    foreach ($rows as $row) {
        $expected = $row->table === 'app.orgs' ? 'id' : 'org_id';
        expect($row->first_column)->toBe($expected, "{$row->table}: chiave primaria senza {$expected} in testa");
    }
});

it('usa chiavi esterne composte con org_id e ognuna ha il suo indice', function (): void {
    $fks = DB::select(<<<'SQL'
        SELECT con.conname AS name,
               n.nspname || '.' || c.relname AS table,
               fn.nspname || '.' || fc.relname AS target,
               (SELECT array_agg(a.attname ORDER BY k.ord) FROM unnest(con.conkey) WITH ORDINALITY k(attnum, ord)
                  JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum)::text AS columns,
               EXISTS (
                 SELECT 1 FROM pg_index i
                 WHERE i.indrelid = con.conrelid
                   AND (i.indkey::int2[])[0:array_length(con.conkey, 1) - 1] = con.conkey
               ) AS indexed
        FROM pg_constraint con
        JOIN pg_class c ON c.oid = con.conrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_class fc ON fc.oid = con.confrelid
        JOIN pg_namespace fn ON fn.oid = fc.relnamespace
        WHERE con.contype = 'f' AND n.nspname = ANY (?)
    SQL, ['{'.implode(',', ORG_SCHEMAS).'}']);

    expect($fks)->not->toBeEmpty();

    foreach ($fks as $fk) {
        expect($fk->target)->not->toStartWith('core.', "{$fk->name}: una tabella delle associazioni non punta a core");

        if ($fk->target === 'app.orgs') {
            expect($fk->columns)->toBe('{org_id}', "{$fk->name}: verso app.orgs si punta con org_id");
        } else {
            expect($fk->columns)->toStartWith('{org_id,', "{$fk->name}: chiave esterna non composta con org_id");
        }

        expect($fk->indexed)->toBeTrue("{$fk->name}: chiave esterna senza indice");
    }
});

it('non dà privilegi speciali al ruolo dell\'applicazione', function (): void {
    $role = DB::selectOne(<<<'SQL'
        SELECT rolsuper, rolbypassrls, rolcreaterole, rolcreatedb,
               (SELECT count(*) FROM pg_auth_members m WHERE m.member = r.oid) AS memberships
        FROM pg_roles r WHERE rolname = current_user
    SQL);

    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and($role->rolcreaterole)->toBeFalse()
        ->and($role->rolcreatedb)->toBeFalse()
        ->and($role->memberships)->toBe(0);

    foreach (orgTables() as $t) {
        expect($t->owner)->not->toBe(DB::selectOne('SELECT current_user AS u')->u, "{$t->table}: il ruolo dell'applicazione è proprietario");
    }
});

it('non permette al ruolo dell\'applicazione di creare oggetti né di svuotare tabelle', function (): void {
    foreach ([...ORG_SCHEMAS, 'core', 'public'] as $schema) {
        $create = DB::selectOne('SELECT has_schema_privilege(current_user, ?, ?) AS ok', [$schema, 'CREATE'])->ok;
        expect($create)->toBeFalse("CREATE concesso sullo schema {$schema}");
    }

    $truncatable = DB::select(<<<'SQL'
        SELECT n.nspname || '.' || c.relname AS table
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname IN ('core', 'app', 'audit', 'sites') AND c.relkind IN ('r', 'p')
          AND (has_table_privilege(current_user, c.oid, 'TRUNCATE') OR has_table_privilege(current_user, c.oid, 'REFERENCES')
               OR has_table_privilege(current_user, c.oid, 'TRIGGER'))
    SQL);

    expect($truncatable)->toBe([]);
});

it('non lascia al ruolo dell\'applicazione la tabella delle migrazioni', function (): void {
    expect(DB::selectOne("SELECT has_table_privilege(current_user, 'public.migrations', 'SELECT') AS ok")->ok)->toBeFalse();
});
