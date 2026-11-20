<?php

declare(strict_types=1);

use App\Modules\Contabilita\Ledger\ActivityFeeRename;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La voce «Quota attività / corso» diventa «Contributo attività / corso» per le associazioni esistenti. Solo dove il nome
 * salvato è ancora quello di partenza (le voci rinominate dall'associazione restano) e solo se la voce non è usata da
 * movimenti di un anno fiscale chiuso: quei rendiconti non cambiano (vedi ActivityFeeRename).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Per leggere le voci di tutte le associazioni la RLS forzata si toglie solo per questa transazione di migrazione.
        DB::statement('ALTER TABLE app.categories NO FORCE ROW LEVEL SECURITY');
        ActivityFeeRename::up();
        DB::statement('ALTER TABLE app.categories FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.categories NO FORCE ROW LEVEL SECURITY');
        ActivityFeeRename::down();
        DB::statement('ALTER TABLE app.categories FORCE ROW LEVEL SECURITY');
    }
};
