<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'indice trigram su cognome e nome non lo usa nessuna ricerca: l'elenco cerca su `lower(...)` con un'altra espressione,
 * quindi PostgreSQL non lo sceglie mai e costa solo spazio e tempo a ogni scrittura. Il codice che lo toglie dal
 * cammino c'è già da prima: questo è il secondo tempo, in cui si toglie l'indice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS app.people_name_trgm');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS people_name_trgm ON app.people USING gin ((last_name || \' \' || first_name) gin_trgm_ops)');
    }
};
