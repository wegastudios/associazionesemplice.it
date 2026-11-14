<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use Illuminate\Support\Facades\DB;

// Cambio del mese di inizio dell'anno fiscale: possibile fino alla prima chiusura, movimenti riassegnati, ricevute intatte.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->enter($this->org->id);
    $this->years = app(FiscalYearService::class);
    $this->cash = Account::query()->where('type', 'cash')->sole();
    $this->donation = Category::query()->where('kind', CategoryKind::Donation)->sole();
    $this->year = (int) now()->format('Y');
    $this->income = fn (string $date) => app(Ledger::class)->recordIncome(new Income($date, $this->cash->id, [new IncomeLine($this->donation->id, 1000)], null, null, true));
});

it('riassegna i movimenti al nuovo anno fiscale senza cambiare date né numeri delle ricevute', function (): void {
    $dates = [$this->year.'-02-10', $this->year.'-10-05'];
    foreach ($dates as $d) {
        ($this->income)($d);
    }
    $before = DB::table('app.receipts')->orderBy('number')->get(['year', 'number', 'issued_on'])->map(fn ($r) => (array) $r)->all();

    $moved = $this->years->chooseStartMonth(9); // da settembre: febbraio sta nell'anno che comincia a settembre dell'anno prima

    $rows = DB::table('app.transactions')->orderBy('tx_date')->get(['tx_date', 'fiscal_year']);
    expect($moved)->toBe(1)
        ->and($rows->pluck('tx_date')->all())->toBe($dates)
        ->and($rows->pluck('fiscal_year')->all())->toBe([$this->year - 1, $this->year])
        ->and(DB::table('app.fiscal_years')->orderBy('year')->pluck('starts_on')->all())->toContain(($this->year - 1).'-09-01', $this->year.'-09-01')
        ->and(DB::table('app.receipts')->orderBy('number')->get(['year', 'number', 'issued_on'])->map(fn ($r) => (array) $r)->all())->toBe($before)
        ->and(DB::table('audit.log')->where('action', 'fiscal_year.start_month_changed')->count())->toBe(1);
});

it('non ripete mai un numero di ricevuta già emesso, anche dopo il cambio', function (): void {
    foreach ([1, 2, 3] as $i) {
        ($this->income)(now()->toDateString());
    }
    $label = $this->years->yearFor(now()->toDateString());
    expect(DB::table('app.receipts')->where('year', $label)->max('number'))->toBe(3);

    $this->years->chooseStartMonth((int) now()->format('n') === 12 ? 1 : (int) now()->format('n') + 1); // oggi cade nell'anno fiscale precedente
    $result = ($this->income)(now()->toDateString());

    expect(DB::table('app.receipts')->selectRaw('count(*) c, count(distinct (year, number)) d')->first())->toMatchArray(['c' => 4, 'd' => 4])
        ->and($result['receipt'])->toEndWith('/'.$this->years->yearFor(now()->toDateString()));
});

it('si può cambiare finché nessun anno è chiuso, poi no', function (): void {
    $this->years->chooseStartMonth(9);
    $this->years->chooseStartMonth(3);
    expect($this->years->startMonth())->toBe(3);

    $this->years->close($this->years->yearFor(now()->toDateString()));
    expect(fn () => $this->years->chooseStartMonth(5))->toThrow(LedgerError::class, 'prima chiusura');

    // Nemmeno riaprendo: la fotografia fatta alla chiusura resta.
    $this->years->reopen($this->years->yearFor(now()->toDateString()), 'Correzione');
    expect(fn () => $this->years->chooseStartMonth(5))->toThrow(LedgerError::class, 'prima chiusura')
        ->and($this->years->startMonth())->toBe(3);
});
