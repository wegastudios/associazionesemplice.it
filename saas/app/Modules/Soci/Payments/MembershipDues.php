<?php

declare(strict_types=1);

namespace App\Modules\Soci\Payments;

use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\PayableDues;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use InvalidArgumentException;

/** La quota associativa dell'anno che il programma sceglie (in corso, o il successivo negli ultimi giorni), per chi non ha ancora quella tessera (chiave q:{persona}:{anno}). */
final readonly class MembershipDues implements PayableDues
{
    public function __construct(private MembershipService $memberships) {}

    public function dues(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $out = [];
        foreach (Person::query()->whereIn('id', $personIds)->whereNull('left_on')->whereNull('anonymized_at')->get() as $p) {
            $plan = $this->memberships->renewalPlan($p->id);
            $year = $plan['year'];
            if (! $p->type->isMember() || $p->type->isAutoRenewed() || $this->memberships->holds($p->id, $year)) {
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

    public function notEnrolled(string $key): ?string
    {
        return null;
    }

    public function settle(string $key, int $cents, string $accountId, string $date, string $method, bool $anyway = false): ?string
    {
        if (preg_match('#^q:([0-9a-f-]{36}):(\d{4}(?:/\d{4})?)$#', $key, $m) !== 1) {
            throw new InvalidArgumentException('Voce non valida.');
        }
        try {
            return $this->memberships->renewWithPayment($m[1], $accountId, $cents, $date, $anyway && $this->memberships->holds($m[1], $m[2]) ? $m[2] : null, PaymentMethod::from($method), $anyway)['receipt'];
        } catch (PeopleError $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
