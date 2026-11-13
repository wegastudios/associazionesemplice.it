<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Libro soci di un esercizio chiuso: la fotografia com'era alla chiusura (righe con numero progressivo e i campi
 * decisi). Solo in aggiunta: alla riapertura la versione vigente si segna superata (`superseded_at`) e alla nuova
 * chiusura ne nasce un'altra. Non si cancella: nessuna cancellazione da un dato fiscale.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.member_book_snapshots (
                org_id        uuid        NOT NULL REFERENCES app.orgs(id),
                id            uuid        NOT NULL,
                fiscal_year   integer     NOT NULL,
                version       integer     NOT NULL CHECK (version >= 1),
                starts_on     date        NOT NULL,
                ends_on       date        NOT NULL,
                rows          jsonb       NOT NULL,
                created_by    uuid,
                created_at    timestamptz NOT NULL DEFAULT now(),
                superseded_at timestamptz,
                PRIMARY KEY (org_id, id),
                UNIQUE (org_id, fiscal_year, version),
                FOREIGN KEY (org_id, fiscal_year) REFERENCES app.fiscal_years(org_id, year)
            )
        SQL);
        OrgSchema::isolate('app.member_book_snapshots');
        OrgSchema::grant('app.member_book_snapshots', ['SELECT', 'INSERT']);
        DB::statement('GRANT UPDATE (superseded_at) ON app.member_book_snapshots TO '.OrgSchema::appRole());
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.member_book_snapshots');
    }
};
