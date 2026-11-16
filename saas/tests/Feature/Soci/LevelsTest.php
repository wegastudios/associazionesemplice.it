<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\MemberLevel;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\LevelService;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleService;
use Inertia\Testing\AssertableInertia as Assert;

it('usa la quota del livello nel rinnovo', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->enter($org->id);
    $junior = app(LevelService::class)->create('Socio junior', MemberType::Ordinary, 1500);
    $socio = Person::create(['first_name' => 'Luca', 'last_name' => 'Rossi', 'level_id' => $junior->id]);

    expect(app(MembershipService::class)->feeFor($socio))->toBe(1500)
        ->and(fn () => app(LevelService::class)->create('Ospiti', MemberType::Guest, null))->toThrow(PeopleError::class, 'tipo di socio')
        ->and(fn () => app(LevelService::class)->create(' ', MemberType::Ordinary, null))->toThrow(PeopleError::class, 'nome');

    app(MembershipService::class)->renewWithPayment($socio->id, Account::query()->sole()->id);
    expect(app(LevelService::class)->all()[0])->toMatchArray(['name' => 'Socio junior', 'fee_cents' => 1500, 'people' => 1]);
});

it('fa gestire i livelli solo a titolare e amministratore', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $operator = $this->createUser('segreteria@example.com', twoFactor: true);
    $this->addOperator($org, $operator, OrgRole::Operator);
    $admin = $this->createUser('admin@example.com', twoFactor: true);
    $this->addOperator($org, $admin, OrgRole::Admin);

    $this->actingAs($operator)->get('/gestionale/soci/livelli')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    $this->post('/gestionale/soci/livelli', ['name' => 'Junior', 'base_type' => 'ordinary'])->assertForbidden();

    $this->actingAs($admin)->post('/gestionale/soci/livelli', ['name' => 'Junior', 'base_type' => 'ordinary', 'fee' => '15,00'])->assertSessionHas('status', 'Livello creato.');
    $id = $this->orgContext()->run($org->id, fn () => MemberLevel::query()->sole()->id);
    $this->put("/gestionale/soci/livelli/{$id}", ['name' => 'Junior', 'base_type' => 'ordinary', 'fee' => '12,00', 'active' => false])->assertSessionHas('status', 'Livello salvato.');

    $this->get('/gestionale/soci/livelli')->assertInertia(fn (Assert $page) => $page->component('Soci/Livelli')->where('levels.0.fee', '12,00')->where('levels.0.active', false));
});

it('fa votare un livello nuovo e lascia toglierne il voto', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $admin = $this->createUser('admin@example.com', twoFactor: true);
    $this->addOperator($org, $admin, OrgRole::Admin);

    $this->actingAs($admin)->post('/gestionale/soci/livelli', ['name' => 'Junior', 'base_type' => 'ordinary']);
    $id = $this->orgContext()->run($org->id, fn () => MemberLevel::query()->sole()->id);
    $this->get('/gestionale/soci/livelli')->assertInertia(fn (Assert $page) => $page->where('levels.0.votes', true));

    $this->put("/gestionale/soci/livelli/{$id}", ['name' => 'Junior', 'base_type' => 'ordinary', 'votes' => false]);
    $this->get('/gestionale/soci/livelli')->assertInertia(fn (Assert $page) => $page->where('levels.0.votes', false));
});

it('lascia salvare la scheda di chi ha un livello non più attivo, senza proporlo agli altri', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $admin = $this->createUser('admin@example.com', twoFactor: true);
    $this->addOperator($org, $admin, OrgRole::Admin);
    $this->actingAs($admin);

    [$socio, $altro, $livello] = $this->orgContext()->run($org->id, function (): array {
        $levels = app(LevelService::class);
        $junior = $levels->create('Junior', MemberType::Ordinary, null);
        $socio = Person::create(['first_name' => 'Luca', 'last_name' => 'Rossi', 'level_id' => $junior->id]);
        $altro = Person::create(['first_name' => 'Anna', 'last_name' => 'Blu']);
        $levels->update($junior->id, 'Junior', MemberType::Ordinary, null, false);

        return [$socio, $altro, $junior];
    });

    // Salvataggio senza modifiche: il livello attuale resta valido.
    $this->put("/gestionale/soci/{$socio->id}", ['first_name' => 'Luca', 'last_name' => 'Rossi', 'type' => 'ordinary', 'level_id' => $livello->id])->assertSessionHasNoErrors();
    expect($this->orgContext()->run($org->id, fn () => Person::query()->find($socio->id)?->level_id))->toBe($livello->id);

    $this->get("/gestionale/soci/{$socio->id}")->assertInertia(fn (Assert $page) => $page->where('levels.0.name', 'Junior (non attivo)'));
    $this->get("/gestionale/soci/{$altro->id}")->assertInertia(fn (Assert $page) => $page->has('levels', 0));

    // A un altro socio il livello non attivo non si assegna.
    $this->orgContext()->run($org->id, fn () => expect(fn () => app(PeopleService::class)->update($altro->id, ['level_id' => $livello->id]))->toThrow(PeopleError::class, 'Livello di socio non valido'));
});
