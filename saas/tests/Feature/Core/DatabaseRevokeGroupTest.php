<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\PortalGrants;
use App\Modules\Core\Platform\GroupPrivileges;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Crea un ruolo di gruppo di prova (senza accesso) e lo toglie a fine prova. Il ruolo proprietario di CI non può creare
 * ruoli: si usa l'utente amministratore del servizio PostgreSQL di CI (PGUSER/PGPASSWORD), solo per creare e cancellare
 * ruoli, schemi e database di prova; le concessioni e le revoche sotto esame le fa il proprietario. Senza quelle
 * variabili la prova si salta: restano quelle sull'interruttore, sul dry-run e sul testo degli script.
 */
function withTestGroup(Closure $test): void
{
    $user = getenv('PGUSER');
    $password = getenv('PGPASSWORD');
    if (! is_string($user) || $user === '' || ! is_string($password)) {
        test()->markTestSkipped('manca l\'utente amministratore del servizio PostgreSQL di prova');
    }
    $base = config('database.connections.pgsql_owner');
    config(['database.connections.zz_admin' => array_merge(is_array($base) ? $base : [], ['username' => $user, 'password' => $password])]);
    $admin = DB::connection('zz_admin');
    $owner = DB::connection('pgsql_owner');
    $group = 'zz_gruppo_prova';
    try {
        $admin->statement("CREATE ROLE {$group} NOLOGIN");
    } catch (Throwable) {
        test()->markTestSkipped('l\'utente amministratore di prova non può creare ruoli');
    }
    try {
        $test($owner, $group, $admin);
    } finally {
        $admin->statement("DROP OWNED BY {$group}");
        $admin->statement("DROP ROLE {$group}");
        DB::purge('zz_admin');
    }
}

function hasPrivilege(Connection $db, string $role, string $table, string $privilege): bool
{
    return (bool) $db->selectOne('SELECT has_table_privilege(?, ?, ?) AS h', [$role, $table, $privilege])->h;
}

function hasSchemaUsage(Connection $db, string $role, string $schema): bool
{
    return (bool) $db->selectOne("SELECT has_schema_privilege(?, ?, 'USAGE') AS h", [$role, $schema])->h;
}

/** @return array{0: string, 1: string} */
function revokeRoles(): array
{
    return ['as_app', 'as_backup'];
}

it('con l\'interruttore spento rifiuta e non tocca nulla', function (): void {
    config(['saas.db.revoke_group' => false]);

    $this->artisan('saas:db:revoke-group')
        ->expectsOutputToContain('Interruttore spento')
        ->assertFailed();
});

it('spento, con --if-enabled esce in silenzio con 0 (così lo lancia il rilascio)', function (): void {
    config(['saas.db.revoke_group' => false]);

    $this->artisan('saas:db:revoke-group', ['--if-enabled' => true])->doesntExpectOutputToContain('permessi')->assertSuccessful();
});

it('il dry-run funziona anche a interruttore spento e non scrive', function (): void {
    config(['saas.db.revoke_group' => false]);

    $this->artisan('saas:db:revoke-group', ['--dry-run' => true])->assertSuccessful();
});

it('senza ruolo di gruppo non fa nulla ed esce con 0', function (): void {
    config(['saas.db.revoke_group' => true]);
    $owner = DB::connection('pgsql_owner');
    if (GroupPrivileges::groupRole($owner) !== null) {
        $this->markTestSkipped('il database di prova ha un ruolo di gruppo');
    }

    $this->artisan('saas:db:revoke-group')
        ->expectsOutputToContain('nessun ruolo di gruppo')
        ->assertSuccessful();
});

