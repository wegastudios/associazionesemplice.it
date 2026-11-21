<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ingresso unico degli operatori: il legame tra utente del gestionale e persona dell'elenco unico è esplicito
 * (non più dedotto dall'email) e i super amministratori si riconoscono dall'identificativo della persona.
 * Solo tabelle nuove: nulla di quanto esiste cambia. I super amministratori già indicati in configurazione
 * restano tali: la migrazione li registra per identificativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.operator_links (
                user_id   uuid        PRIMARY KEY REFERENCES core.users(id) ON DELETE CASCADE,
                person_id uuid        NOT NULL UNIQUE REFERENCES core.staff_people(id) ON DELETE CASCADE,
                linked_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        OrgSchema::grant('core.operator_links', ['SELECT', 'INSERT', 'DELETE']);

        DB::statement(<<<'SQL'
            CREATE TABLE core.staff_super_admins (
                person_id  uuid        PRIMARY KEY REFERENCES core.staff_people(id) ON DELETE CASCADE,
                granted_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        OrgSchema::grant('core.staff_super_admins', ['SELECT', 'INSERT', 'DELETE']);

        $configured = config('saas.staff_issuer.super_admins', '');
        foreach (explode(',', is_string($configured) ? $configured : '') as $email) {
            $email = mb_strtolower(trim($email));
            if ($email === '') {
                continue;
            }
            $id = DB::table('core.staff_people')->where('email', $email)->value('id');
            if (is_string($id)) {
                DB::table('core.staff_super_admins')->insertOrIgnore(['person_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.staff_super_admins');
        DB::statement('DROP TABLE core.operator_links');
    }
};
