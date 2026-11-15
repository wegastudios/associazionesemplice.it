<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Expense;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Contabilita\Models\Transaction;
use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Inertia\Testing\AssertableInertia as Assert;

// Aspetto fiscale: il «regime» è avere o no la partita IVA; senza, l'IVA non si applica mai.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
    $this->orgContext()->enter($this->org->id);
    $this->ledger = app(Ledger::class);
    $this->cash = Account::query()->where('type', 'cash')->sole();
    $this->donation = Category::query()->where('kind', CategoryKind::Donation)->sole()->id;
    $this->cost = Category::query()->where('kind', CategoryKind::GeneralCost)->sole()->id;
});

function incomeWith(string $account, string $category, ?int $rate): Income
{
    return new Income(now()->toDateString(), $account, [new IncomeLine($category, 1000, '', null, $rate)], null, null, false);
}

it('senza partita IVA nessuna aliquota oltre l\'IVA esente', function (): void {
    expect(app(OrgFiscal::class)->hasVat())->toBeFalse()->and(app(OrgFiscal::class)->rates())->toBe([]);
    $this->ledger->recordIncome(incomeWith($this->cash->id, $this->donation, null));
    $this->ledger->recordIncome(incomeWith($this->cash->id, $this->donation, 0));

    expect(fn () => $this->ledger->recordIncome(incomeWith($this->cash->id, $this->donation, 22)))->toThrow(LedgerError::class, 'senza partita IVA');
    expect(fn () => $this->ledger->recordExpense(new Expense(now()->toDateString(), $this->cash->id, $this->cost, 500, null, '', null, null, 10)))->toThrow(LedgerError::class, 'senza partita IVA');
});

it('con la partita IVA ammette solo le aliquote scelte', function (): void {
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    expect(app(OrgFiscal::class)->rates())->toBe([22, 10, 5, 4]);

    $this->ledger->recordIncome(incomeWith($this->cash->id, $this->donation, 22));
    $this->ledger->recordExpense(new Expense(now()->toDateString(), $this->cash->id, $this->cost, 500, null, '', null, null, 4));
    expect(fn () => $this->ledger->recordIncome(incomeWith($this->cash->id, $this->donation, 17)))->toThrow(LedgerError::class, 'non è tra quelle scelte');

    app(OrgSettings::class)->set(OrgFiscal::RATES, '22, 10');
    expect(app(OrgFiscal::class)->rates())->toBe([22, 10])->and(fn () => $this->ledger->recordIncome(incomeWith($this->cash->id, $this->donation, 4)))->toThrow(LedgerError::class);
});

it('la quota associativa è IVA esente con la partita IVA, a scelta, e fuori campo senza', function (): void {
    expect(app(OrgFiscal::class)->membershipRate())->toBeNull();

    app(OrgSettings::class)->set('ente.vat', '12345678903');
    expect(app(OrgFiscal::class)->membershipRate())->toBe(0);
    app(OrgSettings::class)->set(OrgFiscal::MEMBERSHIP_RATE, 10);
    expect(app(OrgFiscal::class)->membershipRate())->toBe(10);

    $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01']);
    app(MembershipService::class)->renewWithPayment($p->id, $this->cash->id, 3000);
    $tx = Transaction::query()->where('amount_cents', 3000)->sole();
    expect((int) $tx->vat_rate)->toBe(10)->and((int) $tx->vat_cents)->toBeGreaterThanOrEqual(272)->toBeLessThanOrEqual(273);
});

it('la pagina Aspetto fiscale salva aliquote, prezzi e quota; senza partita IVA non c\'è nulla da impostare', function (): void {
    $in = fn (callable $f): mixed => $this->orgContext()->run($this->org->id, $f);
    $this->orgContext()->leave();
    $this->actingAs($this->owner)->get('/gestionale/aspetto-fiscale')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Contabilita/AspettoFiscale')->where('hasVat', false));
    $this->put('/gestionale/aspetto-fiscale', ['rates' => '22', 'includes_vat' => true, 'membership_rate' => 0])->assertSessionHasErrors('rates');

    $in(fn () => app(OrgSettings::class)->set('ente.vat', '12345678903'));
    $this->put('/gestionale/aspetto-fiscale', ['rates' => '22, 10, 5, x', 'includes_vat' => false, 'membership_rate' => 5])->assertSessionHasNoErrors();
    $in(fn () => expect(app(OrgFiscal::class)->rates())->toBe([22, 10, 5])->and(app(OrgFiscal::class)->includesVat())->toBeFalse()->and(app(OrgFiscal::class)->membershipRate())->toBe(5));
    $this->put('/gestionale/aspetto-fiscale', ['rates' => '22', 'includes_vat' => true, 'membership_rate' => 5])->assertSessionHasErrors('membership_rate');
    $this->put('/gestionale/aspetto-fiscale', ['rates' => 'abc', 'includes_vat' => true, 'membership_rate' => 0])->assertSessionHasErrors('rates');
});

it('con i prezzi senza IVA l\'importo inserito diventa finale aggiungendo l\'IVA', function (): void {
    $in = fn (callable $f): mixed => $this->orgContext()->run($this->org->id, $f);
    app(OrgSettings::class)->set('ente.vat', '12345678903');
    app(OrgSettings::class)->set(OrgFiscal::INCLUDES_VAT, false);
    $this->orgContext()->leave();

    $this->actingAs($this->owner)->post('/gestionale/prima-nota/incassi', [
        'date' => now()->toDateString(), 'account_id' => $this->cash->id, 'receipt' => false,
        'lines' => [['category_id' => $this->donation, 'amount' => '100,00', 'vat_rate' => '22']],
    ])->assertSessionHasNoErrors();
    $tx = $in(fn () => Transaction::query()->where('vat_rate', 22)->sole());
    expect((int) $tx->amount_cents)->toBe(12200)->and((int) $tx->vat_cents)->toBe(2200);
});
