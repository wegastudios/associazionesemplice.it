<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\CountPeopleJob;

/*
 * Richieste e lavori in coda: ognuno parte senza associazione e la azzera alla fine, così una connessione
 * riusata non porta mai con sé l'associazione precedente.
 */

beforeEach(function (): void {
    Route::get('/_test/entra/{org}', function (string $org, OrgContext $context) {
        $context->enter($org);

        return ['people' => Person::count()];
    });
    Route::get('/_test/vedi', fn () => [
        'setting' => DB::selectOne("SELECT coalesce(current_setting('app.org_id', true), '') AS v")->v,
        'people' => DB::table('app.people')->count(),
    ]);
});

it('una richiesta non lascia l\'associazione alla richiesta successiva', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($org->id, fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Alfa']));

    $this->getJson("/_test/entra/{$org->id}")->assertOk()->assertJson(['people' => 1]);

    // Stessa connessione, richiesta successiva senza associazione.
    $this->getJson('/_test/vedi')->assertOk()->assertExactJson(['setting' => '', 'people' => 0]);
    expect($this->orgSetting())->toBe('');
});

it('una richiesta parte senza associazione anche se la connessione ne aveva una', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($org->id, fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Alfa']));

    // Simula una connessione sporca: app.org_id impostato fuori da OrgContext.
    DB::select("SELECT set_config('app.org_id', ?, false)", [$org->id]);

    $this->getJson('/_test/vedi')->assertOk()->assertExactJson(['setting' => '', 'people' => 0]);
});

it('un lavoro in coda lavora nella sua associazione e la azzera alla fine', function (): void {
    $a = $this->createOrg('Polisportiva Alfa');
    $b = $this->createOrg('Circolo Beta');
    $this->orgContext()->run($a->id, function (): void {
        Person::create(['first_name' => 'Anna', 'last_name' => 'Alfa']);
        Person::create(['first_name' => 'Aldo', 'last_name' => 'Alfa']);
    });
    $this->orgContext()->run($b->id, fn () => Person::create(['first_name' => 'Bruno', 'last_name' => 'Beta']));

    CountPeopleJob::$seen = [];
    CountPeopleJob::dispatch($a->id);
    CountPeopleJob::dispatch($b->id);

    expect(CountPeopleJob::$seen)->toBe([$a->id => 2, $b->id => 1])
        ->and($this->orgSetting())->toBe('')
        ->and($this->orgContext()->current())->toBeNull();
});
