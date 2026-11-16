<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Strumenti di misura (E28): query per richiesta che non crescono con i soci, piani di esecuzione senza scansioni
// complete, registro delle query lente. Dati di prova inventati.

/** Quante query esegue la richiesta, senza contare i controlli di PostgreSQL sul catalogo. */
function queriesOf(Closure $request): int
{
    $n = 0;
    DB::listen(static function ($query) use (&$n): void {
        $n++;
    });
    $request();

    return $n;
}

function addPeople(string $orgId, int $count, int $offset = 0): void
{
    test()->orgContext()->run($orgId, function () use ($count, $offset): void {
        for ($i = 0; $i < $count; $i++) {
            Person::create(['first_name' => 'Nome'.($offset + $i), 'last_name' => 'Cognome'.($offset + $i), 'email' => 'p'.($offset + $i).'@example.com']);
        }
    });
}

it('elenco e scheda dei soci eseguono lo stesso numero di query con 5 o con 25 soci', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);
    $this->actingAs($user);

    addPeople($org->id, 5);
    $id = $this->orgContext()->run($org->id, fn () => (string) Person::query()->orderBy('last_name')->value('id'));
    $this->get('/gestionale/soci')->assertOk(); // la prima richiesta riempie le cache di processo
    $listFew = queriesOf(fn () => $this->get('/gestionale/soci')->assertOk());
    $cardFew = queriesOf(fn () => $this->get("/gestionale/soci/{$id}")->assertOk());

    addPeople($org->id, 20, 5);
    $listMany = queriesOf(fn () => $this->get('/gestionale/soci')->assertOk());
    $cardMany = queriesOf(fn () => $this->get("/gestionale/soci/{$id}")->assertOk());

    expect($listMany)->toBe($listFew, 'elenco soci: le query crescono con i soci')
        ->and($cardMany)->toBe($cardFew, 'scheda socio: le query crescono con i soci');
});

it('con due associazioni popolate le query sulle tabelle grandi usano un indice, non la scansione completa', function (): void {
    $a = $this->createOrg('Polisportiva Alfa');
    $b = $this->createOrg('Circolo Beta');
    addPeople($a->id, 5);
    addPeople($b->id, 5);

    $owner = DB::connection(app(DatabaseNames::class)->ownerConnection(null));
    $queries = [
        'app.people' => 'SELECT * FROM app.people WHERE last_name = ?',
        'app.people per id' => 'SELECT * FROM app.people WHERE id = ?',
        'app.transactions' => 'SELECT * FROM app.transactions WHERE id = ?',
    ];
    $found = [];
    $owner->transaction(function () use ($owner, $a, $queries, &$found): void {
        $owner->select("SELECT set_config('app.org_id', ?, true)", [$a->id]);
        // Con la scansione completa vietata, se per una query esiste un indice utilizzabile il piano non la contiene:
        // se la policy sulle righe lo impedisse (E1), il piano la mostrerebbe lo stesso.
        $owner->statement('SET LOCAL enable_seqscan = off');
        foreach ($queries as $label => $sql) {
            $bind = str_contains($sql, 'last_name') ? 'Cognome1' : '00000000-0000-7000-8000-000000000000';
            $plan = json_decode((string) ($owner->select('EXPLAIN (FORMAT JSON) '.$sql, [$bind])[0]->{'QUERY PLAN'} ?? '[]'), true);
            array_walk_recursive($plan, static function (mixed $value, mixed $key) use (&$found, $label): void {
                if ($key === 'Node Type' && $value === 'Seq Scan') {
                    $found[] = $label;
                }
            });
        }
    });

    expect($found)->toBe([], 'scansione completa nel piano: '.implode(', ', $found));
});

it('registra le query lente senza i valori', function (): void {
    config(['saas.slow_query_ms' => 1]);
    Log::spy();

    DB::select('SELECT pg_sleep(0.05), ? AS segreto', ['valore-riservato-123']);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Query lenta'
        && ($context['ms'] ?? 0) >= 1
        && ! str_contains((string) json_encode($context), 'valore-riservato-123'))->atLeast()->once();
});

it('la salute segnala APCu e OPcache quando mancano, senza fallire', function (): void {
    $this->artisan('saas:health', ['--skip-migrations' => true])->assertSuccessful();
});
