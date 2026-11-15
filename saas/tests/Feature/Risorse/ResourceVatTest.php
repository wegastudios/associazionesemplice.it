<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Transaction;
use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Risorse\Contracts\MemberResources;
use App\Modules\Risorse\Contracts\ResourceBookings;
use App\Modules\Risorse\Models\Resource;
use App\Modules\Risorse\Services\ReservationService;
use App\Modules\Risorse\Services\ResourceService;
use App\Modules\Risorse\Services\ResourceVat;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// IVA delle risorse e delle prenotazioni: solo con la partita IVA; le risorse esistenti restano senza IVA.
beforeEach(function (): void {
    $this->org = $this->createOrg('Circolo Alfa');
    $this->owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['resources' => ['hourly' => ['tier' => 5, 'web' => true], 'daily' => ['tier' => 5, 'web' => true], 'seasonal' => ['tier' => 5, 'web' => true]]], null);
        }
    });
    $this->orgContext()->enter($this->org->id);
    $this->cash = Account::query()->where('type', 'cash')->sole()->id;
    $this->day = now('Europe/Rome')->addDays(3)->format('Y-m-d');
});

function vatCourt(?int $rate, string $name = 'Campo 1'): Resource
{
    return app(ResourceService::class)->save(null, [
        'name' => $name, 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60,
        'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'vat_rate' => $rate,
    ]);
}

function vatBook(Resource $court, string $hour = '10:00'): string
{
    return app(ReservationService::class)->book($court->id, ['holder_name' => 'Torneo Beta', 'date' => test()->day, 'start' => $hour, 'units' => 1], null)[0];
}

function vatCollect(string $reservation): Transaction
{
    app(ReservationService::class)->collect($reservation, test()->cash, now()->toDateString(), null, PaymentMethod::Cash);

    return Transaction::query()->findOrFail(DB::table('app.reservation_payments')->where('reservation_id', $reservation)->value('transaction_id'));
}

it('una risorsa senza IVA incassa senza aliquota', function (): void {
    $court = vatCourt(null);
    $id = vatBook($court);
    $tx = vatCollect($id);

    expect(app(ReservationService::class)->find($id)->vat_rate)->toBeNull()
        ->and($tx->vat_rate)->toBeNull()->and($tx->vat_cents)->toBe(0)->and($tx->amount_cents)->toBe(1000);
});

it('con la partita IVA la prenotazione incassa con l\'aliquota della risorsa, prezzi finali', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    $court = vatCourt(22);
    $id = vatBook($court);
    $tx = vatCollect($id);

    expect(app(ReservationService::class)->find($id)->fee_due_cents)->toBe(1000)->and(app(ReservationService::class)->find($id)->vat_rate)->toBe(22)
        ->and($tx->vat_rate)->toBe(22)->and($tx->amount_cents)->toBe(1000)->and($tx->vat_cents)->toBe(180);
});

it('con i prezzi senza IVA chi prenota paga prezzo più IVA, in anteprima e nel dovuto', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::INCLUDES_VAT, false);
    $court = vatCourt(22);
    $in = ['holder_name' => 'Torneo Beta', 'date' => $this->day, 'start' => '10:00', 'units' => 1];
    $s = app(ReservationService::class);
    expect($s->quote($court->id, $in))->toBe(1220);
    $id = $s->book($court->id, $in, null)[0];
    $tx = vatCollect($id);

    expect($s->find($id)->fee_due_cents)->toBe(1220)->and($tx->amount_cents)->toBe(1220)->and($tx->vat_rate)->toBe(22);
});

it('«IVA esente» non aumenta il prezzo e registra aliquota 0', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::INCLUDES_VAT, false);
    $court = vatCourt(0);
    $id = vatBook($court);
    $tx = vatCollect($id);

    expect(app(ReservationService::class)->find($id)->fee_due_cents)->toBe(1000)->and($tx->vat_rate)->toBe(0)->and($tx->amount_cents)->toBe(1000);
});

it('una prenotazione già fatta si incassa con importo e aliquota di quando è stata creata, anche se l\'IVA della risorsa cambia', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::INCLUDES_VAT, false);
    app(OrgSettings::class)->set(OrgFiscal::RATES, '22, 10');
    // Fatta con il 22%: la risorsa passa al 10%, poi a «senza IVA».
    $court = vatCourt(22);
    $first = vatBook($court, '10:00');
    app(ResourceService::class)->save($court->id, ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'vat_rate' => 10]);
    $second = vatBook($court, '11:00');
    app(ResourceService::class)->save($court->id, ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'vat_rate' => null]);
    $third = vatBook($court, '12:00');

    $a = vatCollect($first);
    expect($a->vat_rate)->toBe(22)->and($a->amount_cents)->toBe(1220);
    $b = vatCollect($second);
    expect($b->vat_rate)->toBe(10)->and($b->amount_cents)->toBe(1100);
    $c = vatCollect($third);
    expect($c->vat_rate)->toBeNull()->and($c->amount_cents)->toBe(1000);
});

it('prenotazione senza IVA poi risorsa con IVA: l\'incasso resta senza aliquota e con l\'importo di creazione', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::INCLUDES_VAT, false);
    $court = vatCourt(null);
    $id = vatBook($court);
    app(ResourceService::class)->save($court->id, ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'vat_rate' => 22]);
    $tx = vatCollect($id);

    expect($tx->vat_rate)->toBeNull()->and($tx->amount_cents)->toBe(1000);
});

it('se la partita IVA non c\'è più l\'incasso della prenotazione si registra senza aliquota', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    $court = vatCourt(22);
    $id = vatBook($court);
    app(OrgSettings::class)->set('ente.vat', '');
    $tx = vatCollect($id);

    expect($tx->vat_rate)->toBeNull()->and($tx->amount_cents)->toBe(1000);
});

