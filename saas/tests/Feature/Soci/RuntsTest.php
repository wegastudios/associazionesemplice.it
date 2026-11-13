<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Models\MemberLevel;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\RuntsCounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $franco = Person::create(['type' => 'founder', 'first_name' => 'Franco', 'last_name' => 'Fondatore', 'joined_on' => '2015-03-01']);
        $vera = Person::create(['type' => 'volunteer', 'first_name' => 'Vera', 'last_name' => 'Volontaria', 'joined_on' => '2020-01-10']);
        Person::create(['type' => 'ordinary', 'first_name' => 'Mino', 'last_name' => 'Renne', 'joined_on' => '2020-01-10', 'birth_date' => '2015-05-05']);
        Person::create(['type' => 'ordinary', 'first_name' => 'Tardi', 'last_name' => 'Arrivato', 'joined_on' => '2026-02-01']);
        Person::create(['type' => 'guest', 'first_name' => 'Ospite', 'last_name' => 'Esterno', 'host_person_id' => $franco->id, 'phone' => '333 1234567']);
        Person::create(['type' => 'founder', 'is_entity' => true, 'first_name' => '', 'last_name' => 'Pro Loco Beta', 'entity_same_section' => true, 'joined_on' => '2018-01-01']);
        $gamma = Person::create(['type' => 'ordinary', 'is_entity' => true, 'first_name' => '', 'last_name' => 'Circolo Gamma', 'entity_same_section' => false, 'joined_on' => '2019-01-01']);
        $year = app(MembershipService::class)->socialYear('2025-12-31')->label();
        app(MembershipService::class)->grantManually($vera->id, $year, 'Prova');
        app(MembershipService::class)->grantManually($gamma->id, $year, 'Prova');

        return app(RuntsCounts::class)->at('2025-12-31');
    });

    expect($counts)->toMatchArray([
        'persons' => 2, 'entities' => 2, 'entities_same_section' => 1, 'volunteers' => 1, 'workers' => 1,
        'kind' => 'odv', 'minimum_applies' => true,
    ])->and(array_column($counts['entity_list'], 'name'))->toBe(['Circolo Gamma', 'Pro Loco Beta']);
});

it('mostra la pagina RUNTS ai soli operatori che vedono l\'anagrafica', function (): void {
    $user = $this->createUser('segreteria@example.com', twoFactor: true);
    $this->addOperator($this->org, $user, OrgRole::Operator);
    $year = (int) now()->format('Y') - 1;

    $this->actingAs($user)->get('/gestionale/soci/runts')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Soci/Runts')->where('year', $year)->where('counts.date', "{$year}-12-31")->where('counts.minimum_applies', false));
});

it('non conta chi ha un livello senza diritto di voto', function (): void {
    $counts = $this->orgContext()->run($this->org->id, function (): array {
        $senzaVoto = MemberLevel::create(['name' => 'Sostenitore', 'base_type' => 'ordinary', 'votes' => false]);
        Person::create(['type' => 'founder', 'first_name' => 'Franco', 'last_name' => 'Fondatore', 'joined_on' => '2015-03-01']);
        Person::create(['type' => 'founder', 'first_name' => 'Sara', 'last_name' => 'Sostenitrice', 'joined_on' => '2016-03-01', 'level_id' => $senzaVoto->id]);

        return app(RuntsCounts::class)->at('2025-12-31');
    });

    expect($counts['persons'])->toBe(1);
});

it('avvisa sotto il minimo con i soci di oggi e segnala le tessere scadute da poco', function (): void {
    $result = $this->orgContext()->run($this->org->id, function (): array {
        app(OrgSettings::class)->set('ente.kind', 'aps');
        Person::create(['type' => 'founder', 'first_name' => 'Franco', 'last_name' => 'Fondatore', 'joined_on' => '2015-03-01']);
        $vera = Person::create(['type' => 'ordinary', 'first_name' => 'Vera', 'last_name' => 'Rinnovi', 'joined_on' => '2020-01-10']);
        app(MembershipService::class)->grantManually($vera->id, app(MembershipService::class)->socialYear('2025-12-31')->label(), 'Prova');
        $end = (string) DB::table('app.valid_memberships')->where('person_id', $vera->id)->value('valid_to');
        $soon = Carbon::parse($end)->addDays(10)->toDateString();
        $late = Carbon::parse($end)->addDays(200)->toDateString();

        return [app(RuntsCounts::class)->today($soon), app(RuntsCounts::class)->today($late)];
    });

    expect($result[0]['persons'])->toBe(1)->and($result[0]['below_minimum'])->toBeTrue()
        ->and(array_column($result[0]['recently_expired'], 'name'))->toBe(['Rinnovi Vera'])
        ->and($result[1]['recently_expired'])->toBe([]);
});
