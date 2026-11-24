<?php

declare(strict_types=1);

use App\Modules\Core\Platform\GroupPrivileges;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Crea un ruolo di gruppo di prova (senza accesso) e lo toglie a fine prova. Il ruolo proprietario di CI non può creare
 * ruoli: si usa l'utente amministratore del servizio PostgreSQL di CI (PGUSER/PGPASSWORD), solo per creare e cancellare
 * il ruolo; le concessioni e le revoche le fa il proprietario. Senza quelle variabili la prova si salta: restano quelle
 * sull'interruttore, sul dry-run e sul testo di remote.sh.
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
        $test($owner, $group);
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
        $owner->statement("GRANT ALL ON ALL TABLES IN SCHEMA audit TO {$group}");
        $owner->statement("GRANT ALL ON ALL SEQUENCES IN SCHEMA core TO {$group}");
        $owner->statement("GRANT UPDATE (kind) ON core.org_notices TO {$group}");
        $owner->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA core GRANT SELECT ON TABLES TO {$group}");

        $appBefore = hasPrivilege($owner, 'as_app', 'core.org_notices', 'SELECT');
        $backupBefore = hasPrivilege($owner, 'as_backup', 'core.org_notices', 'SELECT');

        $before = GroupPrivileges::counts(GroupPrivileges::inventory($owner, $group));
        expect($before['tabelle'])->toBeGreaterThan(0)
            ->and($before['sequenze'])->toBeGreaterThan(0)
            ->and($before['colonne'])->toBeGreaterThan(0)
            ->and($before['predefiniti'])->toBe(1);
        expect(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeTrue();

        $result = GroupPrivileges::revoke($owner, $group);

        expect($result['remaining'])->toBe(0)
            ->and($result['removed'])->toBe(array_sum($before))
            ->and(hasPrivilege($owner, $group, 'audit.log', 'TRUNCATE'))->toBeFalse()
            ->and(hasPrivilege($owner, $group, 'audit.log', 'SELECT'))->toBeFalse()
            ->and(hasPrivilege($owner, 'as_app', 'core.org_notices', 'SELECT'))->toBe($appBefore)
            ->and(hasPrivilege($owner, 'as_backup', 'core.org_notices', 'SELECT'))->toBe($backupBefore);

        // Seconda volta: niente da fare.
        expect(GroupPrivileges::revoke($owner, $group))->toBe(['removed' => 0, 'remaining' => 0]);
    });
});

it('il rilascio lancia il comando dopo le migrazioni e prima del cambio del link, e il suo errore non lo ferma', function (): void {
    $remote = (string) file_get_contents(dirname(__DIR__, 3).'/deploy/remote.sh');
    $migrate = (int) strpos($remote, 'artisan saas:migrate --force');
    $revoke = (int) strpos($remote, 'artisan saas:db:revoke-group --if-enabled');
    $switch = (int) strpos($remote, 'mv -Tf "$BASE/current.new" "$BASE/current"');

    expect($migrate)->toBeGreaterThan(0)
        ->and($revoke)->toBeGreaterThan($migrate)
        ->and($switch)->toBeGreaterThan($revoke)
        ->and($remote)->toContain('artisan saas:db:revoke-group --if-enabled || echo');
});

it('l\'azione di manutenzione revoca-gruppo è nel workflow e nello script, in prova di default', function (): void {
    $base = dirname(__DIR__, 3);
    $ops = (string) file_get_contents($base.'/deploy/ops.sh');
    $workflow = (string) file_get_contents(dirname($base).'/.github/workflows/saas-ops.yml');

    expect($ops)->toContain('revoca-gruppo|revoca-gruppo-esegui)')
        ->and($ops)->toContain('OPT="--dry-run"; [ "$ACTION" = "revoca-gruppo-esegui" ] && OPT=""')
        ->and($workflow)->toContain('revoca-gruppo, revoca-gruppo-esegui');
});

it('l\'interruttore è documentato nei due esempi di ambiente: spento sul server, acceso sulla demo', function (): void {
    $deploy = dirname(__DIR__, 3).'/deploy';

    expect((string) file_get_contents($deploy.'/env.server.example'))->toContain("\nSAAS_DB_REVOKE_GROUP=false\n")
        ->and((string) file_get_contents($deploy.'/env.demo.example'))->toContain("\nSAAS_DB_REVOKE_GROUP=true\n");
});
