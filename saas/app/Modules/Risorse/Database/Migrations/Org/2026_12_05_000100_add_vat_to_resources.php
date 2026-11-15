<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IVA delle risorse e delle prenotazioni. Sulla risorsa: NULL = senza IVA (le risorse esistenti partono così),
 * 0 = «IVA esente», da 1 a 30 = aliquota di «Aspetto fiscale». Sulla prenotazione resta l'aliquota di quando è stata
 * fatta: l'incasso la usa anche se poi l'IVA della risorsa cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['resources', 'reservations'] as $table) {
            DB::statement("ALTER TABLE app.{$table} ADD COLUMN vat_rate smallint");
            DB::statement("ALTER TABLE app.{$table} ADD CONSTRAINT {$table}_vat_rate_check CHECK (vat_rate IS NULL OR vat_rate BETWEEN 0 AND 30)");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.reservations DROP COLUMN vat_rate');
        DB::statement('ALTER TABLE app.resources DROP COLUMN vat_rate');
    }
};
