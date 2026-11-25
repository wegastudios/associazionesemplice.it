<?php

declare(strict_types=1);

namespace App\Modules\Core\Platform;

use App\Modules\Core\Contracts\PortalGrants;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Riporta ai permessi previsti quelli del ruolo di gruppo che possiede il database. Si usa la connessione del
 * proprietario. Non tocca i permessi diretti di applicazione, backup, proprietario e portale, né la proprietà degli
 * oggetti, né CONNECT. Idempotente: si agisce solo dove il gruppo ha una voce nei permessi.
 *
 * Si toglie solo ciò che il proprietario può togliere per davvero: oggetto per oggetto, solo se è suo e la voce l'ha
 * concessa lui; CREATE sul database solo se ha una voce diretta; i permessi sugli schemi solo se applicazione e backup
 * hanno il proprio accesso diretto. Il resto si conta tra i «rimasti». Prima di scrivere ci sono controlli di
 * sicurezza: se non passano non si scrive nulla.
 *
 * Nessun nome di ruolo o di oggetto esce da qui: solo conteggi.
 */
final class GroupPrivileges
{
    /** Voci dei permessi predefiniti: tabelle, sequenze, funzioni, tipi, schemi. */
    private const DEFAULT_KINDS = ['r' => 'TABLES', 'S' => 'SEQUENCES', 'f' => 'FUNCTIONS', 'T' => 'TYPES', 'n' => 'SCHEMAS'];

    private const BATCH = 100;

    /** Dove cercare gli oggetti con permessi del gruppo: origine, colonna dei permessi, proprietario, nome, filtro. */
    private const KINDS = [
        'tables' => ['from' => 'pg_class o JOIN pg_namespace n ON n.oid = o.relnamespace', 'acl' => 'o.relacl', 'owner' => 'o.relowner',
            'name' => "format('%I.%I', n.nspname, o.relname)", 'where' => "n.nspname = ANY (CAST({list} AS text[])) AND o.relkind IN ('r', 'p', 'v', 'm', 'f')"],
        'sequences' => ['from' => 'pg_class o JOIN pg_namespace n ON n.oid = o.relnamespace', 'acl' => 'o.relacl', 'owner' => 'o.relowner',
            'name' => "format('%I.%I', n.nspname, o.relname)", 'where' => "n.nspname = ANY (CAST({list} AS text[])) AND o.relkind = 'S'"],
        'functions' => ['from' => 'pg_proc o JOIN pg_namespace n ON n.oid = o.pronamespace', 'acl' => 'o.proacl', 'owner' => 'o.proowner',
            'name' => 'CAST(CAST(o.oid AS regprocedure) AS text)', 'where' => "n.nspname = ANY (CAST({list} AS text[])) AND o.prokind <> 'p'"],
        'procedures' => ['from' => 'pg_proc o JOIN pg_namespace n ON n.oid = o.pronamespace', 'acl' => 'o.proacl', 'owner' => 'o.proowner',
            'name' => 'CAST(CAST(o.oid AS regprocedure) AS text)', 'where' => "n.nspname = ANY (CAST({list} AS text[])) AND o.prokind = 'p'"],
        'schemas' => ['from' => 'pg_namespace o', 'acl' => 'o.nspacl', 'owner' => 'o.nspowner',
            'name' => "format('%I', o.nspname)", 'where' => 'o.nspname = ANY (CAST({list} AS text[]))'],
    ];

    /** Il ruolo di gruppo: proprietario del database, senza accesso, diverso dall'utente collegato. Null se non c'è. */
    public static function groupRole(Connection $db): ?string
    {
        $row = self::one($db, <<<'SQL'
            SELECT r.rolname AS g FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba
            WHERE d.datname = current_database() AND NOT r.rolcanlogin
              AND r.rolname <> 'pg_database_owner' AND r.rolname <> current_user
            SQL);

        return is_string($row['g'] ?? null) ? $row['g'] : null;
    }

