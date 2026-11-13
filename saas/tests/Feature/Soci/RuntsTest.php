<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\RuntsCounts;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
});

it('conta soci con diritto di voto, enti, volontari e lavoratori al 31 dicembre', function (): void {
    $dipendente = $this->createUser('dip@example.com', twoFactor: true);
    $this->addOperator($this->org, $dipendente, OrgRole::Employee);

    $counts = $this->orgContext()->run($this->org->id, function (): array {
        app(OrgSettings::class)->set('ente.kind', 'odv');
        Person::create(['type' => 'founder', 'first_name' => 'Franco', 'last_name' => 'Fondatore', 'joined_on' => '2015-03-01']);
        Person::create(['type' => 'volunteer', 'first_name' => 'Vera', 'last_name' => 'Volontaria', 'joined_on' => '2020-01-10']);
        Person::create(['type' => 'ordinary', 'first_name' => 'Mino', 'last_name' => 'Renne', 'joined_on' => '2020-01-10', 'birth_date' => '2015-05-05']);
        Person::create(['type' => 'ordinary', 'first_name' => 'Tardi', 'last_name' => 'Arrivato', 'joined_on' => '2026-02-01']);
        Person::create(['type' => 'guest', 'first_name' => 'Ospite', 'last_name' => 'Esterno', 'phone' => '333 1234567']);
        Person::create(['type' => 'founder', 'is_entity' => true, 'first_name' => '', 'last_name' => 'Pro Loco Beta', 'entity_same_section' => true, 'joined_on' => '2018-01-01']);
        Person::create(['type' => 'ordinary', 'is_entity' => true, 'first_name' => '', 'last_name' => 'Circolo Gamma', 'entity_same_section' => false, 'joined_on' => '2019-01-01']);

        return app(RuntsCounts::class)->at('2025-12-31');
    });

    expect($counts)->toMatchArray([
        'persons' => 2, 'entities' => 2, 'entities_same_section' => 1, 'volunteers' => 1, 'workers' => 1,
        'kind' => 'odv', 'minimum_applies' => true, 'below_minimum' => true,
    ])->and(array_column($counts['entity_list'], 'name'))->toBe(['Circolo Gamma', 'Pro Loco Beta']);
});

it('mostra la pagina RUNTS ai soli operatori che vedono l\'anagrafica', function (): void {
    $user = $this->createUser('segreteria@example.com', twoFactor: true);
    $this->addOperator($this->org, $user, OrgRole::Operator);
    $year = (int) now()->format('Y') - 1;

    $this->actingAs($user)->get('/gestionale/soci/runts')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Soci/Runts')->where('year', $year)->where('counts.date', "{$year}-12-31")->where('counts.minimum_applies', false));
});
