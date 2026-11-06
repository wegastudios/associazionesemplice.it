<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Home;

use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\HomeItem;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\Operator;

/** Home: i saldi dei conti aperti. */
final readonly class AccountingHome implements HomeWidget
{
    public function __construct(private Ledger $ledger) {}

    public function items(Operator $operator): array
    {
        if (! $operator->can(Ability::ViewAccounting)) {
            return [];
        }
        $balances = $this->ledger->balances();

        return array_map(static fn (array $a): HomeItem => HomeItem::stat($a['name'], Money::format($balances[$a['id']] ?? 0), '/gestionale/conti'), $this->ledger->openAccounts());
    }
}
