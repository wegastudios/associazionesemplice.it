<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rimborso dei pagamenti online in tre tempi: prima si segna «rimborso in corso» (importo e chiave), poi si chiama il
 * gestore senza blocchi sui conti, poi si registra. Se il gestore non risponde, il promemoria resta e ripetendo lo
 * stesso importo la chiave identica evita un secondo rimborso.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.online_payments ADD COLUMN refund_pending_cents bigint CHECK (refund_pending_cents IS NULL OR refund_pending_cents > 0), ADD COLUMN refund_key text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.online_payments DROP COLUMN refund_pending_cents, DROP COLUMN refund_key');
    }
};