    /**
     * Controlli prima di scrivere. Vuoto = si può procedere. Altrimenti i motivi, in parole generiche (mai nomi).
     * 1) il ruolo del portale (se esiste) ha tutti i permessi DIRETTI dell'elenco `PortalGrants`: ciò che raggiunge oltre
     *    l'elenco non blocca, lo toglie la revoca. Nessun altro ruolo (oltre ad applicazione, backup, proprietario e portale)
     *    raggiunge oggetti solo tramite il gruppo;
     * 2) applicazione e backup esistono e hanno i permessi diretti su tabelle e sequenze (l'applicazione fuori da public e portal,
     *    dove la tabella delle migrazioni è volutamente chiusa).
     *
     * @return list<string>
     */
    public static function guard(Connection $db, string $group, string $appRole, string $backupRole, ?string $portalRole = null): array
    {
        $reasons = [];
        $list = self::lit(self::pgArray(self::schemas($db)));
        $g = self::lit($group);
        $app = self::lit($appRole);
        $backup = self::lit($backupRole);

        $roles = self::one($db, "SELECT count(*) AS c FROM pg_roles WHERE rolname IN ({$app}, {$backup})");
        if ($appRole === '' || $backupRole === '' || (int) ($roles['c'] ?? 0) < 2) {
            return ['ruolo dell\'applicazione o dei backup non trovato'];
        }

        // Ruolo del portale: se non esiste (demo, ambienti senza portale) nessun controllo dedicato.
        $portalName = PortalGrants::validRole($portalRole ?? $db->getConfig('portal_role'));
        $portal = self::lit($portalName);
        $portalExists = $portalName !== '' && $portalName !== $appRole && $portalName !== $backupRole
            && self::one($db, "SELECT 1 AS x FROM pg_roles WHERE rolname = {$portal} AND rolname <> current_user") !== null;

        // Gli altri ruoli (non applicazione, backup, proprietario, portale) membri del gruppo che raggiungono oggetti solo
        // tramite il gruppo: tabelle (SELECT, INSERT, UPDATE, DELETE), sequenze (USAGE, SELECT, UPDATE), schemi (USAGE).
        $roleFilter = "r.rolcanlogin AND NOT r.rolsuper AND r.rolname NOT IN ({$app}, {$backup}, {$portal}, current_user)"
            ." AND pg_has_role(r.oid, (SELECT oid FROM pg_roles WHERE rolname = {$g}), 'USAGE')";
        $inSchemas = "n.nspname = ANY (CAST({$list} AS text[]))";
        $others = 0;
        foreach ([
            ['pg_class o JOIN pg_namespace n ON n.oid = o.relnamespace', 'o.relacl', 'o.relowner', "{$inSchemas} AND o.relkind IN ('r', 'p')",
                "(VALUES ('SELECT'), ('INSERT'), ('UPDATE'), ('DELETE')) p(v)", 'has_table_privilege'],
            ['pg_class o JOIN pg_namespace n ON n.oid = o.relnamespace', 'o.relacl', 'o.relowner', "{$inSchemas} AND o.relkind = 'S'",
                "(VALUES ('USAGE'), ('SELECT'), ('UPDATE')) p(v)", 'has_sequence_privilege'],
            ['pg_namespace o JOIN pg_namespace n ON n.oid = o.oid', 'o.nspacl', 'o.nspowner', $inSchemas,
                "(VALUES ('USAGE')) p(v)", 'has_schema_privilege'],
        ] as [$from, $acl, $owner, $where, $verbs, $has]) {
            $count = self::one($db, <<<SQL
                SELECT count(DISTINCT o.oid) AS c
                FROM {$from} CROSS JOIN pg_roles r CROSS JOIN {$verbs}
                WHERE {$where} AND {$roleFilter} AND {$owner} <> r.oid
                  AND {$has}(r.oid, o.oid, p.v)
                  AND NOT EXISTS (SELECT 1 FROM aclexplode({$acl}) a WHERE a.grantee IN (r.oid, 0) AND a.privilege_type = p.v)
                SQL);
            $others += (int) ($count['c'] ?? 0);
        }
        if ($others > 0) {
            $reasons[] = 'un altro ruolo con accesso raggiunge degli oggetti solo tramite il gruppo';
        }

        // Portale: ogni permesso diretto dell'elenco (PortalGrants) deve esserci, letto dalle voci dirette (non
        // dall'ereditarietà). Ciò che il portale raggiunge oltre l'elenco non blocca: lo toglie la revoca.
        if ($portalExists) {
            $values = PortalGrants::valuesSql();
            $schema = self::lit(PortalGrants::SCHEMA);
            $missing = self::one($db, <<<SQL
                SELECT (
                    SELECT count(*) FROM (VALUES {$values}) v(t, p)
                    JOIN pg_class c ON c.oid = to_regclass({$schema} || '.' || quote_ident(v.t)),
                         (SELECT oid FROM pg_roles WHERE rolname = {$portal}) r
                    WHERE c.relowner <> r.oid
                      AND NOT EXISTS (SELECT 1 FROM aclexplode(c.relacl) a WHERE a.grantee = r.oid AND a.privilege_type = v.p)
                ) + (
                    SELECT count(*) FROM pg_namespace s, (SELECT oid FROM pg_roles WHERE rolname = {$portal}) r
                    WHERE s.nspname = {$schema} AND s.nspowner <> r.oid
                      AND NOT EXISTS (SELECT 1 FROM aclexplode(s.nspacl) a WHERE a.grantee = r.oid AND a.privilege_type = 'USAGE')
                ) AS c
                SQL);
            if ((int) ($missing['c'] ?? 0) > 0) {
                $reasons[] = 'portale: permessi diretti mancanti';
            }
        }

        // Applicazione: nessuna tabella (fuori da public e portal, partizioni escluse) deve essere raggiungibile SOLO tramite il
        // gruppo, cioè l'applicazione ha un accesso effettivo (S/I/U/D) ma nessuna voce diretta, né sulla tabella né su una colonna.
        // Il confronto non va oltre la tabella: il gruppo ha tutto, quindi per un singolo privilegio l'accesso effettivo
        // dell'applicazione non distingue ciò che le serve da ciò che prende dal gruppo; i permessi attesi sono nelle migrazioni.
        $appOnly = self::one($db, <<<SQL
            SELECT count(*) AS c
            FROM pg_class o JOIN pg_namespace n ON n.oid = o.relnamespace, (SELECT oid FROM pg_roles WHERE rolname = {$app}) r
            WHERE n.nspname = ANY (CAST({$list} AS text[])) AND o.relkind IN ('r', 'p')
              AND n.nspname NOT IN ('public', 'portal') AND NOT o.relispartition AND o.relowner <> r.oid
              AND (has_table_privilege(r.oid, o.oid, 'SELECT') OR has_table_privilege(r.oid, o.oid, 'INSERT')
                   OR has_table_privilege(r.oid, o.oid, 'UPDATE') OR has_table_privilege(r.oid, o.oid, 'DELETE'))
              AND NOT EXISTS (SELECT 1 FROM aclexplode(o.relacl) a WHERE a.grantee = r.oid)
              AND NOT EXISTS (SELECT 1 FROM pg_attribute att, aclexplode(att.attacl) a WHERE att.attrelid = o.oid AND a.grantee = r.oid)
            SQL);
        if ((int) ($appOnly['c'] ?? 0) > 0) {
            $reasons[] = 'applicazione: tabelle raggiunte solo tramite il gruppo';
        }

        // Backup: lettura DIRETTA di ogni tabella e di ogni sequenza (pg_dump legge anche le sequenze).
        foreach ([['tabelle', "('r', 'p')"], ['sequenze', "('S')"]] as [$what, $kinds]) {
            $missing = self::one($db, <<<SQL
                SELECT count(*) AS c
                FROM pg_class o JOIN pg_namespace n ON n.oid = o.relnamespace, (SELECT oid FROM pg_roles WHERE rolname = {$backup}) r
                WHERE n.nspname = ANY (CAST({$list} AS text[])) AND o.relkind IN {$kinds} AND o.relowner <> r.oid
                  AND NOT EXISTS (SELECT 1 FROM aclexplode(o.relacl) a WHERE a.grantee = r.oid AND a.privilege_type = 'SELECT')
                SQL);
            if ((int) ($missing['c'] ?? 0) > 0) {
                $reasons[] = "backup: {$what} senza permesso di lettura diretto";
            }
        }

        // Applicazione: USAGE o UPDATE DIRETTO sulle sequenze delle tabelle in cui scrive (default nextval di una colonna seriale).
        $appSequences = self::one($db, <<<SQL
            SELECT count(DISTINCT s.oid) AS c
            FROM pg_class s JOIN pg_namespace n ON n.oid = s.relnamespace
            JOIN pg_depend d ON d.classid = CAST('pg_class' AS regclass) AND d.objid = s.oid AND d.deptype = 'a' AND d.refclassid = CAST('pg_class' AS regclass)
            JOIN pg_class t ON t.oid = d.refobjid, (SELECT oid FROM pg_roles WHERE rolname = {$app}) r
            WHERE n.nspname = ANY (CAST({$list} AS text[])) AND n.nspname NOT IN ('public', 'portal') AND s.relkind = 'S' AND s.relowner <> r.oid
              AND EXISTS (SELECT 1 FROM aclexplode(t.relacl) a WHERE a.grantee = r.oid AND a.privilege_type = 'INSERT')
              AND NOT EXISTS (SELECT 1 FROM aclexplode(s.relacl) a WHERE a.grantee = r.oid AND a.privilege_type IN ('USAGE', 'UPDATE'))
            SQL);
        if ((int) ($appSequences['c'] ?? 0) > 0) {
            $reasons[] = 'applicazione: sequenze senza permesso diretto';
        }

        return $reasons;
    }

