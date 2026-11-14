<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il mese di inizio dell'anno fiscale si sceglie nella configurazione guidata: l'anno di partenza preparato con
 * l'associazione si rifà finché non ci sono movimenti. Il database lo consente solo se nessuno lo usa (le chiavi
 * esterne di movimenti e fotografie rifiutano la cancellazione di un anno fiscale in uso).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT DELETE ON app.fiscal_years TO '.OrgSchema::appRole());
    }

    public function down(): void
    {
        DB::statement('REVOKE DELETE ON app.fiscal_years FROM '.OrgSchema::appRole());
    }
};
