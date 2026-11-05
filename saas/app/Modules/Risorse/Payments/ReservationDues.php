<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Payments;

use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\PayableDues;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Contracts\Typology;
use App\Modules\Risorse\Models\Reservation;
use App\Modules\Risorse\Models\Resource;
use App\Modules\Risorse\Services\ReservationService;
use InvalidArgumentException;

/** Prenotazioni e assegnazioni delle risorse ancora da pagare (chiave r:{prenotazione}). */
final readonly class ReservationDues implements PayableDues
{
    public function __construct(private ReservationService $reservations) {}

    public function dues(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $rows = Reservation::query()->whereIn('person_id', $personIds)->where('kind', 'booking')->where('status', 'booked')
            ->where('fee_due_cents', '>', 0)->orderBy('starts_at')->limit(200)->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $paid = $this->reservations->paid(array_values($rows->map(static fn (Reservation $r): string => $r->id)->all()));
        $names = Resource::query()->whereIn('id', $rows->pluck('resource_id'))->get()->keyBy('id');
        $out = [];
        foreach ($rows as $r) {
            $due = $r->fee_due_cents - ($paid[$r->id] ?? 0);
            $res = $names->get($r->resource_id);
            if ($due > 0 && $res instanceof Resource && is_string($r->person_id)) {
                $t = (int) strtotime($r->starts_at);
                $out[] = ['key' => 'r:'.$r->id, 'person_id' => $r->person_id, 'group' => 'Risorse',
                    'label' => $res->name.' · '.($res->typology === Typology::Hourly ? date('d/m/Y H:i', $t) : 'dal '.date('d/m/Y', $t)), 'amount_cents' => $due];
            }
        }

        return $out;
    }

    public function owns(string $key): bool
    {
        return str_starts_with($key, 'r:');
    }

    public function settle(string $key, int $cents, string $accountId, string $date, string $method): ?string
    {
        if (preg_match('/^r:([0-9a-f-]{36})$/', $key, $m) !== 1) {
            throw new InvalidArgumentException('Voce non valida.');
        }
        try {
            return $this->reservations->collect($m[1], $accountId, $date, $cents, PaymentMethod::from($method));
        } catch (ResourceError|LedgerError $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
