<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ruolo dei backup: legge tutto (anche le tabelle future, con i privilegi predefiniti) e non scrive nulla. Le policy
 * esistenti si aggiornano per ammetterlo in lettura; quelle nuove lo fanno già (OrgSchema::secure).
 */
return new class extends Migration
{
    public function up(): void
    {
        $backup = OrgSchema::backupRole();

        foreach (['app', 'audit', 'sites'] as $schema) {
            DB::statement("GRANT USAGE ON SCHEMA {$schema} TO {$backup}");
            DB::statement("GRANT SELECT ON ALL TABLES IN SCHEMA {$schema} TO {$backup}");
            DB::statement("GRANT SELECT ON ALL SEQUENCES IN SCHEMA {$schema} TO {$backup}");
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} GRANT SELECT ON TABLES TO {$backup}");
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} GRANT SELECT ON SEQUENCES TO {$backup}");
        }

        $policies = DB::select("SELECT schemaname || '.' || tablename AS t FROM pg_policies WHERE policyname = 'org_isolation' AND schemaname IN ('app', 'audit', 'sites')");
        foreach ($policies as $policy) {
            $table = is_object($policy) && isset($policy->t) && is_string($policy->t) ? $policy->t : '';
            $column = $table === 'app.orgs' ? 'id' : 'org_id';
            [$using, $check] = OrgSchema::conditions(DB::connection(), $column);
            DB::statement("ALTER POLICY org_isolation ON {$table} USING ({$using}) WITH CHECK ({$check})");
        }

        DB::statement("GRANT SELECT ON public.migrations, public.migrations_id_seq TO {$backup}");
    }

    public function down(): void {}
};
