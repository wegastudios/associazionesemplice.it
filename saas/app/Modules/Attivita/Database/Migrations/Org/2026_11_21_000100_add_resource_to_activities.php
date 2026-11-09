<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dove si svolge un'attività (dal plugin, API 18): una risorsa dell'associazione, oppure «nessuna» (fuori sede).
 * NULL con no_resource = false significa «non indicata» (attività create prima). occupy_on_booking: la risorsa risulta
 * occupata solo dalle date che hanno prenotazioni valide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.activities ADD COLUMN resource_id uuid');
        DB::statement('ALTER TABLE app.activities ADD COLUMN no_resource boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE app.activities ADD COLUMN occupy_on_booking boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE app.activities ADD CONSTRAINT activities_resource_fk FOREIGN KEY (org_id, resource_id) REFERENCES app.resources(org_id, id)');
        DB::statement('ALTER TABLE app.activities ADD CONSTRAINT activities_resource_check CHECK (NOT (no_resource AND resource_id IS NOT NULL))');
        DB::statement('CREATE INDEX activities_resource ON app.activities (org_id, resource_id) WHERE resource_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS app.activities_resource');
        DB::statement('ALTER TABLE app.activities DROP COLUMN occupy_on_booking, DROP COLUMN no_resource, DROP COLUMN resource_id');
    }
};
