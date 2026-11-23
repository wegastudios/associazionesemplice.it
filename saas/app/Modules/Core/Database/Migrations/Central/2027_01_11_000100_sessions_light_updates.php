<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sessioni degli operatori, aggiornamenti più leggeri (H19): a ogni richiesta Laravel aggiorna `last_activity`, e con un
 * indice su quella colonna ogni aggiornamento diventava una riga nuova in tutti gli indici (niente aggiornamento «sul
 * posto»). L'indice serviva solo alla pulizia delle sessioni scadute, su una tabella che ha le sole sessioni degli
 * operatori (le pagine dei soci e quelle pubbliche stanno nel cookie): basta una lettura della tabella.
 * `fillfactor` 60 lascia spazio nella pagina per gli aggiornamenti sul posto. Vale per le pagine nuove: la tabella è
 * piccola e si riempie da sola. Le soglie dell'autovacuum sono già in 2027_01_10_000200.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement('DROP INDEX IF EXISTS core.core_sessions_last_activity_index');
        DB::statement('ALTER TABLE core.sessions SET (fillfactor = 60)');
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement('ALTER TABLE core.sessions RESET (fillfactor)');
        DB::statement('CREATE INDEX IF NOT EXISTS core_sessions_last_activity_index ON core.sessions (last_activity)');
    }
};
