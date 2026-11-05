<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Nel registro anche le azioni dei soci dall'area soci: actor_type «member», actor_id = la persona. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit.log DROP CONSTRAINT log_actor_type_check');
        DB::statement("ALTER TABLE audit.log ADD CONSTRAINT log_actor_type_check CHECK (actor_type IN ('user', 'system', 'member'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit.log DROP CONSTRAINT log_actor_type_check');
        DB::statement("ALTER TABLE audit.log ADD CONSTRAINT log_actor_type_check CHECK (actor_type IN ('user', 'system'))");
    }
};
