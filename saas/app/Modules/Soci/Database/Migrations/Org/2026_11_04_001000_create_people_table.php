<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anagrafica: prima tabella delle associazioni. I campi sensibili (codice fiscale, nascita, indirizzo,
 * telefono…) arrivano con la cifratura, in una migrazione successiva (DATABASE.md §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app.people', function (Blueprint $table): void {
            $table->uuid('org_id');
            $table->uuid('id');
            // Utente di accesso in core.users: senza chiave esterna, perché core può stare in un altro database.
            $table->uuid('user_id')->nullable();
            $table->text('first_name');
            $table->text('last_name');
            $table->addColumn('citext', 'email')->nullable();
            $table->text('card_number')->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->primary(['org_id', 'id']);
            $table->foreign('org_id')->references('id')->on('app.orgs');
        });

        DB::statement('CREATE UNIQUE INDEX people_card_number_unique ON app.people (org_id, card_number) WHERE deleted_at IS NULL AND card_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX people_user_unique ON app.people (org_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE INDEX people_name_trgm ON app.people USING gin ((last_name || \' \' || first_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX people_last_name ON app.people (org_id, last_name, first_name) WHERE deleted_at IS NULL');

        OrgSchema::isolate('app.people');
        // Niente DELETE: la cancellazione è logica, quella su richiesta rende anonimi i dati.
        OrgSchema::grant('app.people', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        Schema::drop('app.people');
    }
};
