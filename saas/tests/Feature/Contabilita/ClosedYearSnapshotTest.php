<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\AccountType;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Ledger\AccountService;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Ledger\StatementService;
use App\Modules\Contabilita\Ledger\StatementSnapshots;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Core\Contracts\OrgRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->enter($this->org->id);
    $this->cash = Account::query()->where('type', 'cash')->sole();
    $this->year = (int) now()->format('Y');
    $this->donation = Category::query()->where('kind', CategoryKind::Donation)->sole();
    app(Ledger::class)->recordIncome(new Income(now()->toDateString(), $this->cash->id, [new IncomeLine($this->donation->id, 5000, 'Offerta')], null, null, false));
});

it('fotografa il rendiconto alla chiusura e lo legge da lì, non dai dati vivi', function (): void {
    $years = app(FiscalYearService::class);
    $snapshots = app(StatementSnapshots::class);

    expect($snapshots->forYear($this->year)['source'])->toBe('live');
    $live = app(StatementService::class)->forYear($this->year);

    $years->close($this->year);
    $closed = $snapshots->forYear($this->year);
    expect($closed['source'])->toBe('snapshot')->and($closed['version'])->toBe(1)
        ->and(array_diff_key($closed['statement'], ['status' => 1]))->toEqual(array_diff_key($live, ['status' => 1]));

    // Riaperto: la fotografia resta come versione superata e si torna al vivo.
    $years->reopen($this->year, 'Correzione');
    expect($snapshots->forYear($this->year)['source'])->toBe('live')
        ->and($snapshots->versions($this->year))->toHaveCount(1)
        ->and($snapshots->versions($this->year)[0]['superseded'])->toBeTrue();

    $years->close($this->year);
    expect(array_column($snapshots->versions($this->year), 'version'))->toBe([2, 1])
        ->and($snapshots->forYear($this->year)['version'])->toBe(2);
});

it('non lascia cambiare voci e conti usati in un esercizio chiuso', function (): void {
    app(FiscalYearService::class)->close($this->year);

    // Ogni rifiuto in un punto di salvataggio: dentro la transazione del test un errore la lascerebbe interrotta.
    expect(fn () => DB::transaction(fn () => $this->donation->update(['name' => 'Altro nome'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => app(AccountService::class)->update($this->cash->id, 'Altro conto', AccountType::Cash, 0)))->toThrow(LedgerError::class);

    // L'ordine non è un dato dell'esercizio: si può cambiare.
    $this->donation->update(['sort_order' => 99]);
    expect(Category::query()->findOrFail($this->donation->id)->sort_order)->toBe(99);

    // Riaperto, si può di nuovo.
    app(FiscalYearService::class)->reopen($this->year, 'Correzione');
    $this->donation->update(['name' => 'Offerte libere']);
    expect(Category::query()->findOrFail($this->donation->id)->name)->toBe('Offerte libere');
});

it('elenca le versioni delle fotografie nelle pagine', function (): void {
    app(FiscalYearService::class)->close($this->year);
    $user = $this->createUser('admin@example.com', twoFactor: true);
    $this->addOperator($this->org, $user, OrgRole::Admin);

    $this->actingAs($user)->get("/gestionale/rendiconto/{$this->year}")->assertInertia(fn (Assert $page) => $page
        ->where('source', 'snapshot')->where('version', 1)->has('versions', 1));
    $this->get("/gestionale/soci/libro?anno={$this->year}")->assertInertia(fn (Assert $page) => $page->where('source', 'snapshot')->has('versions', 1));
});
