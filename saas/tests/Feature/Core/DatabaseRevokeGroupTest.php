<?php

declare(strict_types=1);

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
        ->and($remote)->toContain('echo "permessi del gruppo: da rivedere, vedi il controllo di salute" >&2');
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
