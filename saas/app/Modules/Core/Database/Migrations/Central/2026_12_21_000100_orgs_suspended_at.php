<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Da quando un'associazione è sospesa o chiusa, scritto dal SaaS stesso (non dal portale): la cancellazione dei dati
 * conta i giorni da qui. Per le associazioni già sospese l'orologio parte dal giorno della migrazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE core.orgs ADD COLUMN suspended_at timestamptz');
        DB::statement("UPDATE core.orgs SET suspended_at = now() WHERE status IN ('suspended', 'closed') AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE core.orgs DROP COLUMN suspended_at');
    }
};
