<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Services;

use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\Core\Contracts\DonationInvites;
use App\Modules\Core\Contracts\OrgDonations;

/** L'invito a donare si mostra solo se il posto è acceso e i soci hanno un modo per donare (la spunta al pagamento online). */
final readonly class MemberDonationInvites implements DonationInvites
{
    public function __construct(private OrgDonations $donations, private OnlinePayments $payments) {}

    public function enabled(string $spot): bool
    {
        return in_array($spot, self::SPOTS, true) && $this->donations->invite($spot) && $this->donations->checkout() && $this->payments->providers() !== [];
    }
}
