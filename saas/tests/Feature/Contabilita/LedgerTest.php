<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\AccountType;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Expense;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Ledger\AccountService;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Ledger\LedgerService;
use App\Modules\Contabilita\Ledger\StatementService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Contabilita\Models\Receipt;
use App\Modules\Contabilita\Models\Transaction;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Prima nota: si lavora dentro un'associazione con i dati di partenza (categorie, cassa, esercizio in corso).
 */

beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->enter($this->org->id);
    $this->ledger = app(Ledger::class);
    $this->cash = Account::query()->where('type', 'cash')->sole();
    $this->bank = app(AccountService::class)->create('Conto corrente', AccountType::Bank, 100000);
    $this->cat = fn (CategoryKind $kind): string => Category::query()->where('kind', $kind)->sole()->id;
    $this->today = now()->toDateString();
});

function income(string $account, array $lines, ?string $person = null, bool $receipt = true, ?string $date = null): Income
{
    return new Income($date ?? now()->toDateString(), $account, $lines, null, $person, $receipt);
}

it('prepara categorie, cassa ed esercizio per ogni nuova associazione', function (): void {
    expect(Category::query()->count())->toBe(8)
        ->and($this->cash->name)->toBe('Cassa contanti')
        ->and(app(FiscalYearService::class)->all()[0])->toMatchArray(['year' => (int) now()->format('Y'), 'status' => 'open'])
        ->and(DB::table('audit.log')->where('action', 'accounting.seeded')->count())->toBe(1);
});

it('registra un incasso con più voci e una ricevuta numerata senza buchi', function (): void {
    $socio = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi']);
    $year = (int) now()->format('Y');

    $first = $this->ledger->recordIncome(income($this->cash->id, [
        new IncomeLine(($this->cat)(CategoryKind::Membership), 3000, 'Quota '.$year),
        new IncomeLine(($this->cat)(CategoryKind::ActivityFee), 12000, 'Corso', $year.'-09'),
    ], $socio->id));
    $second = $this->ledger->recordIncome(income($this->bank->id, [new IncomeLine(($this->cat)(CategoryKind::Donation), 5000)]));
    $noReceipt = $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine(($this->cat)(CategoryKind::OtherIncome), 100)], receipt: false));

    expect($first['receipt'])->toBe("1/{$year}")
        ->and($first['transactions'])->toHaveCount(2)
        ->and($second['receipt'])->toBe("2/{$year}")
        ->and($noReceipt['receipt'])->toBeNull()
        ->and(Receipt::query()->findOrFail($first['receipt_id'])->total_cents)->toBe(15000);

    $tx = Transaction::query()->findOrFail($first['transactions'][1]);
    expect($tx->method->value)->toBe('cash')
        ->and($tx->competence_month)->toBe($year.'-09')
        ->and($tx->person_id)->toBe($socio->id);

    expect($this->ledger->balances())->toEqual([
        $this->cash->id => 15100,
        $this->bank->id => 105000,
    ])->and($this->ledger->balances())->toEqual(app(LedgerService::class)->recomputedBalances());
});

it('registra spese e giroconti e tiene i saldi allineati', function (): void {
    $this->ledger->recordExpense(new Expense($this->today, $this->bank->id, ($this->cat)(CategoryKind::GeneralCost), 25000, description: 'Affitto'));
    $transfer = $this->ledger->recordTransfer($this->today, $this->bank->id, $this->cash->id, 5000, 'Prelievo');

    $legs = Transaction::query()->where('transfer_id', $transfer)->orderBy('type')->get();
    expect($legs->pluck('type')->map->value->all())->toBe(['transfer_in', 'transfer_out'])
        ->and($this->ledger->balances())->toEqual([$this->cash->id => 5000, $this->bank->id => 70000])
        ->and($this->ledger->balances())->toEqual(app(LedgerService::class)->recomputedBalances());
});

