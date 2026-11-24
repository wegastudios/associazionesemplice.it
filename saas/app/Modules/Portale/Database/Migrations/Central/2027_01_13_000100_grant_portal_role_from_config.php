<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Diritti sullo schema `portal` per il ruolo del portale, il cui nome viene dalla configurazione
 * (`database.connections.pgsql_owner.portal_role`, DB_PORTAL_USERNAME) e non è più scritto nella migrazione.
 *
 * La migrazione 2026_12_09_000100_create_portal_schema dava i diritti a un ruolo chiamato per forza `portal_app`: sul
 * server il ruolo ha il prefisso dell'account cPanel, quindi lì non dava nulla. Quella migrazione è già eseguita
 * ovunque e non si tocca (cambierebbe esito su database esistenti): questa la completa, ed è idempotente. Se il ruolo
 * configurato non esiste (database di prova, ruolo non ancora creato) non fa nulla e non fallisce; ripetuta dopo la
 * creazione del ruolo (`saas:migrate` non la rilancia: basta rieseguire gli stessi GRANT a mano, sono quelli qui sotto).
 *
 * I diritti sono gli stessi di prima (SELECT, INSERT, UPDATE, DELETE sulle tabelle dello schema): quali servano davvero a
 * ciascuna tabella lo stabilisce il restringimento che segue, non questa migrazione. Le tabelle future dello schema
 * (create dal proprietario) li ricevono da sole con i privilegi predefiniti.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = OrgSchema::portalRole();
        if ($role === null) {
            return;
        }
        if (DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) === null) {
            return;
        }

        $quoted = '"'.$role.'"';
        DB::statement("GRANT USAGE ON SCHEMA portal TO {$quoted}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA portal TO {$quoted}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA portal GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$quoted}");

        // Il fuso del ruolo: dove il proprietario non può cambiarlo (cPanel) si lascia stare, il portale scrive già in UTC.
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                ALTER ROLE {$quoted} SET timezone = 'UTC';
            EXCEPTION WHEN insufficient_privilege THEN
                RAISE NOTICE 'fuso del ruolo del portale non modificabile';
            END \$\$
        SQL);
    }

    public function down(): void
    {
        // I diritti non si ritirano: il ruolo del portale ne ha bisogno quanto lo schema.
    }
};
