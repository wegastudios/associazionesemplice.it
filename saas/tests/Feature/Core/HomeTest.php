<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Risorse\Services\ReservationService;
use App\Modules\Risorse\Services\ResourceService;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\CertificateService;
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

    $this->actingAs($op)->get('/gestionale')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')
        ->where('todo.0.title', '1 socio senza tessera valida')
        ->where('todo.0.href', '/gestionale/soci')
        ->has('greeting')
        ->has('today', 1)
        ->where('today.0.title', 'Campo 1')
        ->where('today.0.time', '08:00')
        ->where('today.0.detail', 'Torneo Beta')
        ->where('stats.0.title', 'Cassa contanti'));
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
    $this->actingAs($op)->get('/gestionale')->assertInertia(fn (Assert $page) => $page->where('todo', fn ($todo) => collect($todo)->pluck('title')->contains('1 certificato medico scaduto')));
    $this->actingAs($accountant)->get('/gestionale')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')
        ->where('todo', fn ($todo) => ! collect($todo)->pluck('title')->contains('1 certificato medico scaduto'))
        ->where('today', [])->where('stats.0.title', 'Cassa contanti'));
});
