<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lavori in attesa per coda (H13): li cercano il lavoratore e il controllo di salute (`saas:health`). Solo le righe non
 * prese da un lavoratore: l'indice resta piccolo anche con migliaia di promemoria in fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS jobs_waiting_idx ON core.jobs (queue, available_at) WHERE reserved_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS core.jobs_waiting_idx');
    }
};
