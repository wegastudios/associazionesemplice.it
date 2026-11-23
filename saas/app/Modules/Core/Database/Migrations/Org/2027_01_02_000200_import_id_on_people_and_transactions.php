<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Da una scheda o da un movimento nati da un import si risale all'importazione (app.import_jobs e la sua riga di registro
 * people.imported / ledger.imported, che riporta lo stesso id). Solo in aggiunta: nullo per tutto quello che c'era prima
 * e per quello inserito a mano. Niente chiave esterna: gli import finiti si cancellano dopo sette giorni, il riferimento
 * resta per il registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.people ADD COLUMN import_id uuid');
        DB::statement('ALTER TABLE app.transactions ADD COLUMN import_id uuid');
        DB::statement('CREATE INDEX people_import ON app.people (org_id, import_id) WHERE import_id IS NOT NULL');
        DB::statement('CREATE INDEX transactions_import ON app.transactions (org_id, import_id) WHERE import_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.transactions DROP COLUMN import_id');
        DB::statement('ALTER TABLE app.people DROP COLUMN import_id');
    }
};
