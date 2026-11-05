<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Services\ReservationService;
use App\Modules\Risorse\Services\ResourceService;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.

/** @param  array<string, mixed>  $resources  limiti del pacchetto Risorse; null = pacchetto assente */
function resourcePackage(?array $resources): void
{
    app()->instance(Entitlements::class, new class($resources) implements Entitlements
    {
        /** @param  array<string, mixed>|null  $resources */
        public function __construct(private ?array $resources) {}

        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, $this->resources === null ? [] : ['resources' => $this->resources], null);
        }
    });
}

/** @return array<string, mixed> */
function tennis(array $over = []): array
{
    return $over + ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60,
        'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'nonmember_fee_cents' => 1500, 'guests_max' => 3, 'guest_fee_cents' => 200,
        'rates' => 'feriali 18:00-22:00 12,00'];
}

beforeEach(function (): void {
    $this->org = $this->createOrg('Circolo Alfa');
    resourcePackage(['hourly' => ['tier' => 2, 'web' => false], 'daily' => ['tier' => 10, 'web' => false], 'seasonal' => ['tier' => 50, 'web' => false]]);
    $this->day = now('Europe/Rome')->addDays(3)->format('Y-m-d');
});

it('crea le risorse entro i limiti del pacchetto per tipologia', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(ResourceService::class);
        $a = $s->save(null, tennis());
        $s->save(null, tennis(['name' => 'Campo 2']));
        expect(fn () => $s->save(null, tennis(['name' => 'Campo 3'])))->toThrow(ResourceError::class, 'massimo del tuo pacchetto')
            ->and($s->save(null, ['name' => 'Posto barca 1', 'typology' => 'seasonal', 'category' => 'berth', 'fee_cents' => 90000])->typology->value)->toBe('seasonal')
            ->and($a->rates)->toBe([['days' => 'weekdays', 'from' => '18:00', 'to' => '22:00', 'fee_cents' => 1200]])
            ->and($s->limits()['hourly'])->toMatchArray(['used' => 2, 'tier' => 2]);

        // Archiviata non conta; riattivarla oltre il limite no.
        $s->archive($a->id, true);
        $s->save(null, tennis(['name' => 'Campo 3']));
        expect(fn () => $s->archive($a->id, false))->toThrow(ResourceError::class, 'massimo')
            ->and(fn () => $s->save(null, tennis(['open_time' => '22:00', 'close_time' => '08:00'])))->toThrow(ResourceError::class);

        resourcePackage(['seasonal' => ['tier' => 50, 'web' => false]]);
        expect(fn () => app(ResourceService::class)->save(null, ['name' => 'Sala', 'typology' => 'daily', 'category' => 'room']))->toThrow(ResourceError::class, 'non comprende risorse a giornata');
    });
});