it('rifiuta categorie, importi, conti e persone non validi', function (): void {
    $income = ($this->cat)(CategoryKind::Membership);
    $expense = ($this->cat)(CategoryKind::GeneralCost);

    expect(fn () => $this->ledger->recordIncome(income($this->cash->id, [])))->toThrow(LedgerError::class, 'almeno una voce')
        ->and(fn () => $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine($expense, 100)])))->toThrow(LedgerError::class, 'categoria non valida')
        ->and(fn () => $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine($income, 0)])))->toThrow(LedgerError::class, 'importo')
        ->and(fn () => $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine($income, 100, '', '2026-13')])))->toThrow(LedgerError::class, 'competenza')
        ->and(fn () => $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine($income, 100)], (string) Str::uuid7())))->toThrow(LedgerError::class, 'Persona')
        ->and(fn () => $this->ledger->recordIncome(income((string) Str::uuid7(), [new IncomeLine($income, 100)])))->toThrow(LedgerError::class, 'Conto')
        ->and(fn () => $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine($income, 100)], date: '2026-02-30')))->toThrow(LedgerError::class, 'Data')
        ->and(fn () => $this->ledger->recordExpense(new Expense($this->today, $this->cash->id, $income, 100)))->toThrow(LedgerError::class, 'spesa')
        ->and(fn () => $this->ledger->recordTransfer($this->today, $this->cash->id, $this->cash->id, 100))->toThrow(LedgerError::class, 'diversi');

    expect(Transaction::query()->count())->toBe(0);
});

it('storna un giroconto intero e una ricevuta intera, senza riusare il numero', function (): void {
    $year = (int) now()->format('Y');
    $receipt = $this->ledger->recordIncome(income($this->cash->id, [
        new IncomeLine(($this->cat)(CategoryKind::Membership), 3000),
        new IncomeLine(($this->cat)(CategoryKind::ActivityFee), 2000),
    ]));
    $transfer = $this->ledger->recordTransfer($this->today, $this->bank->id, $this->cash->id, 1000);

    expect($this->ledger->void($receipt['transactions'][1], 'Errore di battitura'))->toBe(2);
    $leg = Transaction::query()->where('transfer_id', $transfer)->firstOrFail();
    expect($this->ledger->void($leg->id, ''))->toBe(2);

    expect(Receipt::query()->findOrFail($receipt['receipt_id'])->void_reason)->toBe('Errore di battitura')
        ->and(Transaction::query()->whereNull('voided_at')->count())->toBe(0)
        ->and(Transaction::query()->where('transfer_id', $transfer)->pluck('void_reason')->unique()->all())->toBe(['Stornato'])
        ->and($this->ledger->balances())->toEqual([$this->cash->id => 0, $this->bank->id => 100000])
        ->and(fn () => $this->ledger->void($leg->id, 'di nuovo'))->toThrow(LedgerError::class, 'già stornato');

    $next = $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine(($this->cat)(CategoryKind::Donation), 500)]));
    expect($next['receipt'])->toBe("2/{$year}");
});

it('non lascia modificare né cancellare la prima nota, nemmeno con query scritte a mano', function (): void {
    $id = $this->ledger->recordExpense(new Expense($this->today, $this->cash->id, ($this->cat)(CategoryKind::GeneralCost), 1000));

    expect(fn () => DB::transaction(fn () => DB::table('app.transactions')->where('id', $id)->update(['amount_cents' => 1])))
        ->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => DB::transaction(fn () => DB::table('app.transactions')->where('id', $id)->delete()))
        ->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => DB::transaction(fn () => DB::table('app.account_balances')->update(['balance_cents' => 999999])))
        ->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => DB::transaction(fn () => DB::table('app.receipts')->update(['number' => 99])))
        ->toThrow(QueryException::class, 'permission denied');

    // Un inserimento scritto a mano passa comunque dai controlli del database.
    expect(fn () => DB::transaction(fn () => DB::table('app.transactions')->insert([
        'org_id' => $this->org->id, 'id' => (string) Str::uuid7(), 'tx_date' => $this->today, 'fiscal_year' => 2001,
        'type' => 'income', 'amount_cents' => 100, 'account_id' => $this->cash->id, 'method' => 'cash',
        'category_id' => ($this->cat)(CategoryKind::Donation),
    ])))->toThrow(QueryException::class);
});

