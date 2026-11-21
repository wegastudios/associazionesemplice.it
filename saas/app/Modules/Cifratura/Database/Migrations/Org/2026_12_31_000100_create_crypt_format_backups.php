<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copie d'appoggio dei campi cifrati portati al formato legato alla riga (saas:crypt:upgrade-format): il vecchio testo
 * cifrato (0x01) resta qui per 30 giorni, così il comando inverso può rimetterlo. Solo testo cifrato e l'impronta
 * (SHA-256) del nuovo, mai dati in chiaro. Migrazione solo additiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.crypt_format_backups (
                org_id      uuid        NOT NULL REFERENCES app.orgs(id),
                id          uuid        NOT NULL,
                table_name  text        NOT NULL CHECK (table_name ~ '^app\.[a-z_]+$'),
                row_id      uuid        NOT NULL,
                column_name text        NOT NULL CHECK (column_name ~ '^[a-z_]+_enc$'),
                old_value   bytea       NOT NULL,
                new_hash    bytea       NOT NULL CHECK (octet_length(new_hash) = 32),
                created_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                UNIQUE (org_id, table_name, row_id, column_name)
            )
        SQL);
        DB::statement('CREATE INDEX crypt_format_backups_created ON app.crypt_format_backups (org_id, created_at)');
        OrgSchema::isolate('app.crypt_format_backups');
        OrgSchema::grant('app.crypt_format_backups', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.crypt_format_backups');
    }
};
