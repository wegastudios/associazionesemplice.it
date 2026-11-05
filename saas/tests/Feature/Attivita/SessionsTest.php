<?php

declare(strict_types=1);

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Rules\SessionRules;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\AttendanceService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->day = now()->addDays(2)->toDateString();
});

function recurring(array $over = []): Activity
{
    return app(ActivityService::class)->save(null, $over + [
        'name' => 'Escursioni', 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee_cents' => 1500, 'guest_fee_cents' => 2000,
        'price_lists' => SessionRules::parsePriceLists("Singolo 15\nPacchetto 3 uscite 40 x3"),
    ]);
}

it('crea le date, prenota entro i posti e fa entrare chi è in lista d\'attesa', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $a = recurring();
        expect($s->addDates($a->id, $this->day, null, false, '09:00', '13:00', 'Rifugio', 2))->toBe(1)
            ->and($s->addDates($a->id, $this->day, null, false, '09:00', null, null, 2))->toBe(0)
            ->and(fn () => $s->addDates($a->id, ((int) now()->format('Y') + 2).'-01-01', null, false, null, null, null, null))->toThrow(ActivityError::class, 'fuori dall\'anno')
            ->and(fn () => app(ActivityService::class)->enroll($a->id, Person::create(['first_name' => 'X', 'last_name' => 'Y'])->id))->toThrow(ActivityError::class, 'singole date');
        $session = $s->sessions($a)[0]['id'];

        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        $gino = Person::create(['type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Bianchi', 'host_person_id' => $anna->id]);
        $eva = Person::create(['first_name' => 'Eva', 'last_name' => 'Verdi']);
        expect($s->book($session, $anna->id)->fee_due_cents)->toBe(1500)
            ->and($s->book($session, $gino->id)->fee_due_cents)->toBe(2000)
            ->and(fn () => $s->book($session, $anna->id))->toThrow(ActivityError::class, 'già prenotato')
            ->and(fn () => $s->book($session, $eva->id))->toThrow(ActivityError::class, 'Posti esauriti');

        $s->waitlistJoin($session, $eva->id);
        expect(fn () => $s->waitlistJoin($session, $eva->id))->toThrow(ActivityError::class, 'già in lista')
            ->and($s->sessions($a)[0])->toMatchArray(['taken' => 2, 'free' => 0, 'waiting' => 1]);

        // Gino annulla: entra Eva.
        $gb = (string) DB::table('app.session_bookings')->where('person_id', $gino->id)->value('id');
        expect($s->cancelBooking($gb))->toBe(['Verdi Eva'])
            ->and($s->sessions($a)[0])->toMatchArray(['taken' => 2, 'waiting' => 0])
            ->and(DB::table('app.session_waitlist')->value('status'))->toBe('promoted');

        // Con più posti entra il prossimo; non si scende sotto i prenotati.
        $s->waitlistJoin($session, $gino->id);
        expect($s->updateSession($session, '09:00', '13:00', 'Rifugio', 3))->toBe(['Bianchi Gino'])
            ->and(fn () => $s->updateSession($session, '09:00', null, null, 1))->toThrow(ActivityError::class, 'non possono essere meno');

        // Una serie settimanale e l'annullamento di questa e delle successive.
        expect($s->addDates($a->id, $this->day, date('Y-m-d', (int) strtotime($this->day.' +21 days')), true, '15:00', null, null, null))->toBe(4);
        $later = collect($s->sessions($a))->where('start', '15:00')->values();
        expect($s->cancelSession($later[1]['id'], true))->toBe(3)
            ->and(collect($s->sessions($a))->where('cancelled', false)->count())->toBe(2)
            ->and(fn () => $s->book($later[2]['id'], $anna->id))->toThrow(ActivityError::class, 'annullata')
            ->and(app(ActivityService::class)->forYear(now()->format('Y'))[0]['participants'])->toBe(3);
    });
});

it('vende pacchetti di ingressi e incassa con ricevuta', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $a = recurring();
        $s->addDates($a->id, $this->day, date('Y-m-d', (int) strtotime($this->day.' +21 days')), true, null, null, null, null);
        $dates = array_column($s->sessions($a), 'id');
        $p = Person::create(['first_name' => 'Lucia', 'last_name' => 'Neri']);

        $pack = $s->book($dates[0], $p->id, 'pacchetto_3_uscite');
        expect($pack->fee_due_cents)->toBe(4000)->and($pack->package_entries)->toBe(3)
            ->and($s->openPackages($p->id, $a->id))->toBe([['id' => $pack->id, 'label' => 'Pacchetto 3 uscite', 'left' => 2]]);
        $second = $s->book($dates[1], $p->id, null, true);
        expect($second->fee_due_cents)->toBe(0)->and($second->package_of)->toBe($pack->id);
        $s->book($dates[2], $p->id, null, true);
        expect($s->openPackages($p->id, $a->id))->toBe([])
            ->and(fn () => $s->book($dates[3], $p->id, null, true))->toThrow(ActivityError::class, 'non ha un pacchetto')
            ->and(fn () => $s->book($dates[3], $p->id, 'boh'))->toThrow(ActivityError::class, 'Listino')
            ->and(fn () => $s->cancelBooking($pack->id))->toThrow(ActivityError::class, 'annulla prima')
            ->and($s->bookings($dates[0])[0])->toMatchArray(['list' => 'Pacchetto 3 uscite', 'package_left' => 0, 'state' => 'unpaid']);

        $cash = Account::query()->sole()->id;
        $receipt = $s->collect($pack->id, $cash, now()->toDateString(), null, null);
        expect($receipt)->not->toBeNull()
            ->and($s->paid([$pack->id])[$pack->id])->toBe(4000)
            ->and(fn () => $s->collect($pack->id, $cash, now()->toDateString(), null, null))->toThrow(ActivityError::class, 'niente da incassare')
            ->and(app(Ledger::class)->activityTotals([$a->id])[$a->id]['income'])->toBe(4000);
        app(Ledger::class)->void((string) DB::table('app.booking_payments')->value('transaction_id'), 'errore');
        expect($s->paid([$pack->id]))->toBe([]);
    });
});

