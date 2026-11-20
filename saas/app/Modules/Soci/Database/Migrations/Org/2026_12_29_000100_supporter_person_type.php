<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chi dona dalla pagina pubblica senza essere socio diventa una scheda «Sostenitore (non socio)»: serve per la ricevuta
 * (nome, codice fiscale), non conta tra i soci. Aggiunge solo il valore alla regola del tipo; i dati esistenti non cambiano.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.people DROP CONSTRAINT IF EXISTS people_type_check');
        DB::statement("ALTER TABLE app.people ADD CONSTRAINT people_type_check CHECK (type IN ('founder', 'ordinary', 'volunteer', 'guest', 'guardian', 'supporter'))");
    }
};
