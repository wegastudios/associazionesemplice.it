<?php

declare(strict_types=1);

use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;

// Prova diagnostica (E1): con più associazioni popolate, i piani di alcune query tipiche usano gli indici?
// Fallisce apposta stampando i piani, per leggerli nel log della CI.
it('stampa i piani di esecuzione con la policy RLS attuale', function (): void {
    $orgs = [$this->createOrg('Alfa'), $this->createOrg('Beta'), $this->createOrg('Gamma')];
    foreach ($orgs as $org) {
        $this->orgContext()->run($org->id, function () use ($org): void {
            $base = Person::create(['first_name' => 'Base', 'last_name' => 'Socio']);
            $columns = DB::select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'app' AND table_name = 'people' AND is_generated = 'NEVER' ORDER BY ordinal_position");
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
            DB::statement('INSERT INTO app.people ('.implode(', ', $names).') SELECT '.implode(', ', $values).' FROM app.people p, generate_series(1, 20000) g WHERE p.id = ?', [$base->id]);
        });
    }

    $out = [];
    $this->orgContext()->run($orgs[0]->id, function () use (&$out, $orgs): void {
        $queries = [
            'elenco per cognome (senza org_id)' => ['SELECT id FROM app.people WHERE deleted_at IS NULL ORDER BY last_name, first_name LIMIT 50', []],
            'elenco per cognome (con org_id)' => ['SELECT id FROM app.people WHERE org_id = ? AND deleted_at IS NULL ORDER BY last_name, first_name LIMIT 50', [$orgs[0]->id]],
            'conteggio per tipo (senza org_id)' => ["SELECT count(*) FROM app.people WHERE type = 'ordinary' AND deleted_at IS NULL", []],
            'per id (senza org_id)' => ['SELECT * FROM app.people WHERE id = (SELECT id FROM app.people LIMIT 1)', []],
        ];
        foreach ($queries as $label => [$sql, $bindings]) {
            $plan = array_map(static fn (object $r): string => (string) ((array) $r)['QUERY PLAN'], DB::select('EXPLAIN (COSTS ON) '.$sql, $bindings));
            $out[] = "== {$label}\n".implode("\n", $plan);
        }
        $out[] = 'righe totali visibili: '.DB::scalar('SELECT count(*) FROM app.people');
        $out[] = "policy:\n".implode("\n", array_map(static fn (object $r): string => $r->polname.' => '.$r->using, DB::select("SELECT polname, pg_get_expr(polqual, polrelid) AS using FROM pg_policy WHERE polrelid = 'app.people'::regclass")));
    });

    throw new RuntimeException("\nPIANI_RLS_INIZIO\n".implode("\n\n", $out)."\nPIANI_RLS_FINE\n");
});
