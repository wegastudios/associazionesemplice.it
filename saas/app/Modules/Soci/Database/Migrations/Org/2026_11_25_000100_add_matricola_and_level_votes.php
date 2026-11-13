<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allineamento al plugin: matricola fissa per ogni socio (assegnata una volta, mai cambiata né riassegnata, indice
 * unico; il contatore per associazione non torna indietro nemmeno se una scheda sparisce) e voto per livello
 * (`member_levels.votes`, un livello nuovo parte da sì).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.people ADD COLUMN matricola integer CHECK (matricola >= 1)');
        DB::statement('CREATE UNIQUE INDEX people_matricola_unique ON app.people (org_id, matricola) WHERE matricola IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE app.matricola_counters (
                org_id     uuid    PRIMARY KEY REFERENCES app.orgs(id),
                last_value integer NOT NULL CHECK (last_value >= 0)
            )
        SQL);
        OrgSchema::isolate('app.matricola_counters');
        OrgSchema::grant('app.matricola_counters', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement('ALTER TABLE app.member_levels ADD COLUMN votes boolean NOT NULL DEFAULT true');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.member_levels DROP COLUMN votes');
        DB::statement('DROP TABLE app.matricola_counters');
        DB::statement('DROP INDEX app.people_matricola_unique');
        DB::statement('ALTER TABLE app.people DROP COLUMN matricola');
    }
};
