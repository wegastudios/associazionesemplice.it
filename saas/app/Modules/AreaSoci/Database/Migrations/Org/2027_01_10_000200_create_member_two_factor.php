<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Verifica in due passaggi dei soci (decisioni del titolare del 26-27/11/2026), una riga per la scheda di chi entra.
 * Tabella a parte e non colonne di app.people: resta fuori da copie, esportazioni e libro soci senza eccezioni.
 *
 * - secret: il segreto dell'app sul telefono, cifrato con la chiave dell'applicazione (come per gli operatori);
 * - confirmed_at: null finché il socio non ha scritto il primo codice giusto;
 * - recovery: impronte (password_hash) dei codici di riserva ancora validi, in JSON; ognuno vale una volta sola;
 * - last_step: l'ultimo intervallo di 30 secondi accettato: un codice già usato non vale più.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.member_two_factor (
                org_id       uuid        NOT NULL REFERENCES app.orgs(id),
                person_id    uuid        NOT NULL,
                secret       text        NOT NULL,
                confirmed_at timestamptz,
                recovery     jsonb       NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(recovery) = 'array'),
                last_step    bigint      NOT NULL DEFAULT 0,
                created_at   timestamptz NOT NULL DEFAULT now(),
                updated_at   timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, person_id),
                FOREIGN KEY (org_id, person_id) REFERENCES app.people(org_id, id) ON DELETE CASCADE
            )
        SQL);
        OrgSchema::isolate('app.member_two_factor');
        OrgSchema::grant('app.member_two_factor', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.member_two_factor');
    }
};
