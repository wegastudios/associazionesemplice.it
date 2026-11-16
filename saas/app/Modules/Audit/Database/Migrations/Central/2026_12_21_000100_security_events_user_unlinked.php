<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il registro degli accessi è solo in aggiunta e non si modifica: il suo riferimento all'utente non deve impedire di
 * cancellare l'utente quando l'associazione viene cancellata. L'identificativo resta nel registro, senza più il legame.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE core.security_events DROP CONSTRAINT IF EXISTS security_events_user_id_fkey');
    }

    public function down(): void
    {
        // Il legame non si ripristina: potrebbe riferirsi a utenti ormai cancellati.
    }
};
