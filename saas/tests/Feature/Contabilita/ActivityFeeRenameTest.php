<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Ledger\ActivityFeeRename;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->enter($this->org->id);
    $this->fee = Category::query()->where('kind', CategoryKind::ActivityFee)->sole();
});

it('parte con il nome nuovo nelle associazioni nuove', function (): void {
    expect($this->fee->name)->toBe('Contributo attività / corso')->and(CategoryKind::ActivityFee->label())->toBe('Contributo attività / corso');
});

it('rinomina la voce rimasta col nome vecchio e riporta indietro', function (): void {
    $this->fee->update(['name' => ActivityFeeRename::OLD_NAME]);

    expect(ActivityFeeRename::up())->toBe(1)
        ->and($this->fee->fresh()->name)->toBe('Contributo attività / corso');
    expect(ActivityFeeRename::down())->toBe(1)
        ->and($this->fee->fresh()->name)->toBe('Quota attività / corso');
});

it('lascia intatto un nome personalizzato', function (): void {
    $this->fee->update(['name' => 'Quote dei corsi']);

    expect(ActivityFeeRename::up())->toBe(0)->and($this->fee->fresh()->name)->toBe('Quote dei corsi');
});

it('riconosce la voce per natura, non per nome', function (): void {
    $membership = Category::query()->where('kind', CategoryKind::Membership)->sole();
    $membership->update(['name' => ActivityFeeRename::OLD_NAME]);
    $this->fee->update(['name' => ActivityFeeRename::OLD_NAME]);

    expect(ActivityFeeRename::up())->toBe(1)
        ->and($membership->fresh()->name)->toBe('Quota attività / corso')
        ->and($this->fee->fresh()->name)->toBe('Contributo attività / corso');
});

it('non tocca la voce usata in un anno fiscale chiuso', function (): void {
    $year = (int) now()->format('Y');
    $cash = Account::query()->where('type', 'cash')->sole();
    app(Ledger::class)->recordIncome(new Income(now()->toDateString(), $cash->id, [new IncomeLine($this->fee->id, 3000, 'Corso')], null, null, false));
    $this->fee->update(['name' => ActivityFeeRename::OLD_NAME]);
    app(FiscalYearService::class)->close($year);

    expect(ActivityFeeRename::up())->toBe(0)->and($this->fee->fresh()->name)->toBe('Quota attività / corso');

    // Riaperto l'anno, la voce si rinomina.
    app(FiscalYearService::class)->reopen($year, 'Correzione');
    expect(ActivityFeeRename::up())->toBe(1)->and($this->fee->fresh()->name)->toBe('Contributo attività / corso');
});