it('toglie al gruppo permessi su tabelle, sequenze, colonne e predefiniti, senza toccare app e backup, ed è idempotente', function (): void {
    withTestGroup(function (Connection $owner, string $group): void {
        [$app, $backup] = revokeRoles();
        $owner->statement("GRANT ALL ON ALL TABLES IN SCHEMA audit TO {$group}");
        $owner->statement("GRANT ALL ON ALL SEQUENCES IN SCHEMA core TO {$group}");
        $owner->statement("GRANT UPDATE (kind) ON core.org_notices TO {$group}");
        $owner->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA core GRANT SELECT ON TABLES TO {$group}");

        $appBefore = hasPrivilege($owner, $app, 'core.org_notices', 'SELECT');
        $backupBefore = hasPrivilege($owner, $backup, 'core.org_notices', 'SELECT');

        $before = GroupPrivileges::counts(GroupPrivileges::inventory($owner, $group, $app, $backup));
        expect($before['tabelle'])->toBeGreaterThan(0)
            ->and($before['sequenze'])->toBeGreaterThan(0)
            ->and($before['colonne'])->toBeGreaterThan(0)
            ->and($before['predefiniti'])->toBe(1)
            ->and($before['rimasti'])->toBe(0);
        expect(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeTrue();

        $result = GroupPrivileges::revoke($owner, $group, $app, $backup);

        expect($result['refused'])->toBe([])
            ->and($result['remaining'])->toBe(0)
            ->and($result['removed'])->toBe(array_sum($before))
            ->and(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeFalse()
            ->and(hasPrivilege($owner, $group, 'audit.log', 'SELECT'))->toBeFalse()
            ->and(hasPrivilege($owner, $app, 'core.org_notices', 'SELECT'))->toBe($appBefore)
            ->and(hasPrivilege($owner, $backup, 'core.org_notices', 'SELECT'))->toBe($backupBefore);

        expect(GroupPrivileges::revoke($owner, $group, $app, $backup))->toBe(['removed' => 0, 'remaining' => 0, 'refused' => []]);
    });
});

it('rifiuta senza scrivere se un altro ruolo con accesso raggiunge le tabelle solo tramite il gruppo', function (): void {
    withTestGroup(function (Connection $owner, string $group, Connection $admin): void {
        [$app, $backup] = revokeRoles();
        $other = 'zz_altro_prova';
        $admin->statement("CREATE ROLE {$other} LOGIN");
        try {
            $owner->statement("GRANT ALL ON ALL TABLES IN SCHEMA audit TO {$group}");
            $admin->statement("GRANT {$group} TO {$other}");

            $result = GroupPrivileges::revoke($owner, $group, $app, $backup);

            expect($result['refused'])->toHaveCount(1)
                ->and($result['removed'])->toBe(0)
                ->and(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeTrue();
        } finally {
            $admin->statement("DROP OWNED BY {$other}");
            $admin->statement("DROP ROLE {$other}");
        }
    });
});

it('rifiuta senza scrivere se il backup non ha il permesso di lettura diretto su una tabella', function (): void {
    withTestGroup(function (Connection $owner, string $group): void {
        [$app, $backup] = revokeRoles();
        $owner->statement("GRANT ALL ON ALL TABLES IN SCHEMA audit TO {$group}");
        $owner->statement("REVOKE SELECT ON core.org_notices FROM {$backup}");
        try {
            $result = GroupPrivileges::revoke($owner, $group, $app, $backup);

            expect($result['refused'])->toBe(['backup: tabelle senza permesso di lettura diretto'])
                ->and(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeTrue();
        } finally {
            $owner->statement("GRANT SELECT ON core.org_notices TO {$backup}");
        }
    });
});

it('rifiuta senza scrivere se il backup non ha il permesso di lettura diretto su una sequenza', function (): void {
    withTestGroup(function (Connection $owner, string $group): void {
        [$app, $backup] = revokeRoles();
        $owner->statement("GRANT ALL ON ALL TABLES IN SCHEMA audit TO {$group}");
        $owner->statement("REVOKE SELECT ON SEQUENCE core.jobs_id_seq FROM {$backup}");
        try {
            $result = GroupPrivileges::revoke($owner, $group, $app, $backup);

            expect($result['refused'])->toBe(['backup: sequenze senza permesso di lettura diretto'])
                ->and(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeTrue();
        } finally {
            $owner->statement("GRANT SELECT ON SEQUENCE core.jobs_id_seq TO {$backup}");
        }
    });
});

it('rifiuta senza scrivere se l\'applicazione scrive su una tabella solo tramite il gruppo', function (): void {
    withTestGroup(function (Connection $owner, string $group, Connection $admin): void {
        [$app, $backup] = revokeRoles();
        $owner->statement('CREATE SCHEMA org_zzsolo');
        try {
            $owner->statement('CREATE TABLE org_zzsolo.t (a int)');
            $owner->statement("GRANT ALL ON org_zzsolo.t TO {$group}");
            $owner->statement("GRANT SELECT ON org_zzsolo.t TO {$backup}");
            $admin->statement("GRANT {$group} TO {$app}");

            $result = GroupPrivileges::revoke($owner, $group, $app, $backup);

            expect($result['refused'])->toBe(['applicazione: tabelle raggiunte solo tramite il gruppo'])
                ->and($result['removed'])->toBe(0)
                ->and(hasPrivilege($owner, $group, 'org_zzsolo.t', 'INSERT'))->toBeTrue();

            // Con una voce diretta per l'applicazione il controllo passa.
            $owner->statement("GRANT INSERT ON org_zzsolo.t TO {$app}");
            expect(GroupPrivileges::guard($owner, $group, $app, $backup))->toBe([]);
        } finally {
            $owner->statement('DROP SCHEMA IF EXISTS org_zzsolo CASCADE');
        }
    });
});

it('sugli schemi toglie al gruppo solo se applicazione e backup hanno l\'USAGE diretto, altrimenti lo conta tra i rimasti', function (): void {
    withTestGroup(function (Connection $owner, string $group): void {
        [$app, $backup] = revokeRoles();
        $owner->statement('CREATE SCHEMA org_zzprova');
        try {
            $owner->statement("GRANT USAGE ON SCHEMA org_zzprova TO {$group}");

            $blocked = GroupPrivileges::revoke($owner, $group, $app, $backup);
            expect($blocked['refused'])->toBe([])
                ->and($blocked['remaining'])->toBe(1)
                ->and(hasSchemaUsage($owner, $group, 'org_zzprova'))->toBeTrue();

            $owner->statement("GRANT USAGE ON SCHEMA org_zzprova TO {$app}, {$backup}");
            $done = GroupPrivileges::revoke($owner, $group, $app, $backup);
            expect($done)->toBe(['removed' => 1, 'remaining' => 0, 'refused' => []])
                ->and(hasSchemaUsage($owner, $group, 'org_zzprova'))->toBeFalse()
                ->and(hasSchemaUsage($owner, $app, 'org_zzprova'))->toBeTrue()
                ->and(hasSchemaUsage($owner, $backup, 'org_zzprova'))->toBeTrue();
        } finally {
            $owner->statement('DROP SCHEMA IF EXISTS org_zzprova');
        }
    });
});

it('gli oggetti che non sono del proprietario restano al gruppo e si contano tra i rimasti', function (): void {
    withTestGroup(function (Connection $owner, string $group, Connection $admin): void {
        [$app, $backup] = revokeRoles();
        $admin->statement('CREATE SCHEMA org_zzestraneo');
        try {
            $admin->statement('CREATE TABLE org_zzestraneo.t (a int)');
            $admin->statement("GRANT SELECT ON org_zzestraneo.t TO {$group}, {$app}, {$backup}");

            $result = GroupPrivileges::revoke($owner, $group, $app, $backup);

            expect($result['refused'])->toBe([])
                ->and($result['remaining'])->toBe(1)
                ->and(hasPrivilege($admin, $group, 'org_zzestraneo.t', 'SELECT'))->toBeTrue();
        } finally {
            $admin->statement('DROP SCHEMA IF EXISTS org_zzestraneo CASCADE');
        }
    });
});

it('CREATE sul database si toglie solo con una voce diretta del proprietario, altrimenti resta tra i rimasti', function (): void {
    withTestGroup(function (Connection $owner, string $group, Connection $admin): void {
        [$app, $backup] = revokeRoles();
        $name = 'zz_revoca_prova';
        $ownerRole = (string) $owner->getConfig('username');
        $admin->statement("DROP DATABASE IF EXISTS {$name}");
        $admin->statement("CREATE DATABASE {$name} OWNER {$group}");
        $admin->statement("GRANT {$group} TO {$ownerRole}");
        $base = config('database.connections.pgsql_owner');
        config(['database.connections.zz_scratch' => array_merge(is_array($base) ? $base : [], ['database' => $name])]);
        $scratch = DB::connection('zz_scratch');
        try {
            expect(GroupPrivileges::groupRole($scratch))->toBe($group);

            // Senza una voce diretta del proprietario: non si revoca, CREATE e TEMPORARY restano (2 rimasti).
            $none = GroupPrivileges::revoke($scratch, $group, $app, $backup);
            expect($none['refused'])->toBe([])
                ->and($none['remaining'])->toBe(2)
                ->and((bool) $scratch->selectOne("SELECT has_database_privilege(?, current_database(), 'CREATE') AS h", [$group])->h)->toBeTrue();

            // Con la voce diretta di CREATE: si toglie CREATE; TEMPORARY, senza voce diretta, resta.
            $admin->statement("GRANT CREATE ON DATABASE {$name} TO {$ownerRole}");
            $some = GroupPrivileges::revoke($scratch, $group, $app, $backup);
            expect($some['refused'])->toBe([])
                ->and($some['removed'])->toBe(1)
                ->and($some['remaining'])->toBe(1)
                ->and((bool) $scratch->selectOne("SELECT has_database_privilege(?, current_database(), 'CREATE') AS h", [$group])->h)->toBeFalse();
        } finally {
            DB::purge('zz_scratch');
            $admin->statement("DROP DATABASE IF EXISTS {$name}");
        }
    });
});

/**
 * Ruolo del portale di prova: LOGIN, membro del gruppo, con i permessi diretti dell'elenco `PortalGrants`; il gruppo ha
 * tutto sulle tabelle dello schema portal e di audit. Le tabelle mail_outbox e saas_usage, se mancano (le crea il portale), si creano per la prova.
 */
function withTestPortalRole(Connection $owner, Connection $admin, string $group, Closure $test): void
{
    $portal = 'zz_portale_prova';
    $created = array_values(array_filter(['mail_outbox', 'saas_usage'], static fn (string $t): bool => $owner->selectOne("SELECT to_regclass('portal.{$t}') AS t")->t === null));
    $admin->statement("CREATE ROLE {$portal} LOGIN");
    try {
        foreach ($created as $table) {
            $owner->statement("CREATE TABLE portal.{$table} (id text PRIMARY KEY)");
        }
        $admin->statement("GRANT {$group} TO {$portal}");
        $owner->statement("GRANT ALL ON ALL TABLES IN SCHEMA portal TO {$group}");
        $owner->statement("GRANT ALL ON ALL TABLES IN SCHEMA audit TO {$group}");
        PortalGrants::apply($owner, $portal);

        $test($portal);
    } finally {
        foreach ($created as $table) {
            $owner->statement("DROP TABLE IF EXISTS portal.{$table}");
        }
        $admin->statement("DROP OWNED BY {$portal}");
        $admin->statement("DROP ROLE {$portal}");
    }
}

it('con tutti i permessi diretti del portale i controlli passano e la revoca toglie al gruppo tutto, lasciando i diretti', function (): void {
    withTestGroup(function (Connection $owner, string $group, Connection $admin): void {
        [$app, $backup] = revokeRoles();
        withTestPortalRole($owner, $admin, $group, function (string $portal) use ($owner, $group, $app, $backup): void {
            expect(GroupPrivileges::guard($owner, $group, $app, $backup, $portal))->toBe([])
                ->and(hasPrivilege($owner, $group, 'portal.downloads', 'TRUNCATE'))->toBeTrue();

            $result = GroupPrivileges::revoke($owner, $group, $app, $backup, $portal);

            expect($result['refused'])->toBe([])
                ->and($result['removed'])->toBeGreaterThan(0)
                ->and(hasPrivilege($owner, $group, 'portal.downloads', 'TRUNCATE'))->toBeFalse()
                ->and(hasPrivilege($owner, $group, 'portal.subscriptions', 'SELECT'))->toBeFalse()
                ->and(hasPrivilege($owner, $group, 'audit.log', 'SELECT'))->toBeFalse();
            foreach (PortalGrants::tables() as $table => $verbs) {
                foreach ($verbs as $verb) {
                    expect(hasPrivilege($owner, $portal, "portal.{$table}", $verb))->toBeTrue("{$table} {$verb}");
                }
            }
            expect(hasPrivilege($owner, $portal, 'portal.downloads', 'UPDATE'))->toBeFalse()
                ->and(hasPrivilege($owner, $portal, 'portal.audit', 'DELETE'))->toBeFalse();
        });
    });
});

it('rifiuta senza scrivere se al portale manca anche un solo permesso diretto, senza nominare ruoli o tabelle', function (): void {
    withTestGroup(function (Connection $owner, string $group, Connection $admin): void {
        [$app, $backup] = revokeRoles();
        withTestPortalRole($owner, $admin, $group, function (string $portal) use ($owner, $group, $app, $backup): void {
            $owner->statement("REVOKE DELETE ON portal.downloads FROM {$portal}");

            $result = GroupPrivileges::revoke($owner, $group, $app, $backup, $portal);

            expect($result['refused'])->toBe(['portale: permessi diretti mancanti'])
                ->and($result['removed'])->toBe(0)
                ->and(hasPrivilege($owner, $group, 'portal.downloads', 'TRUNCATE'))->toBeTrue()
                ->and(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeTrue()
                ->and(implode(' ', $result['refused']))->not->toContain($portal)->not->toContain('downloads');
        });
    });
});

it('rifiuta se un altro ruolo di accesso, membro del gruppo e non in elenco, raggiunge oggetti solo tramite il gruppo', function (): void {
    withTestGroup(function (Connection $owner, string $group, Connection $admin): void {
        [$app, $backup] = revokeRoles();
        $other = 'zz_altro_login_prova';
        $admin->statement("CREATE ROLE {$other} LOGIN");
        try {
            withTestPortalRole($owner, $admin, $group, function (string $portal) use ($owner, $admin, $group, $other, $app, $backup): void {
                $admin->statement("GRANT {$group} TO {$other}");

                $result = GroupPrivileges::revoke($owner, $group, $app, $backup, $portal);

                expect($result['refused'])->toBe(['un altro ruolo con accesso raggiunge degli oggetti solo tramite il gruppo'])
                    ->and($result['removed'])->toBe(0)
                    ->and(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeTrue();
            });
        } finally {
            $admin->statement("DROP OWNED BY {$other}");
            $admin->statement("DROP ROLE {$other}");
        }
    });
});

it('senza il ruolo del portale non c\'è nessun controllo dedicato', function (): void {
    withTestGroup(function (Connection $owner, string $group): void {
        [$app, $backup] = revokeRoles();
        $owner->statement("GRANT ALL ON ALL TABLES IN SCHEMA audit TO {$group}");

        expect(GroupPrivileges::guard($owner, $group, $app, $backup, 'zz_portale_assente'))->toBe([]);

        $result = GroupPrivileges::revoke($owner, $group, $app, $backup, 'zz_portale_assente');
        expect($result['refused'])->toBe([])
            ->and(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeFalse();
    });
});

/** Ruolo del portale di prova SENZA permessi, usato dal comando saas:db:narrow-portal (nome dalla configurazione). */
function withBarePortalRole(Closure $test): void
{
    withTestGroup(function (Connection $owner, string $group, Connection $admin) use ($test): void {
        $portal = 'zz_portale_nudo';
        $created = array_values(array_filter(['mail_outbox', 'saas_usage'], static fn (string $t): bool => $owner->selectOne("SELECT to_regclass('portal.{$t}') AS t")->t === null));
        $admin->statement("CREATE ROLE {$portal} LOGIN");
        foreach ($created as $table) {
            $owner->statement("CREATE TABLE portal.{$table} (id text PRIMARY KEY)");
        }
        config(['database.connections.pgsql_owner.portal_role' => $portal]);
        DB::purge('pgsql_owner');
        try {
            $test(DB::connection('pgsql_owner'), $portal);
        } finally {
            foreach ($created as $table) {
                $owner->statement("DROP TABLE IF EXISTS portal.{$table}");
            }
            $admin->statement("DROP OWNED BY {$portal}");
            $admin->statement("DROP ROLE {$portal}");
            DB::purge('pgsql_owner');
        }
    });
}

it('permessi del portale: con l\'interruttore spento l\'esecuzione rifiuta, la prova elenca senza scrivere', function (): void {
    withBarePortalRole(function (Connection $owner, string $portal): void {
        config(['saas.db.narrow_portal' => false]);

        $this->artisan('saas:db:narrow-portal')->expectsOutputToContain('Interruttore spento')->assertFailed();
        expect(hasPrivilege($owner, $portal, 'portal.subscriptions', 'SELECT'))->toBeFalse();

        $this->artisan('saas:db:narrow-portal', ['--dry-run' => true])
            ->expectsOutputToContain('concede subscriptions: SELECT, INSERT, UPDATE, DELETE')
            ->expectsOutputToContain('concede (schema): USAGE')
            ->doesntExpectOutputToContain($portal)
            ->assertSuccessful();
        expect(hasPrivilege($owner, $portal, 'portal.subscriptions', 'SELECT'))->toBeFalse()
            ->and(hasSchemaUsage($owner, $portal, 'portal'))->toBeFalse();
    });
});

it('permessi del portale: con l\'interruttore acceso concede l\'elenco, toglie il resto ed è idempotente', function (): void {
    withBarePortalRole(function (Connection $owner, string $portal): void {
        config(['saas.db.narrow_portal' => true]);
        // Un permesso in più, fuori elenco, e un permesso predefinito: la sistemazione li toglie.
        $owner->statement("GRANT ALL ON portal.downloads TO {$portal}");
        $owner->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA portal GRANT SELECT ON TABLES TO {$portal}");

        $this->artisan('saas:db:narrow-portal')->doesntExpectOutputToContain($portal)->assertSuccessful();

        foreach (PortalGrants::tables() as $table => $verbs) {
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $verb) {
                expect(hasPrivilege($owner, $portal, "portal.{$table}", $verb))->toBe(in_array($verb, $verbs, true), "{$table} {$verb}");
            }
        }
        expect(hasPrivilege($owner, $portal, 'portal.downloads', 'TRUNCATE'))->toBeFalse()
            ->and(hasSchemaUsage($owner, $portal, 'portal'))->toBeTrue()
            ->and($owner->selectOne("SELECT count(*) AS n FROM pg_default_acl d, aclexplode(d.defaclacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = '{$portal}')")->n)->toBe(0);

        $this->artisan('saas:db:narrow-portal')->expectsOutputToContain('già a posto')->assertSuccessful();
    });
});

it('permessi del portale: se la verifica fallisce annulla tutto, il portale non resta a metà', function (): void {
    withTestGroup(function (Connection $owner, string $group, Connection $admin): void {
        $portal = 'zz_portale_rollback';
        if ($owner->selectOne("SELECT to_regclass('portal.mail_outbox') AS t")->t !== null) {
            test()->markTestSkipped('mail_outbox esiste già nel database di prova');
        }
        $admin->statement("CREATE ROLE {$portal} LOGIN");
        // Tabella dell'elenco che il proprietario non può concedere (non è sua): la concessione non va a buon fine.
        $admin->statement('CREATE TABLE portal.mail_outbox (id text PRIMARY KEY)');
        try {
            $owner->statement("GRANT ALL ON portal.downloads TO {$portal}");

            expect(fn () => PortalGrants::apply($owner, $portal))->toThrow(RuntimeException::class);

            // Nulla è cambiato: nessuna concessione dell'elenco e nessuna revoca (il permesso in più c'è ancora).
            expect(hasPrivilege($owner, $portal, 'portal.subscriptions', 'SELECT'))->toBeFalse()
                ->and(hasSchemaUsage($owner, $portal, 'portal'))->toBeFalse()
                ->and(hasPrivilege($owner, $portal, 'portal.downloads', 'TRUNCATE'))->toBeTrue()
                ->and(hasPrivilege($owner, $portal, 'portal.downloads', 'UPDATE'))->toBeTrue();
        } finally {
            $admin->statement('DROP TABLE IF EXISTS portal.mail_outbox');
            $admin->statement("DROP OWNED BY {$portal}");
            $admin->statement("DROP ROLE {$portal}");
        }
    });
});

it('l\'azione permessi-portale è nel workflow e nello script, mai nel rilascio, e l\'interruttore è spento negli esempi', function (): void {
    $base = dirname(__DIR__, 3);
    $ops = (string) file_get_contents($base.'/deploy/ops.sh');
    $workflow = (string) file_get_contents(dirname($base).'/.github/workflows/saas-ops.yml');
    $start = (int) strpos($ops, '  permessi-portale|permessi-portale-esegui)');
    $branch = substr($ops, $start, (int) strpos($ops, '    ;;', $start) - $start);

    expect($branch)->toContain('OPT="--dry-run"; [ "$ACTION" = "permessi-portale-esegui" ] && OPT=""')
        ->and($branch)->toContain('> "$F" 2>&1')
        ->and($branch)->not->toContain('cat "$F"')
        ->and($workflow)->toContain('permessi-portale, permessi-portale-esegui')
        ->and((string) file_get_contents($base.'/deploy/remote.sh'))->not->toContain('narrow-portal')
        ->and((string) file_get_contents($base.'/deploy/env.server.example'))->toContain("\nSAAS_DB_NARROW_PORTAL=false\n")
        ->and((string) file_get_contents($base.'/deploy/env.demo.example'))->toContain("\nSAAS_DB_NARROW_PORTAL=false\n");
});

it('il rilascio lancia il comando dopo le migrazioni e prima del cambio del link; log solo «fatto» o «da rivedere», dettaglio su file 600; non lo ferma', function (): void {
    $remote = (string) file_get_contents(dirname(__DIR__, 3).'/deploy/remote.sh');
    $migrate = (int) strpos($remote, 'artisan saas:migrate --force');
    $revoke = (int) strpos($remote, 'artisan saas:db:revoke-group --if-enabled');
    $switch = (int) strpos($remote, 'mv -Tf "$BASE/current.new" "$BASE/current"');

    expect($migrate)->toBeGreaterThan(0)
        ->and($revoke)->toBeGreaterThan($migrate)
        ->and($switch)->toBeGreaterThan($revoke)
        ->and($remote)->toContain('saas:db:revoke-group --if-enabled > "$GROUP_FILE" 2>&1 ) || GROUP_RC=$?')
        ->and($remote)->toContain('umask 077; "$PHP" artisan saas:db:revoke-group')
        ->and($remote)->toContain('echo "permessi del gruppo: fatto"')
        ->and($remote)->toContain('echo "permessi del gruppo: da rivedere, vedi il controllo di salute" >&2')
        // Senza file di uscita (interruttore spento) `ls` esce con 2: con pipefail e set -e il rilascio si fermerebbe.
        ->and($remote)->toContain('{ ls -1t "$BASE"/shared/revoca-gruppo-*.txt 2>/dev/null || true; } | tail -n +6');
});

it('l\'azione di manutenzione revoca-gruppo è nel workflow, in prova di default, e non stampa mai l\'uscita nel log', function (): void {
    $base = dirname(__DIR__, 3);
    $ops = (string) file_get_contents($base.'/deploy/ops.sh');
    $workflow = (string) file_get_contents(dirname($base).'/.github/workflows/saas-ops.yml');
    $start = (int) strpos($ops, '  revoca-gruppo|revoca-gruppo-esegui)');
    $branch = substr($ops, $start, (int) strpos($ops, '    ;;', $start) - $start);

    expect($branch)->toContain('OPT="--dry-run"; [ "$ACTION" = "revoca-gruppo-esegui" ] && OPT=""')
        ->and($branch)->toContain('> "$F" 2>&1')
        ->and($branch)->toContain('echo "$ACTION: salvato in $F"')
        ->and($branch)->not->toContain('cat "$F"')
        ->and($branch)->not->toContain('cat $F')
        ->and($workflow)->toContain('revoca-gruppo, revoca-gruppo-esegui');
});

it('l\'interruttore è documentato nei due esempi di ambiente: spento sul server, acceso sulla demo', function (): void {
    $deploy = dirname(__DIR__, 3).'/deploy';

    expect((string) file_get_contents($deploy.'/env.server.example'))->toContain("\nSAAS_DB_REVOKE_GROUP=false\n")
        ->and((string) file_get_contents($deploy.'/env.demo.example'))->toContain("\nSAAS_DB_REVOKE_GROUP=true\n");
});

it('utente dell\'account acceso: il controllo non lo blocca e la revoca gli lascia la sola lettura diretta, anche per il futuro', function (): void {
    withTestGroup(function (Connection $owner, string $group, Connection $admin): void {
        [$app, $backup] = revokeRoles();
        // cPanel chiama i ruoli «<account>_<nome>»: il gruppo di prova è «zz_gruppo_prova», quindi l'account è «zz».
        $account = 'zz';
        $admin->statement("CREATE ROLE {$account} LOGIN");
        try {
            $owner->statement("GRANT ALL ON ALL TABLES IN SCHEMA audit TO {$group}");
            $admin->statement("GRANT {$group} TO {$account}");

            config(['saas.db.account_reader' => false]);
            expect(GroupPrivileges::guard($owner, $group, $app, $backup))->toBe(['un altro ruolo con accesso raggiunge degli oggetti solo tramite il gruppo']);

            config(['saas.db.account_reader' => true]);
            expect(GroupPrivileges::accountReader($owner, $group, [$app, $backup]))->toBe($account)
                ->and(GroupPrivileges::guard($owner, $group, $app, $backup))->toBe([]);

            $result = GroupPrivileges::revoke($owner, $group, $app, $backup);

            expect($result['refused'])->toBe([])
                ->and(hasPrivilege($owner, $group, 'audit.log', 'SELECT'))->toBeFalse()
                ->and(hasPrivilege($owner, $account, 'audit.log', 'SELECT'))->toBeTrue()
                ->and(hasPrivilege($owner, $account, 'audit.log', 'INSERT'))->toBeFalse()
                ->and(hasPrivilege($owner, $account, 'audit.log', 'UPDATE'))->toBeFalse()
                ->and(hasPrivilege($owner, $account, 'audit.log', 'DELETE'))->toBeFalse()
                ->and(hasPrivilege($owner, $account, 'audit.log', 'TRUNCATE'))->toBeFalse()
                ->and(hasPrivilege($owner, $account, 'core.orgs', 'SELECT'))->toBeTrue()
                ->and(hasSchemaUsage($owner, $account, 'core'))->toBeTrue();

            // Una tabella creata dopo dal proprietario (una migrazione) si legge subito, senza scrittura.
            $owner->statement('CREATE TABLE core.zz_tabella_nuova (id int)');
            try {
                expect(hasPrivilege($owner, $account, 'core.zz_tabella_nuova', 'SELECT'))->toBeTrue()
                    ->and(hasPrivilege($owner, $account, 'core.zz_tabella_nuova', 'INSERT'))->toBeFalse();
            } finally {
                $owner->statement('DROP TABLE core.zz_tabella_nuova');
            }
        } finally {
            config(['saas.db.account_reader' => false]);
            $owner->statement("ALTER DEFAULT PRIVILEGES REVOKE ALL ON TABLES FROM {$account}");
            $owner->statement("ALTER DEFAULT PRIVILEGES REVOKE ALL ON SEQUENCES FROM {$account}");
            $owner->statement("ALTER DEFAULT PRIVILEGES REVOKE ALL ON SCHEMAS FROM {$account}");
            $admin->statement("DROP OWNED BY {$account}");
            $admin->statement("DROP ROLE {$account}");
        }
    });
});

it('utente dell\'account: mai uno dei ruoli del gestionale, mai un ruolo che non esiste, mai a interruttore spento', function (): void {
    withTestGroup(function (Connection $owner, string $group): void {
        [$app, $backup] = revokeRoles();
        config(['saas.db.account_reader' => true]);
        try {
            // «zz» non esiste in questa prova.
            expect(GroupPrivileges::accountReader($owner, $group, [$app, $backup]))->toBeNull()
                ->and(GroupPrivileges::accountReader($owner, 'senza-prefisso', [$app, $backup]))->toBeNull()
                ->and(GroupPrivileges::accountReader($owner, 'as_app_gruppo', ['as_app', 'as_backup']))->toBeNull();
            config(['saas.db.account_reader' => false]);
            expect(GroupPrivileges::accountReader($owner, 'as_gruppo', [$backup]))->toBeNull();
        } finally {
            config(['saas.db.account_reader' => false]);
        }
    });
});

it('copia dei permessi: legge senza scrivere, salva in un file 600 nuovo e il ritorno rimette ciò che la revoca ha tolto', function (): void {
    withTestGroup(function (Connection $owner, string $group): void {
        [$app, $backup] = revokeRoles();
        $owner->statement("GRANT SELECT, UPDATE ON audit.log TO {$group}");
        $file = sys_get_temp_dir().'/copia-permessi-prova-'.bin2hex(random_bytes(4)).'.sql';
        try {
            $this->artisan('saas:db:grants-copy', ['file' => $file])->expectsOutputToContain('permessi copiati')->assertSuccessful();
            expect(decoct(fileperms($file) & 0777))->toBe('600')
                ->and((string) file_get_contents($file))->toContain("GRANT UPDATE ON TABLE audit.log TO {$group};");
            // Un file che esiste già non si sovrascrive.
            $this->artisan('saas:db:grants-copy', ['file' => $file])->assertFailed();

            GroupPrivileges::revoke($owner, $group, $app, $backup);
            expect(hasPrivilege($owner, $group, 'audit.log', 'UPDATE'))->toBeFalse();

            $this->artisan('saas:db:grants-copy', ['file' => $file, '--ripristina' => true])->expectsOutputToContain('permessi rimessi')->assertSuccessful();
            expect(hasPrivilege($owner, $group, 'audit.log', 'UPDATE'))->toBeTrue()
                ->and(hasPrivilege($owner, $group, 'audit.log', 'SELECT'))->toBeTrue();
        } finally {
            @unlink($file);
        }
    });
});

it('le azioni copia-permessi e ripristina-copia-permessi sono nel workflow e nello script, l\'uscita va su file', function (): void {
    $base = dirname(__DIR__, 3);
    $ops = (string) file_get_contents($base.'/deploy/ops.sh');
    $workflow = (string) file_get_contents(dirname($base).'/.github/workflows/saas-ops.yml');
    $start = (int) strpos($ops, '  copia-permessi)');
    $branch = substr($ops, $start, (int) strpos($ops, '    ;;', $start) - $start);

    expect($branch)->toContain('artisan saas:db:grants-copy "$C"')
        ->and($branch)->toContain('echo "$ACTION: salvato in $C"')
        ->and($branch)->not->toContain('cat ')
        ->and($ops)->toContain('artisan saas:db:grants-copy --ripristina "$latest"')
        ->and($workflow)->toContain('copia-permessi, ripristina-copia-permessi')
        ->and((string) file_get_contents($base.'/deploy/env.server.example'))->toContain("\nSAAS_DB_ACCOUNT_READER=false\n");
});
