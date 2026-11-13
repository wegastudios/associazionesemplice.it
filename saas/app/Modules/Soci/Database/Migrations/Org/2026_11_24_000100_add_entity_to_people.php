<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Socio che è un ente (associazione, società…): la denominazione sta in `last_name`, `first_name` resta vuoto.
 * `entity_same_section` serve al RUNTS: l'ente socio è della stessa sezione (ODV, APS…) o no.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.people ADD COLUMN is_entity boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE app.people ADD COLUMN entity_same_section boolean');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.people DROP COLUMN entity_same_section');
        DB::statement('ALTER TABLE app.people DROP COLUMN is_entity');
    }
};
