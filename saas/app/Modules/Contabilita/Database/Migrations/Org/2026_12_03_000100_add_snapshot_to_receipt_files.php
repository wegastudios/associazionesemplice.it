<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fotografia dei dati della ricevuta (ente, nome, codice fiscale, tutori, righe con aliquote), cifrata con la chiave
 * dell'associazione e salvata all'emissione. Serve a rifare il PDF anche quando i dati vivi sono cambiati.
 * Vuota per le ricevute fissate prima di questo aggiornamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.receipt_files ADD COLUMN IF NOT EXISTS snapshot bytea');
        DB::statement('GRANT UPDATE (snapshot) ON app.receipt_files TO '.OrgSchema::appRole());
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.receipt_files DROP COLUMN IF EXISTS snapshot');
    }
};