it('prenota a fasce con tariffe soci e non soci, ospiti e serie, senza sovrapposizioni', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $court = app(ResourceService::class)->save(null, tennis());
        $s = app(ReservationService::class);
        $socio = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi']);
        app(MembershipService::class)->renewWithPayment($socio->id, Account::query()->sole()->id, 3000);
        $esterno = Person::create(['type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Bianchi', 'host_person_id' => $socio->id]);

        $ids = $s->book($court->id, ['person_id' => $socio->id, 'date' => $this->day, 'start' => '17:00', 'units' => 2, 'guests' => ['Anna Verdi']], null);
        $r = $s->find($ids[0]);
        $weekday = (int) date('N', (int) strtotime($this->day)) <= 5;
        expect($r->member)->toBeTrue()
            ->and($r->starts_at)->toBe($this->day.' 17:00:00')
            ->and($r->fee_due_cents)->toBe(1000 + ($weekday ? 1200 : 1000) + 200)
            ->and($r->guests)->toBe(['Anna Verdi']);

        expect(fn () => $s->book($court->id, ['person_id' => $esterno->id, 'date' => $this->day, 'start' => '18:00', 'units' => 1], null))
            ->toThrow(ResourceError::class, 'già occupata');
        $other = $s->book($court->id, ['person_id' => $esterno->id, 'date' => $this->day, 'start' => '19:00', 'units' => 1], null);
        expect($s->find($other[0])->fee_due_cents)->toBe(1500)->and($s->find($other[0])->member)->toBeFalse();
        $ext = $s->book($court->id, ['holder_name' => 'Torneo Beta', 'date' => $this->day, 'start' => '08:00', 'units' => 1], null);
        expect($s->find($ext[0])->holder_name)->toBe('Torneo Beta');

        // Ogni settimana per 4 settimane: tutte o nessuna.
        $series = $s->book($court->id, ['person_id' => $socio->id, 'date' => $this->day, 'start' => '10:00', 'units' => 1, 'weeks' => 4], null);
        expect($series)->toHaveCount(4)
            ->and(fn () => $s->book($court->id, ['person_id' => $socio->id, 'date' => date('Y-m-d', (int) strtotime($this->day.' -7 days')), 'start' => '10:00', 'weeks' => 3], null))
            ->toThrow(ResourceError::class, 'già occupata');
        expect(DB::table('app.reservations')->where('status', 'booked')->count())->toBe(7)
            ->and($s->cancel($series[1], true))->toBe(3)
            ->and(DB::table('app.reservations')->where('status', 'booked')->count())->toBe(4);

        // Il blocco ferma le prenotazioni, e non si sovrappone a quelle esistenti.
        $s->block($court->id, $this->day.' 12:00', $this->day.' 14:00', 'Manutenzione', null);
        expect(fn () => $s->book($court->id, ['person_id' => $socio->id, 'date' => $this->day, 'start' => '13:00'], null))->toThrow(ResourceError::class, 'bloccata')
            ->and(fn () => $s->block($court->id, $this->day.' 16:00', $this->day.' 18:00', null, null))->toThrow(ResourceError::class)
            ->and(fn () => $s->book($court->id, ['person_id' => $socio->id, 'date' => $this->day, 'start' => '09:00', 'guests' => ['A', 'B', 'C', 'D']], null))->toThrow(ResourceError::class, 'al massimo 3');

        expect(DB::table('audit.log')->where('action', 'reservation.series')->exists())->toBeTrue();

        // Anche saltando il controllo del servizio il database rifiuta la sovrapposizione.
        expect(fn () => DB::table('app.reservations')->insert(['org_id' => $this->org->id, 'id' => (string) Str::uuid7(), 'resource_id' => $court->id, 'kind' => 'booking',
            'holder_name' => 'X', 'starts_at' => $this->day.' 17:30:00', 'ends_at' => $this->day.' 18:30:00']))->toThrow(Illuminate\Database\QueryException::class, 'reservations_org_id_resource_id_tsrange_excl');
    });
});

it('incassa in prima nota con ricevuta e tiene conto degli storni', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $hall = app(ResourceService::class)->save(null, ['name' => 'Sala feste', 'typology' => 'daily', 'category' => 'room', 'fee_cents' => 20000, 'services' => "Aperitivo 5\nAllestimento 50"]);
        $s = app(ReservationService::class);
        $p = Person::create(['first_name' => 'Lucia', 'last_name' => 'Neri']);
        $id = $s->book($hall->id, ['person_id' => $p->id, 'date' => $this->day, 'units' => 2, 'services' => ['Allestimento']], null)[0];
        expect($s->find($id)->fee_due_cents)->toBe(45000)
            ->and($s->find($id)->ends_at)->toBe(date('Y-m-d', (int) strtotime($this->day.' +2 days')).' 00:00:00')
            ->and(fn () => $s->book($hall->id, ['person_id' => $p->id, 'date' => $this->day, 'services' => ['Torta']], null))->toThrow(ResourceError::class, 'non previsto');

        $cash = Account::query()->sole()->id;
        $receipt = $s->collect($id, $cash, now()->toDateString(), 10000, PaymentMethod::Cash);
        expect($receipt)->not->toBeNull()->and($s->paid([$id])[$id])->toBe(10000);
        $s->collect($id, $cash, now()->toDateString(), null, null);
        expect($s->paid([$id])[$id])->toBe(45000)
            ->and(fn () => $s->collect($id, $cash, now()->toDateString(), null, null))->toThrow(ResourceError::class, 'niente da incassare')
            ->and(DB::table('app.transactions')->where('description', 'like', 'Sala feste%')->count())->toBe(2);

        $tx = (string) DB::table('app.reservation_payments')->where('reservation_id', $id)->orderBy('created_at')->value('transaction_id');
        app(App\Modules\Contabilita\Contracts\Ledger::class)->void($tx, 'Errore');
        expect($s->paid([$id])[$id])->toBe(35000);
    });
});

