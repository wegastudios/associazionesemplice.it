<?php

declare(strict_types=1);

use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MemberBookService;
use App\Modules\Soci\Services\MemberBookSnapshots;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($this->org->id, function (): void {
        $old = Person::create(['type' => 'founder', 'first_name' => 'Franco', 'last_name' => 'Fondatore', 'joined_on' => '2015-03-01', 'tax_code' => 'RSSMRA80A01H501U', 'card_number' => '1']);
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi', 'joined_on' => '2020-09-10', 'birth_date' => '1985-01-01', 'birth_place' => 'Roma', 'address' => ['text' => 'Via Roma 1, 00100 Roma']]);
        $gone = Person::create(['first_name' => 'Gino', 'last_name' => 'Uscito', 'joined_on' => '2018-01-15']);
        Person::create(['type' => 'guest', 'first_name' => 'Ospite', 'last_name' => 'Esterno', 'host_person_id' => $anna->id, 'phone' => '333 1234567']);
        Person::create(['type' => 'guardian', 'first_name' => 'Non', 'last_name' => 'Socio']);
        app(PeopleService::class)->leave($gone->id, '2024-06-30', 'Recesso');
        app(MembershipService::class)->renewWithPayment($anna->id, Account::query()->sole()->id, 2500);
        unset($old);
    });
});

it('elenca i soci in ordine di ingresso con quota, cessazione e filtri', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $book = app(MemberBookService::class);
        $rows = $book->rows();
        expect(array_column($rows, 'name'))->toBe(['Fondatore Franco', 'Uscito Gino', 'Rossi Anna'])
            ->and(array_column($rows, 'n'))->toBe([1, 2, 3])
            ->and($rows[0]['tax_code'])->toBe('RSSMRA80A01H501U')
            ->and($rows[1])->toMatchArray(['left_on' => '30/06/2024', 'left_reason' => 'Recesso', 'in_force' => false])
            ->and($rows[2])->toMatchArray(['birth' => '01/01/1985 Roma', 'residence' => 'Via Roma 1, 00100 Roma', 'fee' => '25,00 €', 'fee_year' => now()->format('Y'), 'joined_on' => '10/09/2020'])
            ->and(array_column($book->rows(MemberBookService::IN_FORCE), 'n'))->toBe([1, 3])
            ->and(array_column($book->rows(MemberBookService::LEFT), 'name'))->toBe(['Uscito Gino']);
    });
});

it('controlla la data di uscita', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::query()->where('first_name', 'Anna')->sole();
        expect(fn () => app(PeopleService::class)->leave($p->id, now()->addDay()->toDateString(), ''))->toThrow(PeopleError::class)
            ->and(fn () => app(PeopleService::class)->leave($p->id, '2019-01-01', ''))->toThrow(PeopleError::class);
    });
});

it('mostra, stampa ed esporta il libro soci secondo i permessi', function (): void {
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $acc = $this->createUser('acc@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->addOperator($this->org, $acc, OrgRole::Accountant);

    $this->actingAs($op)->get('/gestionale/soci/libro?stato=in_force')->assertInertia(fn (Assert $page) => $page->component('Soci/LibroSoci')
        ->where('filter', 'in_force')->has('rows', 2)->where('canExport', true));
    $pdf = (string) $this->get('/gestionale/soci/libro.pdf')->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
    expect($pdf)->toContain('Libro soci')->toContain('Fondatore Franco')->toContain('Recesso')->toContain('/MediaBox [0 0 842 595]');
    $csv = (string) $this->get('/gestionale/soci/libro.csv?stato=left')->assertOk()->getContent();
    expect($csv)->toContain('Uscito Gino')->not->toContain('Rossi Anna');
    $this->orgContext()->run($this->org->id, fn () => expect(DB::table('audit.log')->where('action', 'member_book.exported')->count())->toBe(2));

    $this->actingAs($acc)->get('/gestionale/soci/libro')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canExport', false));
    $this->get('/gestionale/soci/libro.pdf')->assertForbidden();
});

it('va a capo di pagina con molti soci', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $rows = array_fill(0, 45, app(MemberBookService::class)->rows()[0]);
        expect(app(MemberBookService::class)->pdf($rows, 'X', ''))->toContain('/Count 3')->toContain('pagina 3');
    });
});

it('fa entrare nel libro di un esercizio chi ha avuto una tessera valida almeno un giorno; a cavallo compare in entrambi', function (): void {
    $card = [['2025-09-01', '2026-08-31']];
    $enters = fn (string $from, string $to) => MemberBookService::entersPeriod('ordinary', $card, [$from, $to], '2020-01-01', null);

    expect($enters('2025-01-01', '2025-12-31'))->toBeTrue()
        ->and($enters('2026-01-01', '2026-12-31'))->toBeTrue()
        ->and($enters('2024-01-01', '2024-12-31'))->toBeFalse()
        ->and($enters('2027-01-01', '2027-12-31'))->toBeFalse()
        ->and(MemberBookService::entersPeriod('founder', [], ['2026-01-01', '2026-12-31'], '2015-03-01', null))->toBeTrue()
        ->and(MemberBookService::entersPeriod('founder', [], ['2026-01-01', '2026-12-31'], '2015-03-01', '2025-06-30'))->toBeFalse();
});

it('fotografa il libro alla chiusura dell\'esercizio e non lo cambia più, nemmeno se la persona viene cancellata', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $years = app(FiscalYearService::class);
        $snapshots = app(MemberBookSnapshots::class);
        $year = $years->yearFor(now()->format('Y-m-d'));

        // Dal vivo: il fondatore e chi ha rinnovato; chi era uscito prima dell'anno no.
        expect(array_column($snapshots->forYear($year)['rows'], 'name'))->toBe(['Fondatore Franco', 'Rossi Anna']);

        $years->close($year);
        Person::query()->where('first_name', 'Anna')->sole()->delete();

        $closed = $snapshots->forYear($year);
        expect($closed['source'])->toBe('snapshot')
            ->and(array_column($closed['rows'], 'name'))->toBe(['Fondatore Franco', 'Rossi Anna'])
            ->and($closed['rows'][1]['email'])->toBe('')
            ->and($closed['rows'][1]['residence'])->toBe('Via Roma 1, 00100 Roma');

        // Riaperto: la fotografia resta come versione precedente e si torna al vivo.
        $years->reopen($year, 'Correzione');
        expect(DB::table('app.member_book_snapshots')->whereNotNull('superseded_at')->count())->toBe(1)
            ->and($snapshots->forYear($year)['source'])->toBe('live')
            ->and(array_column($snapshots->forYear($year)['rows'], 'name'))->toBe(['Fondatore Franco']);

        $years->close($year);
        expect(DB::table('app.member_book_snapshots')->where('fiscal_year', $year)->orderBy('version')->pluck('version')->all())->toBe([1, 2]);
    });
});
