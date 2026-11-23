<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Risorse\Services\ReservationService;
use App\Modules\Risorse\Services\ResourceService;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\CertificateService;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleService;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => [], 'resources' => ['hourly' => ['tier' => 2, 'web' => false], 'seasonal' => ['tier' => 5, 'web' => false]]], null);
        }
    });
    $this->org = $this->createOrg('Polisportiva Alfa');
});

it('mostra nella Home cosa c\'è da fare, cosa succede oggi e i saldi', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        $res = app(ResourceService::class);
        $court = $res->save(null, ['name' => 'Campo 1', 'typology' => 'hourly', 'category' => 'court', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'fee_cents' => 1000]);
        $berth = $res->save(null, ['name' => 'Posto barca 3', 'typology' => 'seasonal', 'category' => 'berth', 'fee_cents' => 50000]);
        $r = app(ReservationService::class);
        $today = substr(ReservationService::now(), 0, 10);
        // La segreteria può prenotare anche una fascia già passata: il test non dipende dall'ora.
        $r->book($court->id, ['holder_name' => 'Torneo Beta', 'date' => $today, 'start' => '08:00'], null);
        $r->book($berth->id, ['person_id' => $anna->id, 'date' => date('Y-m-d', (int) strtotime($today.' -30 days'))], null);
    });
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);

    // Riquadri e contatori arrivano con la seconda richiesta (gruppo «cruscotto»), la pagina subito.
    $this->actingAs($op)->get('/gestionale')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')
        ->has('greeting')
        ->missing('todo')->missing('today')->missing('stats')->missing('peopleCount')
        ->loadDeferredProps('cruscotto', fn (Assert $reload) => $reload
            ->where('todo.0.title', '1 socio senza tessera valida')
            ->where('todo.0.href', '/gestionale/soci')
            ->has('today', 1)
            ->where('today.0.title', 'Campo 1')
            ->where('today.0.time', '08:00')
            ->where('today.0.detail', 'Torneo Beta')
            ->where('stats.0.title', 'Cassa contanti')
            ->where('peopleCount', 1)));
});

it('ricalcola i contatori della Home a ogni apertura', function (): void {
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->actingAs($op)->get('/gestionale')->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('cruscotto', fn (Assert $reload) => $reload->where('peopleCount', 0)));

    $this->orgContext()->run($this->org->id, fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']));

    $this->actingAs($op)->get('/gestionale')->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('cruscotto', fn (Assert $reload) => $reload->where('peopleCount', 1)
            ->where('todo', fn ($todo) => collect($todo)->pluck('title')->contains('1 socio senza tessera valida'))));
});

it('conta i soci senza tessera valida: sospesi sì, usciti, ospiti e fondatori no', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $cash = Account::query()->sole()->id;
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        $bruno = Person::create(['first_name' => 'Bruno', 'last_name' => 'Verdi']);
        $carla = Person::create(['type' => 'volunteer', 'first_name' => 'Carla', 'last_name' => 'Neri']);
        Person::create(['type' => 'founder', 'first_name' => 'Franco', 'last_name' => 'Fondatore']);
        Person::create(['type' => 'guest', 'first_name' => 'Ospite', 'last_name' => 'Esterno', 'host_person_id' => $anna->id]);
        $dario = Person::create(['first_name' => 'Dario', 'last_name' => 'Bianchi', 'joined_on' => now()->subYears(2)->toDateString()]);
        app(MembershipService::class)->renewWithPayment($bruno->id, $cash, 3000);
        app(MembershipService::class)->renewWithPayment($carla->id, $cash, 3000);
        $carla->forceFill(['suspended_at' => now()])->save();
        app(PeopleService::class)->leave($dario->id, now()->subDay()->toDateString(), 'Recesso');
    });
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);

    $this->actingAs($op)->get('/gestionale')->assertInertia(fn (Assert $page) => $page
        ->where('todo', fn ($todo) => collect($todo)->pluck('title')->contains('2 soci senza tessera valida')));
});

it('mostra a ciascuno solo quello che può vedere', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        app(CertificateService::class)->add($anna->id, ['kind' => 'agonistic', 'issued_on' => now()->subYears(2)->toDateString(), 'expires_on' => now()->subYear()->toDateString(), 'doctor' => 'Dr. Bianchi']);
    });
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $accountant = $this->createUser('com@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->addOperator($this->org, $accountant, OrgRole::Accountant);

    // I certificati sono dati sanitari: li vede la segreteria, non il commercialista, che vede però i saldi.
    $this->actingAs($op)->get('/gestionale')->assertInertia(fn (Assert $page) => $page->loadDeferredProps('cruscotto', fn (Assert $reload) => $reload
        ->where('todo', fn ($todo) => collect($todo)->pluck('title')->contains('1 certificato medico scaduto'))));
    $this->actingAs($accountant)->get('/gestionale')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')
        ->loadDeferredProps('cruscotto', fn (Assert $reload) => $reload
            ->where('todo', fn ($todo) => ! collect($todo)->pluck('title')->contains('1 certificato medico scaduto'))
            ->where('today', [])->where('stats.0.title', 'Cassa contanti')));
});
