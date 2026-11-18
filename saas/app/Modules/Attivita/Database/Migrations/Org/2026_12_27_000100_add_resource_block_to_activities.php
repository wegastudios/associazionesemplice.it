<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Blocco della risorsa per una manifestazione (dal plugin): più eventi con prenotazioni (anche le tre gare di una regata)
 * condividono un identificativo, e la risorsa resta occupata dal primo inizio all'ultima fine delle loro date non
 * annullate. Il periodo non si scrive: si ricalcola a ogni richiesta. Il nome è solo un'etichetta, uguale per tutto il
 * blocco; senza più eventi collegati il blocco sparisce da solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.activities ADD COLUMN resource_block text CHECK (length(resource_block) BETWEEN 1 AND 40)');
        DB::statement('ALTER TABLE app.activities ADD COLUMN resource_block_label text CHECK (length(resource_block_label) <= 120)');
        DB::statement("ALTER TABLE app.activities ADD CONSTRAINT activities_resource_block_check CHECK (resource_block IS NULL OR (kind = 'recurring' AND resource_id IS NOT NULL AND NOT occupy_on_booking))");
        DB::statement('CREATE INDEX activities_resource_block ON app.activities (org_id, resource_id, resource_block) WHERE resource_block IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS app.activities_resource_block');
        DB::statement('ALTER TABLE app.activities DROP CONSTRAINT activities_resource_block_check');
        DB::statement('ALTER TABLE app.activities DROP COLUMN resource_block_label, DROP COLUMN resource_block');
    }
};