    /**
     * Cosa ha ancora il gruppo: ciò che si può togliere e, in `blocked`, ciò che il proprietario non può togliere.
     *
     * @return array{tables: list<string>, sequences: list<string>, functions: list<string>, procedures: list<string>, schemas: list<string>, columns: list<array{0: string, 1: string}>, defaults: list<array{0: string, 1: ?string, 2: string}>, database: list<string>, blocked: int}
     */
    public static function inventory(Connection $db, string $group, string $appRole, string $backupRole): array
    {
        $list = self::lit(self::pgArray(self::schemas($db)));
        $g = self::lit($group);
        $blocked = 0;
        $out = ['tables' => [], 'sequences' => [], 'functions' => [], 'procedures' => [], 'schemas' => [], 'columns' => [], 'defaults' => [], 'database' => [], 'blocked' => 0];

        foreach (self::KINDS as $kind => $def) {
            // Sugli schemi si toglie solo se applicazione e backup hanno il loro USAGE diretto.
            $extra = '';
            if ($kind === 'schemas') {
                foreach ([$appRole, $backupRole] as $role) {
                    $extra .= ' AND EXISTS (SELECT 1 FROM aclexplode(o.nspacl) y WHERE y.grantee = (SELECT oid FROM pg_roles WHERE rolname = '.self::lit($role).") AND y.privilege_type = 'USAGE')";
                }
            }
            $where = str_replace('{list}', $list, $def['where']);
            $rows = self::rows($db, <<<SQL
                SELECT {$def['name']} AS o,
                       ({$def['owner']} = u.oid AND NOT EXISTS (SELECT 1 FROM aclexplode({$def['acl']}) x WHERE x.grantee = gr.oid AND x.grantor <> u.oid){$extra}) AS ok
                FROM {$def['from']},
                     (SELECT oid FROM pg_roles WHERE rolname = current_user) u,
                     (SELECT oid FROM pg_roles WHERE rolname = {$g}) gr
                WHERE {$where}
                  AND EXISTS (SELECT 1 FROM aclexplode({$def['acl']}) a WHERE a.grantee = gr.oid AND a.grantee <> {$def['owner']})
                ORDER BY 1
                SQL);
            foreach ($rows as $row) {
                if ($row['ok']) {
                    $out[$kind][] = (string) $row['o'];
                } else {
                    $blocked++;
                }
            }
        }

        foreach (self::rows($db, <<<SQL
            SELECT format('%I.%I', n.nspname, c.relname) AS t, quote_ident(att.attname) AS col,
                   (c.relowner = u.oid AND NOT EXISTS (SELECT 1 FROM aclexplode(att.attacl) x WHERE x.grantee = gr.oid AND x.grantor <> u.oid)) AS ok
            FROM pg_attribute att JOIN pg_class c ON c.oid = att.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace,
                 (SELECT oid FROM pg_roles WHERE rolname = current_user) u,
                 (SELECT oid FROM pg_roles WHERE rolname = {$g}) gr
            WHERE n.nspname = ANY (CAST({$list} AS text[])) AND att.attnum > 0 AND NOT att.attisdropped
              AND EXISTS (SELECT 1 FROM aclexplode(att.attacl) a WHERE a.grantee = gr.oid AND a.grantee <> c.relowner)
            SQL) as $row) {
            if ($row['ok']) {
                $out['columns'][] = [(string) $row['t'], (string) $row['col']];
            } else {
                $blocked++;
            }
        }

        // Permessi predefiniti: si cambiano solo per i ruoli di cui il proprietario è membro.
        foreach (self::rows($db, <<<SQL
            SELECT quote_ident(pg_get_userbyid(d.defaclrole)) AS r, n.nspname AS s, d.defaclobjtype AS k,
                   pg_has_role(current_user, d.defaclrole, 'MEMBER') AS m
            FROM pg_default_acl d LEFT JOIN pg_namespace n ON n.oid = d.defaclnamespace
            WHERE d.defaclobjtype IN ('r', 'S', 'f', 'T', 'n') AND (n.nspname IS NULL OR n.nspname = ANY (CAST({$list} AS text[])))
              AND EXISTS (SELECT 1 FROM aclexplode(d.defaclacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = {$g}) AND a.grantee <> d.defaclrole)
            SQL) as $row) {
            if ($row['m']) {
                $out['defaults'][] = [(string) $row['r'], $row['s'] === null ? null : (string) $row['s'], (string) $row['k']];
            } else {
                $blocked++;
            }
        }

        // CREATE e TEMPORARY sul database (CONNECT non si tocca mai): si tolgono solo con una voce DIRETTA del proprietario.
        foreach (self::rows($db, <<<SQL
            SELECT p.priv AS priv,
                   (d.datacl IS NULL OR EXISTS (SELECT 1 FROM aclexplode(d.datacl) a WHERE a.grantee = d.datdba AND a.privilege_type = p.priv)) AS has,
                   EXISTS (SELECT 1 FROM aclexplode(d.datacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = current_user) AND a.privilege_type = p.priv) AS direct
            FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba, (VALUES ('CREATE'), ('TEMPORARY')) p(priv)
            WHERE d.datname = current_database() AND r.rolname = {$g}
            SQL) as $row) {
            if (! $row['has']) {
                continue;
            }
            if ($row['direct']) {
                $out['database'][] = (string) $row['priv'];
            } else {
                $blocked++;
            }
        }

        $out['blocked'] = $blocked;

        return $out;
    }

