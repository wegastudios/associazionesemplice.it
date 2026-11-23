<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Secondo tempo di H28: toglie l'indice per scheda del registro (`audit.log_org_subject`), il più largo sulla tabella
 * più grande, pagato a ogni riga scritta e mai letto. Si rilascia SOLO dopo la conferma del primo tempo: azione
 * «indici-registro» della manutenzione del server (saas:audit:index-usage) con 0 letture su tutti i database, su un
 * periodo di statistiche di almeno un mese, e la prova che nessuna lettura del registro lo usa
 * (tests/Feature/Performance/SchemaMeasuresTest.php).
 *
 * Su una tabella partizionata `DROP INDEX` non si può fare CONCURRENTLY: prende un blocco forte sul registro e sulle
 * partizioni, ma per un istante (si tolgono solo i file degli indici). L'attesa del blocco è di 3 secondi al massimo:
 * dietro il dump orario la migrazione fallisce senza cambiare niente e si rilancia fuori dagli orari dei backup.
 *
 * down() ricrea l'indice: su una tabella partizionata non si può fare CONCURRENTLY, quindi blocca le scritture del
 * registro finché l'indice non è costruito su tutte le partizioni. Da usare solo fuori orario.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement('DROP INDEX IF EXISTS audit.log_org_subject');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS log_org_subject ON audit.log (org_id, subject_type, subject_id, occurred_at)');
    }
};
