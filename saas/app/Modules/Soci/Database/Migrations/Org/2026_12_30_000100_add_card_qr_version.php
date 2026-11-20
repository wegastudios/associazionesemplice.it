<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Versione del codice QR della tessera: il socio può rigenerarlo e il vecchio smette di valere.
 * Colonna nuova con valore predefinito: i codici già in circolazione restano validi (versione 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.people ADD COLUMN card_qr_version integer NOT NULL DEFAULT 1 CHECK (card_qr_version >= 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.people DROP COLUMN card_qr_version');
    }
};
