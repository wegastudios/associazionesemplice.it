<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Esito dell'ultima verifica completa del PDF di ogni ricevuta (H34): quale file (`checked_path`), con che esito e quando.
 * «Verifica delle ricevute» non legge e decifra più ogni file a ogni apertura: controlla che il file ci sia e abbia la
 * dimensione giusta, e per il contenuto si fida dell'ultima verifica completa, fatta alla creazione, di notte
 * (saas:receipts:verify, a blocchi: le ricevute già fissate si riempiono così) o prima di rifare un PDF.
 *
 * Solo colonne nuove, vuote e senza valore predefinito: nessuna riga si riscrive (docs/REGOLE_DI_LAVORO.md). Il vincolo
 * sui valori nasce NOT VALID: vale per le scritture nuove senza rileggere la tabella. Il blocco della tabella dura un
 * istante; l'attesa è di 3 secondi al massimo, poi la migrazione fallisce senza cambiare niente e si rilancia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement(<<<'SQL'
            ALTER TABLE app.receipt_files
                ADD COLUMN IF NOT EXISTS checked_path   text,
                ADD COLUMN IF NOT EXISTS checked_status text,
                ADD COLUMN IF NOT EXISTS checked_at     timestamptz,
                ADD CONSTRAINT receipt_files_checked_status CHECK (checked_status IN ('ok', 'missing', 'altered')) NOT VALID
        SQL);
        DB::statement('GRANT UPDATE (checked_path, checked_status, checked_at) ON app.receipt_files TO '.OrgSchema::appRole());
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement('ALTER TABLE app.receipt_files DROP CONSTRAINT IF EXISTS receipt_files_checked_status, DROP COLUMN IF EXISTS checked_at, DROP COLUMN IF EXISTS checked_status, DROP COLUMN IF EXISTS checked_path');
    }
};