it('in un esercizio chiuso non entra né si storna nulla, finché non si riapre', function (): void {
    $year = (int) now()->format('Y');
    $years = app(FiscalYearService::class);
    $id = $this->ledger->recordExpense(new Expense($this->today, $this->cash->id, ($this->cat)(CategoryKind::GeneralCost), 1000));

    $years->close($year);

    expect(fn () => $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine(($this->cat)(CategoryKind::Donation), 100)])))
        ->toThrow(LedgerError::class, "esercizio {$year} è chiuso")
        ->and(fn () => $this->ledger->void($id, 'x'))->toThrow(LedgerError::class, 'chiuso')
        ->and(fn () => $years->reopen($year, ' '))->toThrow(LedgerError::class, 'perché');

    $years->reopen($year, 'Correzione richiesta dal commercialista');
    expect($this->ledger->void($id, 'x'))->toBe(1)
        ->and(DB::table('audit.log')->whereIn('action', ['fiscal_year.closed', 'fiscal_year.reopened'])->count())->toBe(2);
});

it('crea da sé l\'esercizio solare di una data nuova e rifiuta esercizi sovrapposti', function (): void {
    $years = app(FiscalYearService::class);
    $next = (int) now()->format('Y') + 1;

    $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine(($this->cat)(CategoryKind::Donation), 100)], date: "{$next}-03-15"));

    expect(collect($years->all())->pluck('year')->all())->toContain($next)
        ->and(fn () => $years->create($next + 1, "{$next}-09-01"))->toThrow(LedgerError::class, 'sovrappone');
});

it('non chiude un conto con un saldo e rifiuta i movimenti su un conto chiuso', function (): void {
    $accounts = app(AccountService::class);

    expect(fn () => $accounts->close($this->bank->id))->toThrow(LedgerError::class, '1.000,00 €');

    $this->ledger->recordTransfer($this->today, $this->bank->id, $this->cash->id, 100000);
    $accounts->close($this->bank->id);

    expect(fn () => $this->ledger->recordExpense(new Expense($this->today, $this->bank->id, ($this->cat)(CategoryKind::GeneralCost), 100)))
        ->toThrow(LedgerError::class, 'conto è chiuso');
});

it('prepara il rendiconto per cassa senza storni né giroconti', function (): void {
    $year = (int) now()->format('Y');
    $this->ledger->recordIncome(income($this->cash->id, [
        new IncomeLine(($this->cat)(CategoryKind::Membership), 3000),
        new IncomeLine(($this->cat)(CategoryKind::Membership), 3000),
    ]));
    $voided = $this->ledger->recordIncome(income($this->cash->id, [new IncomeLine(($this->cat)(CategoryKind::Donation), 9999)]));
    $this->ledger->void($voided['transactions'][0], 'Errore');
    $this->ledger->recordExpense(new Expense($this->today, $this->bank->id, ($this->cat)(CategoryKind::GeneralCost), 2500));
    $this->ledger->recordTransfer($this->today, $this->bank->id, $this->cash->id, 7000);

    $s = app(StatementService::class)->forYear($year);

    expect($s['income'])->toBe([['group' => 'Entrate da quote associative', 'category' => 'Quota associativa', 'cents' => 6000]])
        ->and($s['expense'])->toBe([['group' => 'Uscite di supporto generale', 'category' => 'Costi generali (affitto, utenze, assicurazione)', 'cents' => 2500]])
        ->and($s['totals'])->toBe(['income' => 6000, 'expense' => 2500, 'result' => 3500])
        ->and($s['accounts'])->toBe([
            ['name' => 'Cassa contanti', 'opening' => 0, 'closing' => 13000],
            ['name' => 'Conto corrente', 'opening' => 100000, 'closing' => 90500],
        ]);
});

