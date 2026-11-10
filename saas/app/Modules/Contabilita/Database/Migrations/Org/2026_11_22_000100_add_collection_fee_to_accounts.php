<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commissione di incasso per conto (dal plugin, API 18): quota fissa (al massimo 20 €) e percentuale in punti base (al
 * massimo 25%) che il conto trattiene a ogni incasso di segreteria o sul posto. Si registra come spesa «costo generale».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.accounts ADD COLUMN fee_fixed_cents integer NOT NULL DEFAULT 0 CHECK (fee_fixed_cents BETWEEN 0 AND 2000)');
        DB::statement('ALTER TABLE app.accounts ADD COLUMN fee_bp integer NOT NULL DEFAULT 0 CHECK (fee_bp BETWEEN 0 AND 2500)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.accounts DROP COLUMN fee_bp, DROP COLUMN fee_fixed_cents');
    }
};