it('assegna le risorse stagionali e gestisce la lista d\'attesa', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $berth = app(ResourceService::class)->save(null, ['name' => 'Posto barca 7', 'typology' => 'seasonal', 'category' => 'berth', 'fee_cents' => 90000]);
        $s = app(ReservationService::class);
        $a = Person::create(['first_name' => 'Anna', 'last_name' => 'Blu']);
        $b = Person::create(['first_name' => 'Bruno', 'last_name' => 'Gialli']);
        $year = now()->format('Y');
        $id = $s->book($berth->id, ['person_id' => $a->id, 'date' => $year.'-04-01', 'to' => $year.'-09-30'], null)[0];
        expect($s->find($id)->fee_due_cents)->toBe(90000)
            ->and(fn () => $s->book($berth->id, ['holder_name' => 'Esterno', 'date' => $year.'-05-01'], null))->toThrow(ResourceError::class, 'anagrafica')
            ->and(fn () => $s->book($berth->id, ['person_id' => $b->id, 'date' => $year.'-05-01', 'to' => $year.'-06-01'], null))->toThrow(ResourceError::class, 'già occupata');

        $s->waitlistJoin($berth->id, $b->id, 'Barca a vela 8 m');
        expect(fn () => $s->waitlistJoin($berth->id, $b->id, null))->toThrow(ResourceError::class, 'già in lista')
            ->and($s->waitlist($berth->id)[0])->toMatchArray(['person_id' => $b->id, 'name' => 'Gialli Bruno']);
        $s->book($berth->id, ['person_id' => $b->id, 'date' => ($year + 1).'-04-01'], null);
        expect($s->waitlist($berth->id))->toBe([])
            ->and(fn () => $s->waitlistJoin($berth->id, $b->id, null))->toThrow(ResourceError::class, 'ha già');
    });
});

it('apre le pagine secondo pacchetto e permessi', function (): void {
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $acc = $this->createUser('acc@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->addOperator($this->org, $acc, OrgRole::Accountant);

    $this->actingAs($op)->get('/gestionale/risorse')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Risorse/Elenco')->where('limits.0.tier', 2));
    $this->post('/gestionale/risorse', ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00',
        'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee' => '10,00', 'nonmember_fee' => '15'])->assertRedirect();
    $id = (string) $this->orgContext()->run($this->org->id, fn () => DB::table('app.resources')->value('id'));
    $this->get('/gestionale/risorse/'.$id.'?giorno='.$this->day)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Risorse/Scheda')->has('grid', 14)->where('resource.fee', '10,00'));
    $this->post('/gestionale/risorse/'.$id.'/prenotazioni', ['holder_name' => 'Torneo Beta', 'date' => $this->day, 'start' => '09:00', 'units' => 1])
        ->assertSessionHas('status', 'Prenotazione registrata.');
    $this->post('/gestionale/risorse/'.$id.'/prenotazioni', ['holder_name' => 'Altro', 'date' => $this->day, 'start' => '09:00', 'units' => 1])->assertSessionHasErrors('ledger');
    $this->postJson('/gestionale/risorse/'.$id.'/preventivo', ['holder_name' => 'X', 'date' => $this->day, 'start' => '10:00', 'units' => 2])->assertJson(['fee' => '30,00 €']);
    $this->get('/gestionale/risorse/calendario?giorno='.$this->day)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Risorse/Calendario')->where('hourly.0.slots.1.label', 'Torneo Beta'));
    $res = (string) $this->orgContext()->run($this->org->id, fn () => DB::table('app.reservations')->value('id'));
    $cash = (string) $this->orgContext()->run($this->org->id, fn () => Account::query()->sole()->id);
    $this->post('/gestionale/prenotazioni/'.$res.'/incassa', ['account_id' => $cash, 'date' => now()->toDateString()])->assertSessionHas('status', 'Incasso registrato in prima nota.');
    $this->get('/gestionale/risorse/'.$id.'?giorno='.$this->day)->assertInertia(fn (Assert $page) => $page->where('resource.upcoming.0.due', 0)->where('resource.upcoming.0.paid', '15,00 €'));
    $this->post('/gestionale/prenotazioni/'.$res.'/annulla')->assertSessionHas('status', 'Prenotazione annullata.');

    // La commercialista vede, non prenota.
    $this->actingAs($acc)->get('/gestionale/risorse')->assertOk();
    $this->post('/gestionale/risorse/'.$id.'/prenotazioni', ['holder_name' => 'X', 'date' => $this->day, 'start' => '11:00'])->assertForbidden();

    // Senza pacchetto niente risorse.
    resourcePackage(null);
    $this->actingAs($op)->get('/gestionale/risorse')->assertForbidden();
});
