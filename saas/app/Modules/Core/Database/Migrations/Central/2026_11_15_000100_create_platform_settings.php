<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Impostazioni della piattaforma (posta, spazio dei backup, servizio chiavi, cache) modificabili dall'amministrazione
 * oltre che dal file d'ambiente. I valori sono cifrati con la chiave dell'applicazione. Gli amministratori della
 * piattaforma sono utenti con platform_admin, nominati da riga di comando.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.platform_settings (
                key        text        PRIMARY KEY CHECK (key ~ '^[a-z_]+\.[a-z_]+$'),
                value_enc  text        NOT NULL,
                updated_by uuid        REFERENCES core.users(id),
                updated_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX platform_settings_updated_by ON core.platform_settings (updated_by) WHERE updated_by IS NOT NULL');
        OrgSchema::grant('core.platform_settings', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        DB::statement('ALTER TABLE core.users ADD COLUMN platform_admin boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE core.users DROP COLUMN platform_admin');
        DB::statement('DROP TABLE core.platform_settings');
    }
};
