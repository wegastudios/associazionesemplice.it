<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La policy delle associazioni non contiene più «OR current_user = ruolo dei backup», che impediva di usare gli indici
 * su org_id nelle query senza org_id esplicito: org_id = (sottoselect su app.org_id). Il ruolo dei backup legge con una
 * policy sua, di sola lettura (backup_read). Vale per ogni tabella e partizione con la policy org_isolation. Una sola
 * transazione: se qualcosa fallisce non cambia niente. Nessun dato si riscrive.
 *
 * Per tornare indietro: ALTER POLICY org_isolation ... USING (org_id = NULLIF(current_setting('app.org_id', true), '')::uuid
 * OR current_user = '<ruolo backup>') WITH CHECK (org_id = NULLIF(...)) e DROP POLICY backup_read, per ogni tabella
 * (down() lo fa).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tables() as $table => $column) {
            [$using, $check] = OrgSchema::conditions(DB::connection(), $column);
            DB::statement("ALTER POLICY org_isolation ON {$table} USING ({$using}) WITH CHECK ({$check})");
            OrgSchema::allowBackupRead(DB::connection(), $table);
        }
    }

    public function down(): void
    {
        $backup = trim(OrgSchema::backupRole(), '"');
        foreach ($this->tables() as $table => $column) {
            $check = "{$column} = NULLIF(current_setting('app.org_id', true), '')::uuid";
            DB::statement("ALTER POLICY org_isolation ON {$table} USING ({$check} OR current_user = '{$backup}') WITH CHECK ({$check})");
            DB::statement("DROP POLICY IF EXISTS backup_read ON {$table}");
        }
    }

    /** @return array<string, string> tabella => colonna dell'associazione */
    private function tables(): array
    {
        $out = [];
        foreach (DB::select("SELECT schemaname || '.' || tablename AS t FROM pg_policies WHERE policyname = 'org_isolation' AND schemaname IN ('app', 'audit', 'sites') ORDER BY 1") as $row) {
            $table = is_object($row) && isset($row->t) && is_string($row->t) ? $row->t : '';
            if ($table !== '') {
                $out[$table] = $table === 'app.orgs' ? 'id' : 'org_id';
            }
        }

        return $out;
    }
};
