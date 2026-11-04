<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Campi sensibili dell'anagrafica, solo cifrati (DATABASE.md §5). Il codice fiscale ha anche l'impronta per la
 * ricerca esatta e per l'unicità nell'associazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE app.people
                ADD COLUMN tax_code_enc    bytea,
                ADD COLUMN tax_code_bidx   bytea,
                ADD COLUMN birth_date_enc  bytea,
                ADD COLUMN birth_place_enc bytea,
                ADD COLUMN address_enc     bytea,
                ADD COLUMN phone_enc       bytea,
                ADD COLUMN iban_enc        bytea,
                ADD CONSTRAINT people_tax_code_pair CHECK ((tax_code_enc IS NULL) = (tax_code_bidx IS NULL))
        SQL);

        DB::statement('CREATE UNIQUE INDEX people_tax_code_unique ON app.people (org_id, tax_code_bidx) WHERE deleted_at IS NULL AND tax_code_bidx IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.people DROP COLUMN tax_code_enc, DROP COLUMN tax_code_bidx, DROP COLUMN birth_date_enc, DROP COLUMN birth_place_enc, DROP COLUMN address_enc, DROP COLUMN phone_enc, DROP COLUMN iban_enc');
    }
};
