<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IVA delle attività, dei corsi e degli eventi. NULL = senza IVA (le attività già esistenti partono così);
 * 0 = «IVA esente»; da 1 a 30 = aliquota scelta tra quelle di «Aspetto fiscale». Solo con la partita IVA.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.activities ADD COLUMN vat_rate smallint');
        DB::statement('ALTER TABLE app.activities ADD CONSTRAINT activities_vat_rate_check CHECK (vat_rate IS NULL OR vat_rate BETWEEN 0 AND 30)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.activities DROP COLUMN vat_rate');
    }
};