it('se l\'aliquota non è più in elenco la risorsa si comporta come senza IVA', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    $court = vatCourt(22);
    app(OrgSettings::class)->set(OrgFiscal::RATES, '10');
    expect(app(ResourceVat::class)->rate($court))->toBeNull();
});

it('l\'area soci vede gli importi con l\'IVA aggiunta', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::INCLUDES_VAT, false);
    vatCourt(22);
    $row = app(MemberResources::class)->available()[0];
    expect($row['fee'])->toBe(1220);
    $slot = collect(app(MemberResources::class)->slots($row['id'], $this->day))->firstWhere('start', '10:00');
    expect($slot['price'])->toBe(1220);
});

it('la scheda salva «Con IVA» solo con la partita IVA e dalle aliquote scelte', function (): void {
    $this->orgContext()->leave();
    $base = ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2,
        'fee' => '10', 'with_vat' => true, 'vat_rate' => 22];
    $this->actingAs($this->owner)->post('/gestionale/risorse', $base)->assertSessionHasErrors(['with_vat' => 'Senza partita IVA l\'IVA non si applica.']);

    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set('ente.vat', '12345678903'));
    $this->actingAs($this->owner)->post('/gestionale/risorse', $base)->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/gestionale/risorse', ['vat_rate' => 7, 'name' => 'Campo 2'] + $base)
        ->assertSessionHasErrors(['vat_rate' => 'Scegli «IVA esente» o una delle aliquote indicate in Aspetto fiscale.']);
    expect($this->orgContext()->run($this->org->id, fn () => Resource::query()->sole()->vat_rate))->toBe(22);
});

it('non si toglie da Aspetto fiscale un\'aliquota usata da una risorsa', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    vatCourt(10);
    $this->orgContext()->leave();
    $this->actingAs($this->owner)->put('/gestionale/aspetto-fiscale', ['rates' => '22', 'includes_vat' => true, 'membership_rate' => 0])
        ->assertSessionHasErrors(['rates' => 'Alcune risorse usano l\'aliquota 10%: prima cambia l\'IVA di quelle risorse, poi toglila dall\'elenco.']);
});

it('il messaggio dice «attività o risorse» quando le usano entrambe', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    vatCourt(10);
    app(App\Modules\Attivita\Services\ActivityService::class)->save(null, ['name' => 'Yoga', 'kind' => 'course', 'social_year' => now()->format('Y'), 'billing' => 'monthly', 'fee_cents' => 1000, 'vat_rate' => 10]);
    $this->orgContext()->leave();
    $this->actingAs($this->owner)->put('/gestionale/aspetto-fiscale', ['rates' => '22', 'includes_vat' => true, 'membership_rate' => 0])
        ->assertSessionHasErrors(['rates' => 'Alcune attività o risorse usano l\'aliquota 10%: prima cambia l\'IVA di quelle attività o risorse, poi toglila dall\'elenco.']);
});

it('un\'aliquota di una prenotazione non ancora finita non si toglie da Aspetto fiscale, quando è finita o annullata sì', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    $court = vatCourt(10);
    $id = vatBook($court);
    // La risorsa passa al 22%: resta la prenotazione fatta al 10%.
    app(ResourceService::class)->save($court->id, ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'vat_rate' => 22]);
    expect(app(ResourceBookings::class)->vatRatesInBookings())->toBe([10])->and(app(ResourceBookings::class)->vatRatesInUse())->toBe([22]);
    $this->orgContext()->leave();
    $put = fn () => $this->actingAs($this->owner)->put('/gestionale/aspetto-fiscale', ['rates' => '22', 'includes_vat' => true, 'membership_rate' => 0]);
    $put()->assertSessionHasErrors(['rates' => 'Alcune prenotazioni non ancora finite usano l\'aliquota 10%: togli l\'aliquota dall\'elenco quando saranno finite o annullate.']);

    $this->orgContext()->run($this->org->id, fn () => app(ReservationService::class)->cancel($id));
    $put()->assertSessionHasNoErrors();
});

it('la scheda avvisa se l\'IVA della risorsa non si può più applicare e i Dati dell\'ente contano le risorse con IVA', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    $court = vatCourt(22);
    expect(app(ResourceBookings::class)->withVatCount())->toBe(1);
    app(OrgSettings::class)->set('ente.vat', '');
    $this->orgContext()->leave();
    $this->actingAs($this->owner)->get("/gestionale/risorse/{$court->id}")
        ->assertInertia(fn (Assert $page) => $page->where('resource.vat_warning', 'Senza partita IVA l\'IVA non si applica: gli incassi di questa risorsa si registrano senza IVA.'));

    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set('ente.vat', '12345678903'));
    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set(OrgFiscal::RATES, '10'));
    $this->actingAs($this->owner)->get("/gestionale/risorse/{$court->id}")
        ->assertInertia(fn (Assert $page) => $page->where('resource.vat_warning', 'L\'aliquota di questa risorsa non è più nell\'elenco di Aspetto fiscale: scegline un\'altra.'));
});

it('l\'elenco e la griglia delle fasce mostrano l\'importo con l\'IVA', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::INCLUDES_VAT, false);
    $court = vatCourt(22);
    $this->orgContext()->leave();
    $this->actingAs($this->owner)->get('/gestionale/risorse')->assertInertia(fn (Assert $page) => $page->where('rows.0.fee', fn ($v) => str_contains((string) $v, '12,20')));
    $this->actingAs($this->owner)->get("/gestionale/risorse/{$court->id}?giorno={$this->day}")
        ->assertInertia(fn (Assert $page) => $page->where('grid.2.price', fn ($v) => str_contains((string) $v, '12,20')));
});
