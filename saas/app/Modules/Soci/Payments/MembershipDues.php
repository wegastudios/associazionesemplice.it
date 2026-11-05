<?php

declare(strict_types=1);

namespace App\Modules\Soci\Payments;

use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\PayableDues;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use InvalidArgumentException;

/** La quota associativa dell'anno sociale in corso, per chi non ha ancora la tessera (chiave q:{persona}:{anno}). */
final readonly class MembershipDues implements PayableDues
{
    public function __construct(private MembershipService $memberships) {}

    public function dues(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $year = $this->memberships->socialYearLabel();
        $out = [];
        foreach (Person::query()->whereIn('id', $personIds)->whereNull('left_on')->whereNull('anonymized_at')->get() as $p) {
            if (! $p->type->isMember() || $p->type->isAutoRenewed() || $this->memberships->isActive($p->id)) {
                continue;
            }
            $fee = $this->memberships->feeFor($p);
            if ($fee > 0) {
                $out[] = ['key' => 'q:'.$p->id.':'.$year, 'person_id' => $p->id, 'group' => 'Quota associativa',
                    'label' => 'Quota associativa '.$year.' · '.$p->fullName(), 'amount_cents' => $fee];
            }
        }

        return $out;
    }

    public function owns(string $key): bool
    {
        return str_starts_with($key, 'q:');
    }

    public function settle(string $key, int $cents, string $accountId, string $date, string $method): ?string
    {
        if (preg_match('#^q:([0-9a-f-]{36}):(\d{4}(?:/\d{4})?)$#', $key, $m) !== 1) {
            throw new InvalidArgumentException('Voce non valida.');
        }
        try {
            return $this->memberships->renewWithPayment($m[1], $accountId, $cents, $date, $m[2], PaymentMethod::from($method))['receipt'];
        } catch (PeopleError $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
