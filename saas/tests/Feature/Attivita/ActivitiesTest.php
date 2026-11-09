<?php

declare(strict_types=1);

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Expense;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
});

function course(array $over = []): Activity
{
    return app(ActivityService::class)->save(null, $over + [
        'name' => 'Yoga', 'kind' => 'course', 'social_year' => now()->format('Y'), 'billing' => 'monthly', 'fee_cents' => 3000, 'guest_fee_cents' => 4000,
    ]);
}

it('iscrive a un corso, incassa i mesi con ricevuta e ne calcola la situazione', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(ActivityService::class);
        $cash = Account::query()->sole()->id;
        $month = now()->format('Y-m');
        $first = now()->format('Y').'-01';
        $yoga = course();
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        $gino = Person::create(['type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Bianchi', 'host_person_id' => $anna->id, 'phone' => '333 1234567']);

        $s->enroll($yoga->id, $anna->id, $first);
        $s->enroll($yoga->id, $gino->id, $month);
        expect(fn () => $s->enroll($yoga->id, $anna->id))->toThrow(ActivityError::class, 'già iscritto');

        $receipt = $s->collect($yoga->id, $anna->id, $cash, now()->toDateString(), [$first]);
        expect($receipt)->toBe('1/'.now()->format('Y'));

        $status = collect($s->status($yoga))->keyBy('person_id');
        $due = ((int) now()->format('n')) * 3000;
        expect($status[$anna->id]['summary']['total_due'])->toBe($due)
            ->and($status[$anna->id]['summary']['total_paid'])->toBe(3000)
            ->and($status[$gino->id]['fee'])->toBe(4000)
            ->and($status[$gino->id]['summary']['total_due'])->toBe(4000);

        $s->collect($yoga->id, $gino->id, $cash, now()->toDateString(), [$month]);
        expect(collect($s->status($yoga))->keyBy('person_id')[$gino->id]['summary']['regular'])->toBeTrue()
            ->and(app(Ledger::class)->activityTotals([$yoga->id])[$yoga->id])->toBe(['income' => 7000, 'expense' => 0])
            ->and(DB::table('app.transactions')->where('activity_id', $yoga->id)->value('competence_month'))->not->toBeNull();

        // Chi non è iscritto non paga, i mesi fuori dall'anno non si incassano.
        $other = Person::create(['first_name' => 'Eva', 'last_name' => 'Verdi']);
        expect(fn () => $s->collect($yoga->id, $other->id, $cash, now()->toDateString(), [$month]))->toThrow(ActivityError::class, 'non è iscritta')
            ->and(fn () => $s->collect($yoga->id, $anna->id, $cash, now()->toDateString(), ['1999-01']))->toThrow(ActivityError::class, 'anno sociale');
    });
});

it('storna un incasso e la situazione torna da versare', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(ActivityService::class);
        $event = $s->save(null, ['name' => 'Cena sociale', 'kind' => 'event', 'social_year' => now()->format('Y'), 'fee_cents' => 2500, 'starts_on' => now()->toDateString()]);
        $p = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        $s->enroll($event->id, $p->id);
        $s->collect($event->id, $p->id, Account::query()->sole()->id, now()->toDateString(), []);
        expect($s->status($event)[0]['summary']['regular'])->toBeTrue();

        app(Ledger::class)->void((string) DB::table('app.transactions')->where('activity_id', $event->id)->value('id'), 'errore');
        expect($s->status($event)[0]['summary']['total_paid'])->toBe(0)
            ->and(app(Ledger::class)->activityTotals([$event->id]))->toBe([]);

        // Chiusa senza versamenti, l'iscrizione all'evento non deve più nulla.
        $s->unenroll($event->id, $p->id);
        expect($s->status($event)[0]['summary']['total_due'])->toBe(0);
    });
});