it('non tocca i movimenti di un\'altra associazione', function (): void {
    $id = $this->ledger->recordExpense(new Expense($this->today, $this->cash->id, ($this->cat)(CategoryKind::GeneralCost), 1000));
    $other = $this->createOrg('Circolo Beta');

    $this->orgContext()->run($other->id, function () use ($id): void {
        expect(fn () => app(Ledger::class)->void($id, 'x'))->toThrow(LedgerError::class, 'inesistente')
            ->and(fn () => app(Ledger::class)->recordExpense(new Expense(now()->toDateString(), $this->cash->id, Category::query()->where('kind', 'general_cost')->sole()->id, 100)))
            ->toThrow(LedgerError::class, 'Conto non valido');
    });

    expect(Transaction::query()->findOrFail($id)->voided_at)->toBeNull();
});

it('registra la commissione di incasso del conto come costo generale, solo se il conto ne ha una', function (): void {
    $donation = ($this->cat)(CategoryKind::Donation);
    $this->ledger->recordIncome(income($this->bank->id, [new IncomeLine($donation, 10000)]));
    expect(Transaction::query()->where('type', 'expense')->count())->toBe(0);

    app(AccountService::class)->setFee($this->bank->id, 25, 150);
    $this->ledger->recordIncome(income($this->bank->id, [new IncomeLine($donation, 10000)]));
    $fee = Transaction::query()->where('type', 'expense')->sole();

    expect($fee->amount_cents)->toBe(175)
        ->and($fee->account_id)->toBe($this->bank->id)
        ->and($fee->description)->toBe('Commissione di incasso (stima) — Conto corrente')
        ->and(Category::query()->find($fee->category_id)->kind->value)->toBe('general_cost');

    // L'import dello storico non aggiunge commissioni.
    $this->ledger->recordIncome(new Income(now()->toDateString(), $this->bank->id, [new IncomeLine($donation, 10000)], null, null, false, null, null, false));
    expect(Transaction::query()->where('type', 'expense')->count())->toBe(1);
});

it('la commissione ha limiti: al massimo 20 euro e 25 per cento', function (): void {
    $accounts = app(AccountService::class);

    expect(fn () => $accounts->setFee($this->bank->id, 2001, 0))->toThrow(LedgerError::class)
        ->and(fn () => $accounts->setFee($this->bank->id, 0, 2501))->toThrow(LedgerError::class)
        ->and($accounts->setFee($this->bank->id, 2000, 2500)->fee_bp)->toBe(2500);
});

it('gli esercizi si chiudono in ordine e si riapre solo l\'ultimo chiuso', function (): void {
    $years = app(FiscalYearService::class);
    $old = (int) now()->subYear()->format('Y');
    $current = (int) now()->format('Y');
    $this->ledger->recordExpense(new Expense(now()->subYear()->format('Y-m-d'), $this->cash->id, ($this->cat)(CategoryKind::GeneralCost), 500));
    $this->ledger->recordExpense(new Expense($this->today, $this->cash->id, ($this->cat)(CategoryKind::GeneralCost), 700));

    expect(fn () => $years->close($current))->toThrow(LedgerError::class, "chiudi il {$old}");

    $years->close($old);
    $years->close($current);

    expect(fn () => $years->reopen($old, 'Correzione'))->toThrow(LedgerError::class, "riapri il {$current}");

    $years->reopen($current, 'Correzione');
    $years->reopen($old, 'Correzione');
    expect(DB::table('app.fiscal_years')->where('status', 'open')->count())->toBe(2);
});
