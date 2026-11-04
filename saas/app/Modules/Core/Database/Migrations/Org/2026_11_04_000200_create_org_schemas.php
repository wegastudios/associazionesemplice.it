<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schemi delle associazioni: su ogni database, condiviso e dedicati.
 *
 * app.orgs è la copia locale dell'associazione: le tabelle di app, audit e sites puntano a lei, perché una
 * chiave esterna non può arrivare a core.orgs in un altro database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (['app', 'audit', 'sites'] as $schema) {
            DB::statement("CREATE SCHEMA {$schema}");
            DB::statement("REVOKE ALL ON SCHEMA {$schema} FROM PUBLIC");
            DB::statement("GRANT USAGE ON SCHEMA {$schema} TO ".OrgSchema::appRole());
        }

        Schema::create('app.orgs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->timestampsTz();
        });
        OrgSchema::isolate('app.orgs', orgColumn: 'id');
        OrgSchema::grant('app.orgs', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        foreach (['sites', 'audit', 'app'] as $schema) {
            DB::statement("DROP SCHEMA {$schema} CASCADE");
        }
    }
};
