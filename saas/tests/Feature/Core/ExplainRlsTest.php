<?php

declare(strict_types=1);

use App\Modules\Core\Database\DatabaseRegistry;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;

// Prova diagnostica (E1): con più associazioni popolate e statistiche aggiornate, i piani di alcune query tipiche con la
// policy attuale e con quella proposta. Fallisce apposta stampando i piani, per leggerli nel log della CI.
it('stampa i piani di esecuzione con la policy RLS attuale e con quella proposta', function (): void {
    $this->useDedicatedDatabase();
    $orgs = [$this->createOrg('Alfa', $this::DEDICATED), $this->createOrg('Beta', $this::DEDICATED), $this->createOrg('Gamma', $this::DEDICATED)];
    $connection = '';
    foreach ($orgs as $org) {
        $connection = $this->orgContext()->run($org->id, function () use (&$connection): string {
            $conn = $this->orgContext()->require()->connection;
            $base = Person::create(['first_name' => 'Base', 'last_name' => 'Socio']);
            $columns = DB::connection($conn)->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'app' AND table_name = 'people' AND is_generated = 'NEVER' ORDER BY ordinal_position");
            $names = [];
            $values = [];
            foreach ($columns as $c) {
                $col = (string) $c->column_name;
                $names[] = '"'.$col.'"';
                $values[] = match ($col) {
                    'id' => 'gen_random_uuid()',
                    'first_name' => "'Nome' || g",
                    'last_name' => "'Cognome' || (g % 997)",
                    'card_number', 'user_id', 'tax_code_bidx', 'tax_code_enc', 'matricola' => 'NULL',
                    default => 'p."'.$col.'"',
                };
            }
            DB::connection($conn)->statement('INSERT INTO app.people ('.implode(', ', $names).') SELECT '.implode(', ', $values).' FROM app.people p, generate_series(1, 20000) g WHERE p.id = ?', [$base->id]);

            return $conn;
        });
    }
    $owner = DB::connection($this->app->make(DatabaseRegistry::class)->ownerConnection($this::DEDICATED));
    $owner->statement('ANALYZE app.people');

    $original = (string) $owner->scalar("SELECT pg_get_expr(polqual, polrelid) FROM pg_policy WHERE polrelid = 'app.people'::regclass AND polname = 'org_isolation'");

    $plans = function () use ($orgs, $connection): string {
        $out = [];
        $this->orgContext()->run($orgs[0]->id, function () use (&$out, $orgs, $connection): void {
            $queries = [
                'elenco per cognome (senza org_id)' => ['SELECT id FROM app.people WHERE deleted_at IS NULL ORDER BY last_name, first_name LIMIT 50', []],
                'elenco per cognome (con org_id)' => ['SELECT id FROM app.people WHERE org_id = ? AND deleted_at IS NULL ORDER BY last_name, first_name LIMIT 50', [$orgs[0]->id]],
                'conteggio per tipo (senza org_id)' => ["SELECT count(*) FROM app.people WHERE type = 'ordinary' AND deleted_at IS NULL", []],
                'per id (senza org_id)' => ['SELECT * FROM app.people WHERE id = ?', ['00000000-0000-4000-8000-000000000001']],
            ];
            foreach ($queries as $label => [$sql, $bindings]) {
                $plan = array_map(static fn (object $r): string => (string) ((array) $r)['QUERY PLAN'], DB::connection($connection)->select('EXPLAIN '.$sql, $bindings));
                $out[] = "== {$label}\n".implode("\n", $plan);
            }
            $out[] = 'righe totali visibili: '.DB::connection($connection)->scalar('SELECT count(*) FROM app.people');
        });

        return implode("\n\n", $out);
    };

    $report = "POLICY ATTUALE: {$original}\n\n".$plans();
    try {
        $owner->statement("ALTER POLICY org_isolation ON app.people USING (org_id = (SELECT NULLIF(current_setting('app.org_id', true), '')::uuid))");
        $report .= "\n\n############ POLICY PROPOSTA (org_id = sottoselect)\n\n".$plans();
    } finally {
        $owner->statement("ALTER POLICY org_isolation ON app.people USING ({$original})");
    }

    throw new RuntimeException("\nPIANI_RLS_INIZIO\n{$report}\nPIANI_RLS_FINE\n".$backup);
});