it('registra le presenze di corsi e date e le riassume', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $acts = app(ActivityService::class);
        $att = app(AttendanceService::class);
        $year = now()->format('Y');
        $monday = now()->startOfWeek()->subWeek()->toDateString();
        $yoga = $acts->save(null, ['name' => 'Yoga', 'kind' => 'course', 'social_year' => $year, 'billing' => 'monthly', 'fee_cents' => 3000, 'weekdays' => [1, 3]]);
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        $bruno = Person::create(['first_name' => 'Bruno', 'last_name' => 'Blu']);
        $acts->enroll($yoga->id, $anna->id, substr($monday, 0, 7));
        $acts->enroll($yoga->id, $bruno->id, substr($monday, 0, 7));

        expect($att->lessonDates($yoga, $monday, date('Y-m-d', (int) strtotime($monday.' +6 days'))))->toBe([$monday, date('Y-m-d', (int) strtotime($monday.' +2 days'))])
            ->and(array_column($att->roster($yoga, $monday), 'name'))->toBe(['Blu Bruno', 'Rossi Anna'])
            ->and($att->save($yoga->id, $monday, [$anna->id], null))->toBe(2)
            ->and($att->marks($yoga->id, $monday))->toEqual([$bruno->id => false, $anna->id => true])
            ->and(fn () => $att->save($yoga->id, date('Y-m-d', (int) strtotime($monday.' +1 day')), [], null))->toThrow(ActivityError::class, 'non ha lezione')
            ->and(fn () => $att->save($yoga->id, now()->addWeek()->startOfWeek()->toDateString(), [], null))->toThrow(ActivityError::class, 'anticipo');
        $att->save($yoga->id, date('Y-m-d', (int) strtotime($monday.' +2 days')), [$anna->id, $bruno->id], null);
        $sum = $att->summary($yoga->id, $monday, now()->toDateString());
        expect($sum['dates'])->toHaveCount(2)
            ->and($sum['people'][1])->toMatchArray(['name' => 'Rossi Anna', 'present' => 2, 'total' => 2])
            ->and($sum['people'][0])->toMatchArray(['name' => 'Blu Bruno', 'present' => 1]);

        // Le date di un evento con prenotazioni: attesi i prenotati.
        $s = app(SessionService::class);
        $a = recurring();
        $s->addDates($a->id, now()->toDateString(), null, false, '10:00', null, null, null);
        $s->book($s->sessions($a)[0]['id'], $bruno->id);
        expect(array_column($att->roster($a, now()->toDateString()), 'name'))->toBe(['Blu Bruno'])
            ->and($att->save($a->id, now()->toDateString(), [$bruno->id], null))->toBe(1);

        $cal = $att->calendar($monday, now()->addDays(3)->toDateString());
        expect(collect($cal)->pluck('title')->unique()->values()->all())->toContain('Yoga', 'Escursioni')
            ->and(collect($cal)->firstWhere('title', 'Escursioni'))->toMatchArray(['start' => '10:00', 'taken' => 1]);
    });
});

it('mostra date, prenotazioni, presenze e calendario nelle pagine', function (): void {
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->actingAs($op);
    $this->post('/gestionale/attivita', ['name' => 'Gite', 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee' => '12,00',
        'price_lists' => "Singolo 12\nTessera 5 gite 50 x5"])->assertRedirect();
    [$id, $person] = $this->orgContext()->run($this->org->id, fn (): array => [(string) DB::table('app.activities')->value('id'),
        Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi'])->id]);

    $this->post("/gestionale/attivita/{$id}/date", ['from' => $this->day, 'start' => '09:00', 'capacity' => 10])->assertSessionHas('status', '1 data aggiunta.');
    $this->get("/gestionale/attivita/{$id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Attivita/Scheda')
        ->where('activity.price_lists', "Singolo 12,00\nTessera 5 gite 50,00 x5")->has('activity.sessions', 1)->where('activity.sessions.0.free', 10));
    $session = (string) $this->orgContext()->run($this->org->id, fn () => DB::table('app.activity_sessions')->value('id'));
    $this->post("/gestionale/attivita/date/{$session}/prenota", ['person_id' => $person, 'price_list' => 'tessera_5_gite'])->assertSessionHas('status', 'Prenotazione registrata.');
    $this->get("/gestionale/attivita/date/{$session}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Attivita/Data')
        ->where('bookings.0.name', 'Rossi Anna')->where('bookings.0.fee', '50,00 €')->where('bookings.0.package_left', 4));
    $this->get('/gestionale/calendario?dal='.$this->day)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Attivita/Calendario')
        ->where('days.0.items.0.title', 'Gite'));
    $this->get("/gestionale/attivita/{$id}/presenze?giorno={$this->day}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Attivita/Presenze')
        ->where('future', true)->has('roster', 1));
    $this->post("/gestionale/attivita/{$id}/presenze", ['date' => $this->day, 'present' => [$person]])->assertSessionHasErrors('ledger');
    $b = (string) $this->orgContext()->run($this->org->id, fn () => DB::table('app.session_bookings')->value('id'));
    $this->post("/gestionale/attivita/prenotazioni/{$b}/annulla")->assertSessionHas('status', 'Prenotazione annullata.');
});
