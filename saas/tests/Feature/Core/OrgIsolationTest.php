<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\NoOrgContext;
use App\Modules\Core\Models\Org;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Separazione tra associazioni: ogni test prova a leggere o scrivere i dati di un'altra associazione,
 * anche con query senza filtro, e deve fallire o non trovare nulla.
 */

/** @return array{0: Org, 1: Org, 2: Person, 3: Person} */
function twoOrgsWithOnePersonEach(): array
{
    $a = test()->createOrg('Polisportiva Alfa');
    $b = test()->createOrg('Circolo Beta');

    $anna = test()->orgContext()->run($a->id, fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Alfa']));
    $bruno = test()->orgContext()->run($b->id, fn () => Person::create(['first_name' => 'Bruno', 'last_name' => 'Beta']));

    return [$a, $b, $anna, $bruno];
}

it('mostra a ogni associazione solo le sue persone', function (): void {
    [$a, $b] = twoOrgsWithOnePersonEach();

    expect($this->orgContext()->run($a->id, fn () => Person::pluck('first_name')->all()))->toBe(['Anna'])
        ->and($this->orgContext()->run($b->id, fn () => Person::pluck('first_name')->all()))->toBe(['Bruno']);
});

it('filtra anche le query senza filtro, scritte a mano', function (): void {
    [$a, , , $bruno] = twoOrgsWithOnePersonEach();

    $this->orgContext()->run($a->id, function () use ($bruno): void {
        expect(DB::table('app.people')->count())->toBe(1)
            ->and(DB::table('app.people')->where('id', $bruno->id)->exists())->toBeFalse()
            ->and(DB::select('SELECT first_name FROM app.people'))->toHaveCount(1)
            ->and(DB::table('app.orgs')->count())->toBe(1);
    });
});

it('senza associazione impostata non mostra nessuna riga e i modelli si rifiutano', function (): void {
    twoOrgsWithOnePersonEach();

    expect(DB::table('app.people')->count())->toBe(0)
        ->and(DB::table('app.orgs')->count())->toBe(0)
        ->and(fn () => Person::query()->count())->toThrow(NoOrgContext::class)
        ->and(fn () => Person::create(['first_name' => 'X', 'last_name' => 'Y']))->toThrow(NoOrgContext::class);
});

it('rifiuta una riga scritta per un\'altra associazione', function (): void {
    [$a, $b] = twoOrgsWithOnePersonEach();

    $this->orgContext()->run($a->id, function () use ($b): void {
        expect(fn () => DB::transaction(fn () => DB::table('app.people')->insert([
            'org_id' => $b->id,
            'id' => (string) Str::uuid7(),
            'first_name' => 'Intruso',
            'last_name' => 'Intruso',
        ])))->toThrow(QueryException::class, 'row-level security');
    });

    expect($this->orgContext()->run($b->id, fn () => Person::count()))->toBe(1);
});

it('non modifica le righe di un\'altra associazione e non le sposta', function (): void {
    [$a, $b, $anna, $bruno] = twoOrgsWithOnePersonEach();

    $this->orgContext()->run($a->id, function () use ($b, $anna, $bruno): void {
        // La riga di B non si vede, quindi l'aggiornamento non tocca nulla.
        expect(DB::table('app.people')->where('id', $bruno->id)->update(['first_name' => 'Hackerato']))->toBe(0);

        // Spostare una riga propria nell'altra associazione viola la policy.
        expect(fn () => DB::transaction(fn () => DB::table('app.people')
            ->where('id', $anna->id)
            ->update(['org_id' => $b->id])))->toThrow(QueryException::class, 'row-level security');
    });

    expect($this->orgContext()->run($b->id, fn () => Person::sole()->first_name))->toBe('Bruno');
});

it('non permette al ruolo dell\'applicazione di cancellare righe', function (): void {
    [$a] = twoOrgsWithOnePersonEach();

    $this->orgContext()->run($a->id, function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('app.people')->delete()))
            ->toThrow(QueryException::class, 'permission denied');
    });
});

it('impedisce di impostare a mano un\'associazione diversa sul modello', function (): void {
    [$a, $b] = twoOrgsWithOnePersonEach();

    $person = $this->orgContext()->run($a->id, function () use ($b): Person {
        $person = new Person(['first_name' => 'Carla', 'last_name' => 'Alfa']);
        $person->forceFill(['org_id' => $b->id]);
        $person->save();

        return $person;
    });

    // Il modello riceve sempre l'associazione corrente, qualunque cosa ci sia scritto.
    expect($person->org_id)->toBe($a->id)
        ->and($this->orgContext()->run($b->id, fn () => Person::count()))->toBe(1);
});

it('azzera l\'associazione quando si esce e quando si cambia', function (): void {
    [$a, $b] = twoOrgsWithOnePersonEach();

    $this->orgContext()->enter($a->id);
    expect($this->orgSetting())->toBe($a->id);

    $this->orgContext()->enter($b->id);
    expect($this->orgSetting())->toBe($b->id);

    $this->orgContext()->leave();
    expect($this->orgSetting())->toBe('')
        ->and($this->orgContext()->current())->toBeNull();
});

it('ripristina l\'associazione precedente anche se il lavoro fallisce', function (): void {
    [$a, $b] = twoOrgsWithOnePersonEach();

    $this->orgContext()->enter($a->id);

    expect(fn () => $this->orgContext()->run($b->id, fn () => throw new RuntimeException('errore')))
        ->toThrow(RuntimeException::class);

    expect($this->orgContext()->require()->id)->toBe($a->id)
        ->and($this->orgSetting())->toBe($a->id);
});

it('rifiuta associazioni inesistenti, chiuse o con identificativo non valido', function (): void {
    [$a] = twoOrgsWithOnePersonEach();

    expect(fn () => $this->orgContext()->enter((string) Str::uuid7()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->orgContext()->enter("x' OR '1'='1"))->toThrow(InvalidArgumentException::class);

    $a->delete();

    expect(fn () => $this->orgContext()->enter($a->id))->toThrow(InvalidArgumentException::class)
        ->and($this->orgSetting())->toBe('');
});
