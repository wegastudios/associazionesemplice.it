<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pagamenti online (H27): l'elenco in segreteria va dal più recente; con questo indice si leggono solo le righe mostrate.
 * Creato CONCURRENTLY, fuori transazione; un indice non valido lasciato da un tentativo interrotto si toglie e si rifà.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $invalid = DB::selectOne(<<<'SQL'
            SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'app' AND c.relname = 'online_payments_recent' AND NOT i.indisvalid
        SQL);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY app.online_payments_recent');
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS online_payments_recent ON app.online_payments (org_id, created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS app.online_payments_recent');
    }
};
