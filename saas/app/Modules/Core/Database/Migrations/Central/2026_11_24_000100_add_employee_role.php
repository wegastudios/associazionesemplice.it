<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Utente «Dipendente»: parte con i permessi della segreteria; titolare e amministratori possono togliergliene
 * alcuni, uno per uno (`denied_abilities`, solo da un elenco chiuso).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE core.org_users DROP CONSTRAINT org_users_role_check');
        DB::statement("ALTER TABLE core.org_users ADD CONSTRAINT org_users_role_check CHECK (role IN ('owner', 'admin', 'operator', 'accountant', 'custom', 'employee'))");
        DB::statement("ALTER TABLE core.org_users ADD COLUMN denied_abilities text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE core.org_users ADD CONSTRAINT org_users_denied_check CHECK (denied_abilities = '{}' OR role = 'employee')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE core.org_users DROP CONSTRAINT org_users_denied_check');
        DB::statement('ALTER TABLE core.org_users DROP COLUMN denied_abilities');
        DB::statement("UPDATE core.org_users SET role = 'operator' WHERE role = 'employee'");
        DB::statement('ALTER TABLE core.org_users DROP CONSTRAINT org_users_role_check');
        DB::statement("ALTER TABLE core.org_users ADD CONSTRAINT org_users_role_check CHECK (role IN ('owner', 'admin', 'operator', 'accountant', 'custom'))");
    }
};
