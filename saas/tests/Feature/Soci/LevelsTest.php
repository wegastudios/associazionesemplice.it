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