it('lega le spese all\'attività e ne fa il bilancio', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(ActivityService::class);
        $ledger = app(Ledger::class);
        $cash = Account::query()->sole()->id;
        $yoga = course();
        $s->expense($yoga->id, $cash, now()->toDateString(), 12000, 'Affitto palestra');
        $general = $ledger->recordExpense(new Expense(now()->toDateString(), $cash, (string) $ledger->categoryFor(CategoryKind::GeneralCost), 5000, null, 'Materassini'));

        $ledger->assignActivity($general, $yoga->id);
        expect($ledger->activityTotals([$yoga->id])[$yoga->id])->toBe(['income' => 0, 'expense' => 17000])
            ->and(array_column($ledger->activityMovements($yoga->id), 'description'))->toContain('Affitto palestra', 'Materassini')
            ->and(DB::table('audit.log')->where('action', 'ledger.activity')->exists())->toBeTrue()
            ->and($s->forYear(now()->format('Y'))[0])->toMatchArray(['name' => 'Yoga', 'income' => 0, 'expense' => 17000]);

        $ledger->assignActivity($general, null);
        expect($ledger->activityTotals([$yoga->id])[$yoga->id]['expense'])->toBe(12000)
            ->and(fn () => $ledger->assignActivity($general, '00000000-0000-7000-8000-000000000000'))->toThrow(LedgerError::class, 'Attività non trovata');
    });
});

it('lascia cambiare solo l\'attività di un movimento, non il resto', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $ledger = app(Ledger::class);
        $cash = Account::query()->sole()->id;
        $yoga = course();
        $id = $ledger->recordExpense(new Expense(now()->toDateString(), $cash, (string) $ledger->categoryFor(CategoryKind::ActivityCost), 1000, null, 'x'));

        expect(fn () => DB::transaction(fn () => DB::table('app.transactions')->where('id', $id)->update(['activity_id' => $yoga->id, 'amount_cents' => 1])))->toThrow(QueryException::class, 'permission denied')
            ->and(fn () => DB::transaction(fn () => DB::table('app.transactions')->where('id', $id)->update(['description' => 'altro'])))->toThrow(QueryException::class);

        $ledger->void($id, 'errore');
        expect(fn () => $ledger->assignActivity($id, $yoga->id))->toThrow(LedgerError::class, 'stornato')
            ->and(fn () => DB::transaction(fn () => DB::table('app.transactions')->where('id', $id)->update(['activity_id' => $yoga->id])))->toThrow(QueryException::class);

        $other = Account::create(['name' => 'Banca', 'type' => 'bank']);
        $transfer = $ledger->recordTransfer(now()->toDateString(), $cash, $other->id, 100);
        $tid = (string) DB::table('app.transactions')->where('transfer_id', $transfer)->value('id');
        expect(fn () => $ledger->assignActivity($tid, $yoga->id))->toThrow(LedgerError::class, 'giroconto');
    });
});

it('controlla attività e iscrizioni', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(ActivityService::class);
        $member = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        $guardian = Person::create(['type' => 'guardian', 'first_name' => 'Marco', 'last_name' => 'Rossi']);
        $volunteer = Person::create(['type' => 'volunteer', 'first_name' => 'Vera', 'last_name' => 'Blu']);

        expect(fn () => course(['instructor_person_id' => $member->id]))->toThrow(ActivityError::class, 'volontario')
            ->and(course(['instructor_person_id' => $volunteer->id, 'weekdays' => [4, 2, 2]])->refresh()->weekdays)->toBe([2, 4])
            ->and(fn () => course(['social_year' => '2025/2027']))->toThrow(ActivityError::class, 'Anno sociale')
            ->and(fn () => $s->save(null, ['name' => 'Gita', 'kind' => 'event', 'fee_cents' => 0]))->toThrow(ActivityError::class, 'data dell\'evento');

        $yoga = course();
        expect(fn () => $s->enroll($yoga->id, $guardian->id))->toThrow(ActivityError::class, 'genitore')
            ->and(fn () => $s->enroll($yoga->id, $member->id, '1999-01'))->toThrow(ActivityError::class, 'anno sociale');
        $s->archive($yoga->id, true);
        expect(fn () => $s->enroll($yoga->id, $member->id))->toThrow(ActivityError::class, 'archiviata')
            ->and(app(ActivityService::class)->options())->toHaveCount(1);
    });
});

