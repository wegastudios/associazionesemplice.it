<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eventi dell'area soci (H26): le date non annullate ancora in corso o future, cercate per fine (o giorno) e giorno.
 * Senza questo indice la pagina leggeva anche tutte le date passate dei corsi. Creato CONCURRENTLY, fuori transazione;
 * un indice non valido lasciato da un tentativo interrotto si toglie e si rifà.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $invalid = DB::selectOne(<<<'SQL'
            SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'app' AND c.relname = 'activity_sessions_upcoming' AND NOT i.indisvalid
        SQL);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY app.activity_sessions_upcoming');
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS activity_sessions_upcoming ON app.activity_sessions (org_id, (coalesce(end_date, session_date)), session_date) WHERE cancelled_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS app.activity_sessions_upcoming');
    }
};
