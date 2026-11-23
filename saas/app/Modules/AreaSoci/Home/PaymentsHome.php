<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Home;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\HomeItem;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use Illuminate\Database\DatabaseManager;

/** Home: pagamenti online dei soci da controllare. */
final readonly class PaymentsHome implements HomeWidget
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private Entitlements $entitlements,
    ) {}

    public function items(Operator $operator): array
    {
        if (! $operator->can(Ability::EditAccounting) || ! $this->entitlements->for($operator->orgId)->has(Package::MemberArea)) {
            return [];
        }
        // Conteggio diretto sull'indice per stato: tutti quelli da controllare, non solo tra gli ultimi pagamenti.
        $review = $this->db->connection($this->context->require()->connection)->table('app.online_payments')->where('status', 'review')->count();

        return $review === 0 ? [] : [HomeItem::todo($review === 1 ? '1 pagamento online da controllare' : $review.' pagamenti online da controllare', '/gestionale/pagamenti-online', 'danger', 'Importo diverso o voce non più dovuta', 15)];
    }
}
