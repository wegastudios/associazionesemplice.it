<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rendiconto (H25): due indici sui movimenti non stornati che contengono già tipo e importo, così il rendiconto legge
 * solo l'indice. Per anno fiscale e voce (entrate e uscite dell'anno); per conto e data (saldi a inizio e fine anno).
 * Creati CONCURRENTLY, fuori transazione: la prima nota resta scrivibile mentre si costruiscono.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'transactions_statement_year' => '(org_id, fiscal_year, category_id) INCLUDE (type, amount_cents) WHERE voided_at IS NULL',
        'transactions_statement_account' => '(org_id, account_id, tx_date) INCLUDE (type, amount_cents) WHERE voided_at IS NULL',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            // Un tentativo interrotto (attesa del blocco scaduta) lascia l'indice non valido: si toglie e si rifà.
            $invalid = DB::selectOne(<<<'SQL'
                SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'app' AND c.relname = ? AND NOT i.indisvalid
            SQL, [$name]);
            if ($invalid !== null) {
                DB::statement("DROP INDEX CONCURRENTLY app.{$name}");
            }
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON app.transactions {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS app.{$name}");
        }
    }
};
