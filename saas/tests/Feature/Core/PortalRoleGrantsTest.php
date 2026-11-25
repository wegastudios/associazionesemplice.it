<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use App\Modules\Core\Contracts\PortalGrants;
use Illuminate\Support\Facades\DB;

/*
 * Il ruolo con cui il portale si collega allo schema `portal` viene dalla configurazione (DB_PORTAL_USERNAME), non da un
 * nome scritto nella migrazione: sul server ha il prefisso dell'account cPanel.
 */

/** Esegue la migrazione dei diritti con la connessione del proprietario e il ruolo del portale dato. */
function grantPortalRole(string $role): void
{
    config(['database.connections.pgsql_owner.portal_role' => $role]);
    DB::purge('pgsql_owner');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_owner');
    try {
        (require base_path('app/Modules/Portale/Database/Migrations/Central/2027_01_13_000100_grant_portal_role_from_config.php'))->up();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function portalPrivilege(string $role, string $table, string $privilege): bool
{
    return (bool) DB::connection('pgsql_owner')->selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, "portal.{$table}", $privilege])->ok;
}

it('il nome del ruolo del portale è nella configurazione e ha un valore di partenza', function (): void {
    expect(config('database.connections.pgsql_owner.portal_role'))->toBeString()->not->toBe('');

    config(['database.connections.pgsql_owner.portal_role' => 'portale; DROP SCHEMA portal']);
    DB::purge('pgsql_owner');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_owner');
    try {
        expect(OrgSchema::portalRole())->toBeNull();
    } finally {
        DB::setDefaultConnection($previous);
    }
});

it('dà allo schema portal i diritti al ruolo scritto in configurazione, qualunque sia il suo nome', function (): void {
    // as_app esiste sul database di prova e non ha nulla su portal: è il ruolo «con prefisso» di questa prova.
    $role = (string) config('database.connections.pgsql.username');
    $owner = DB::connection('pgsql_owner');
    expect(portalPrivilege($role, 'subscriptions', 'SELECT'))->toBeFalse();

    try {
        grantPortalRole($role);

        foreach (['subscriptions', 'orders', 'saas_orgs', 'releases', 'downloads', 'saas_outbox', 'settings', 'email_templates', 'audit', 'events'] as $table) {
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                expect(portalPrivilege($role, $table, $privilege))->toBeTrue("{$table} {$privilege}");
            }
            expect(portalPrivilege($role, $table, 'TRUNCATE'))->toBeFalse("{$table} TRUNCATE")
                ->and(portalPrivilege($role, $table, 'TRIGGER'))->toBeFalse("{$table} TRIGGER");
        }
        expect((bool) $owner->selectOne('SELECT has_schema_privilege(?, ?, ?) AS ok', [$role, 'portal', 'USAGE'])->ok)->toBeTrue()
            ->and((bool) $owner->selectOne('SELECT has_schema_privilege(?, ?, ?) AS ok', [$role, 'portal', 'CREATE'])->ok)->toBeFalse();

        // Ripetuta non cambia nulla né fallisce.
        grantPortalRole($role);
        expect(portalPrivilege($role, 'audit', 'DELETE'))->toBeTrue();
    } finally {
        $owner->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA portal REVOKE ALL ON TABLES FROM \"{$role}\"");
        $owner->statement("REVOKE ALL ON ALL TABLES IN SCHEMA portal FROM \"{$role}\"");
        $owner->statement("REVOKE ALL ON SCHEMA portal FROM \"{$role}\"");
    }

    expect(portalPrivilege($role, 'subscriptions', 'SELECT'))->toBeFalse();
});

it('la migrazione che restringe porta i permessi diretti del ruolo a quelli dell\'elenco, ed è idempotente', function (): void {
    $role = (string) config('database.connections.pgsql.username');
    $owner = DB::connection('pgsql_owner');
    $narrow = function () use ($role): void {
        config(['database.connections.pgsql_owner.portal_role' => $role]);
        DB::purge('pgsql_owner');
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_owner');
        try {
            (require base_path('app/Modules/Portale/Database/Migrations/Central/2027_01_14_000100_narrow_portal_role_grants.php'))->up();
        } finally {
            DB::setDefaultConnection($previous);
        }
    };

    try {
        grantPortalRole($role);
        expect(portalPrivilege($role, 'downloads', 'UPDATE'))->toBeTrue();

        $narrow();
        $narrow();

        foreach (PortalGrants::TABLES as $table => $verbs) {
            if ($owner->selectOne("SELECT to_regclass('portal.{$table}') AS t")->t === null) {
                continue;
            }
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                expect(portalPrivilege($role, $table, $privilege))->toBe(in_array($privilege, $verbs, true), "{$table} {$privilege}");
            }
        }
        expect((bool) $owner->selectOne('SELECT has_schema_privilege(?, ?, ?) AS ok', [$role, 'portal', 'USAGE'])->ok)->toBeTrue();
    } finally {
        $owner->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA portal REVOKE ALL ON TABLES FROM \"{$role}\"");
        $owner->statement("REVOKE ALL ON ALL TABLES IN SCHEMA portal FROM \"{$role}\"");
        $owner->statement("REVOKE ALL ON SCHEMA portal FROM \"{$role}\"");
    }
});

it('con un ruolo che non esiste la migrazione non fa nulla e non fallisce', function (): void {
    grantPortalRole('zz_ruolo_inesistente');

    expect(DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_roles WHERE rolname = 'zz_ruolo_inesistente'")->n)->toBe(0);
});