it('mostra le pagine e rispetta i permessi', function (): void {
    $op = $this->createUser('op@example.com', twoFactor: true);
    $acc = $this->createUser('acc@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->addOperator($this->org, $acc, OrgRole::Accountant);
    [$cash, $anna] = $this->orgContext()->run($this->org->id, fn () => [Account::query()->sole()->id, Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi'])->id]);
    $year = now()->format('Y');

    $this->actingAs($op)->post('/gestionale/attivita', ['name' => 'Yoga', 'kind' => 'course', 'social_year' => $year, 'billing' => 'monthly', 'fee' => '30,00', 'weekdays' => [2], 'resource' => 'none'])
        ->assertRedirect()->assertSessionHas('status', 'Attività creata.');
    $id = $this->orgContext()->run($this->org->id, fn () => Activity::query()->sole()->id);

    $this->post("/gestionale/attivita/{$id}/iscrizioni", ['person_id' => $anna])->assertSessionHas('status', 'Iscrizione registrata.');
    $this->post("/gestionale/attivita/{$id}/incassi", ['person_id' => $anna, 'account_id' => $cash, 'date' => now()->toDateString(), 'months' => [now()->format('Y-m')]])
        ->assertSessionHas('status', "Contributo incassato: ricevuta n. 1/{$year}.");
    $this->post("/gestionale/attivita/{$id}/spese", ['account_id' => $cash, 'date' => now()->toDateString(), 'amount' => '100,00', 'description' => 'Affitto'])
        ->assertSessionHas('status', 'Spesa registrata.');

    $this->get('/gestionale/attivita')->assertInertia(fn (Assert $page) => $page->component('Attivita/Elenco')
        ->where('rows.0.participants', 1)->where('rows.0.income_label', '30,00 €')->where('rows.0.balance_label', '-70,00 €')->where('rows.0.negative', true));
    $this->get("/gestionale/attivita/{$id}")->assertInertia(fn (Assert $page) => $page->component('Attivita/Scheda')
        ->where('activity.enrollments.0.name', 'Rossi Anna')->has('activity.movements', 2)->where('activity.monthly', true));
    $this->get("/gestionale/prima-nota?attivita={$id}")->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('rows.0.activity', 'Yoga'));

    $this->actingAs($acc)->get("/gestionale/attivita/{$id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    $this->post("/gestionale/attivita/{$id}/iscrizioni", ['person_id' => $anna])->assertForbidden();
    $this->post("/gestionale/attivita/{$id}/incassi", ['person_id' => $anna, 'account_id' => $cash, 'date' => now()->toDateString()])->assertForbidden();
    $this->post('/gestionale/attivita', ['name' => 'X', 'kind' => 'course', 'social_year' => $year])->assertForbidden();
});

it('registra in prima nota incassi e spese con l\'attività e la cambia', function (): void {
    $user = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $user, OrgRole::Owner);
    [$cash, $yoga, $cat] = $this->orgContext()->run($this->org->id, fn () => [Account::query()->sole()->id, course()->id, (string) app(Ledger::class)->categoryFor(CategoryKind::ActivityCost)]);

    $this->actingAs($user)->post('/gestionale/prima-nota/spese', ['date' => now()->toDateString(), 'account_id' => $cash, 'category_id' => $cat, 'amount' => '50,00', 'activity_id' => $yoga])
        ->assertSessionHas('status', 'Spesa registrata.');
    $tx = $this->orgContext()->run($this->org->id, fn () => (string) DB::table('app.transactions')->where('activity_id', $yoga)->value('id'));
    expect($tx)->not->toBe('');

    $this->post("/gestionale/prima-nota/{$tx}/attivita", ['activity_id' => null])->assertSessionHas('status', 'Attività del movimento aggiornata.');
    $this->orgContext()->run($this->org->id, fn () => expect(DB::table('app.transactions')->where('id', $tx)->value('activity_id'))->toBeNull());
    $this->get('/gestionale/prima-nota')->assertInertia(fn (Assert $page) => $page->has('activities', 1));
});
