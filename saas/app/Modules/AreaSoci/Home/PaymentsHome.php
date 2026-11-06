<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Home;

use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\HomeItem;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;

/** Home: pagamenti online dei soci da controllare. */
final readonly class PaymentsHome implements HomeWidget
{
    public function __construct(
        private OnlinePayments $payments,
        private Entitlements $entitlements,
    ) {}

    public function items(Operator $operator): array
    {
        if (! $operator->can(Ability::EditAccounting) || ! $this->entitlements->for($operator->orgId)->has(Package::MemberArea)) {
            return [];
        }
        $review = count(array_filter($this->payments->list(null, 200), static fn (array $p): bool => $p['status'] === 'review'));

        return $review === 0 ? [] : [HomeItem::todo($review === 1 ? '1 pagamento online da controllare' : $review.' pagamenti online da controllare', '/gestionale/pagamenti-online', 'danger', 'Importo diverso o voce non più dovuta', 15)];
    }
}