    /**
     * Conteggi per tipo; «rimasti» = voci che il proprietario non può togliere.
     *
     * @param  array{tables: list<string>, sequences: list<string>, functions: list<string>, procedures: list<string>, schemas: list<string>, columns: list<array{0: string, 1: string}>, defaults: list<array{0: string, 1: ?string, 2: string}>, database: list<string>, blocked: int}  $inventory
     * @return array{tabelle: int, sequenze: int, funzioni: int, schemi: int, colonne: int, predefiniti: int, database: int, rimasti: int}
     */
    public static function counts(array $inventory): array
    {
        return [
            'tabelle' => count($inventory['tables']),
            'sequenze' => count($inventory['sequences']),
            'funzioni' => count($inventory['functions']) + count($inventory['procedures']),
            'schemi' => count($inventory['schemas']),
            'colonne' => count($inventory['columns']),
            'predefiniti' => count($inventory['defaults']),
            'database' => count($inventory['database']),
            'rimasti' => $inventory['blocked'],
        ];
    }

    /**
     * Toglie i permessi, in una transazione, se i controlli di sicurezza passano (altrimenti non scrive nulla).
     * `removed` = voci tolte; `remaining` = voci ancora del gruppo (compresi i «rimasti»); `refused` = motivi del rifiuto.
     *
     * @return array{removed: int, remaining: int, refused: list<string>}
     */
    public static function revoke(Connection $db, string $group, string $appRole, string $backupRole, ?string $portalRole = null): array
    {
        $before = self::inventory($db, $group, $appRole, $backupRole);
        $refused = self::guard($db, $group, $appRole, $backupRole, $portalRole);
        if ($refused !== []) {
            return ['removed' => 0, 'remaining' => array_sum(self::counts($before)), 'refused' => $refused];
        }
        $g = self::quote($group);

        $db->transaction(function () use ($db, $group, $appRole, $backupRole, $g, $before): void {
            foreach (['tables' => 'TABLE', 'sequences' => 'SEQUENCE', 'functions' => 'FUNCTION', 'procedures' => 'PROCEDURE'] as $key => $kind) {
                foreach (array_chunk($before[$key], self::BATCH) as $chunk) {
                    $db->statement("REVOKE ALL ON {$kind} ".implode(', ', $chunk)." FROM {$g}");
                }
            }

            // Le colonne con un permesso proprio che la tabella non aveva restano: si rilegge e si tolgono una per una.
            $perTable = [];
            foreach (self::inventory($db, $group, $appRole, $backupRole)['columns'] as [$table, $column]) {
                $perTable[$table][] = $column;
            }
            foreach ($perTable as $table => $columns) {
                $db->statement('REVOKE ALL ('.implode(', ', $columns).") ON TABLE {$table} FROM {$g}");
            }

            foreach ($before['schemas'] as $schema) {
                $db->statement("REVOKE ALL ON SCHEMA {$schema} FROM {$g}");
            }

            foreach ($before['defaults'] as [$role, $schema, $kind]) {
                $in = $schema === null ? '' : ' IN SCHEMA '.self::quote($schema);
                $db->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$role}{$in} REVOKE ALL ON ".self::DEFAULT_KINDS[$kind]." FROM {$g}");
            }

            if ($before['database'] !== []) {
                try {
                    $db->transaction(function () use ($db, $g, $before): void {
                        $db->statement("SET LOCAL ROLE {$g}");
                        $db->statement('REVOKE '.implode(', ', $before['database']).' ON DATABASE '.self::quote($db->getDatabaseName())." FROM {$g}");
                        $db->statement('RESET ROLE');
                    });
                } catch (Throwable) {
                    // Il proprietario non può assumere il ruolo di gruppo: resta tra i permessi rimasti.
                }
            }
        });

        $after = self::inventory($db, $group, $appRole, $backupRole);
        $removed = array_sum(self::counts($before)) - $before['blocked'] - (array_sum(self::counts($after)) - $after['blocked']);

        return ['removed' => max(0, $removed), 'remaining' => array_sum(self::counts($after)), 'refused' => []];
    }

    /** @return list<string> */
    private static function schemas(Connection $db): array
    {
        $names = [...RoleObjectAudit::EXPECTED_SCHEMAS];
        foreach (self::rows($db, "SELECT nspname FROM pg_namespace WHERE nspname ~ '^org_'") as $row) {
            $names[] = (string) $row['nspname'];
        }

        return $names;
    }

    /** @param  list<string>  $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $v): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $values)).'}';
    }

    /** Stringa letterale per SQL (nomi presi dal catalogo o dalla configurazione, mai da chi scrive). */
    private static function lit(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private static function rows(Connection $db, string $sql): array
    {
        /** @var list<array<string, scalar|null>> $rows */
        $rows = array_map(static fn (mixed $row): array => (array) $row, $db->select($sql));

        return $rows;
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private static function one(Connection $db, string $sql): ?array
    {
        return self::rows($db, $sql)[0] ?? null;
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
