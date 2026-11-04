<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Il ruolo dei backup legge anche lo schema core (senza RLS: bastano i permessi). */
return new class extends Migration
{
    public function up(): void
    {
        $backup = OrgSchema::backupRole();

        DB::statement("GRANT USAGE ON SCHEMA core TO {$backup}");
        DB::statement("GRANT SELECT ON ALL TABLES IN SCHEMA core TO {$backup}");
        DB::statement("GRANT SELECT ON ALL SEQUENCES IN SCHEMA core TO {$backup}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA core GRANT SELECT ON TABLES TO {$backup}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA core GRANT SELECT ON SEQUENCES TO {$backup}");
    }

    public function down(): void {}
};
