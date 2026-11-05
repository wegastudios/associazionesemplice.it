<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Services;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use App\Modules\Risorse\Contracts\MemberResources;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Contracts\Typology;
use App\Modules\Risorse\Models\Reservation;
use App\Modules\Risorse\Models\Resource;
use App\Modules\Risorse\Rules\ResourceRules;
use Illuminate\Support\Str;

/** Le risorse viste dall'area soci (vedi il contratto). */
final readonly class MemberResourcesService implements MemberResources
{
    public function __construct(
        private OrgContext $context,
        private Entitlements $entitlements,
        private ReservationService $reservations,
    ) {}

    public function available(): array
    {
        $web = $this->webTypologies();
        if ($web === []) {
            return [];
        }

        return array_values(Resource::query()->whereNull('archived_at')->whereIn('typology', $web)->orderBy('typology')->orderBy('name')->get()
            ->map(static fn (Resource $r): array => [
                'id' => $r->id, 'name' => $r->name, 'typology' => $r->typology->value, 'typology_label' => $r->typology->label(), 'description' => $r->description,
                'fee' => $r->fee_cents, 'nonmember_fee' => $r->nonmember_fee_cents, 'max_slots' => $r->max_slots, 'guests_max' => $r->guests_max,
            ])->all());
    }

    public function slots(string $resourceId, string $date): array
    {
        $r = $this->resource($resourceId);
        if ($r->typology !== Typology::Hourly || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return [];
        }
        $now = ReservationService::now();
        $busy = $this->reservations->between($date.' 00:00:00', date('Y-m-d', (int) strtotime($date.' +1 day')).' 00:00:00', [$r->id]);
        $out = [];
        foreach (ResourceRules::daySlots($r->rules()) as $slot) {
            $s = $date.' '.$slot['start'].':00';
            $e = $date.' '.$slot['end'].':00';
            $past = $s < $now;
            $free = ! $past;
            foreach ($busy as $b) {
                if (ResourceRules::overlaps($s, $e, $b['starts_at'], $b['ends_at'])) {
                    $free = false;
                }
            }
            $out[] = ['start' => $slot['start'], 'end' => $slot['end'], 'free' => $free, 'past' => $past, 'price' => ResourceRules::bandFee($r->rules(), $s)];
        }

        return $out;
    }

    public function dayFree(string $resourceId, string $date): bool
    {
        $r = $this->resource($resourceId);

        return $r->typology === Typology::Daily && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date >= substr(ReservationService::now(), 0, 10)
            && $this->reservations->between($date.' 00:00:00', date('Y-m-d', (int) strtotime($date.' +1 day')).' 00:00:00', [$r->id]) === [];
    }

    public function book(string $resourceId, string $personId, array $in): string
    {
        $r = $this->resource($resourceId);

        return $this->reservations->book($r->id, ['person_id' => $personId, 'date' => $in['date'], 'start' => $in['start'] ?? '', 'units' => $in['units'] ?? 1,
            'guests' => $in['guests'] ?? []], null, true)[0];
    }

    public function mine(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $now = ReservationService::now();
        $rows = Reservation::query()->whereIn('person_id', $personIds)->where('kind', 'booking')->where('status', 'booked')
            ->where(static fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now))->orderBy('starts_at')->limit(100)->get();
        $paid = $this->reservations->paid(array_values($rows->map(static fn (Reservation $x): string => $x->id)->all()));
        $names = Resource::query()->whereIn('id', $rows->pluck('resource_id'))->pluck('name', 'id')->all();

        return array_values($rows->map(static fn (Reservation $x): array => [
            'id' => $x->id, 'resource' => is_string($names[$x->resource_id] ?? null) ? $names[$x->resource_id] : '', 'starts_at' => $x->starts_at, 'ends_at' => $x->ends_at,
            'fee' => $x->fee_due_cents, 'paid' => $paid[$x->id] ?? 0, 'cancellable' => $x->starts_at > $now && ! isset($paid[$x->id]) && $x->ends_at !== null,
        ])->all());
    }

    public function cancel(string $reservationId, string $personId): void
    {
        $x = Str::isUuid($reservationId) ? Reservation::query()->find($reservationId) : null;
        if ($x === null || $x->person_id !== $personId || $x->status !== 'booked' || $x->kind !== 'booking') {
            throw new ResourceError('Prenotazione non trovata.');
        }
        if ($x->starts_at <= ReservationService::now() || $x->ends_at === null) {
            throw new ResourceError('Questa prenotazione non si annulla più da qui: scrivi alla segreteria.');
        }
        if ($this->reservations->paid([$x->id]) !== []) {
            throw new ResourceError('La prenotazione è già pagata: per annullarla scrivi alla segreteria.');
        }
        $this->reservations->cancel($x->id);
    }

    /** @return list<string> le tipologie che il pacchetto ha in versione Web (a ore e a giornata) */
    private function webTypologies(): array
    {
        $ent = $this->entitlements->for($this->context->require()->id);
        $out = [];
        foreach ([Typology::Hourly, Typology::Daily] as $t) {
            if ($ent->limit(Package::Resources, $t->value.'.web') === true) {
                $out[] = $t->value;
            }
        }

        return $out;
    }

    private function resource(string $id): Resource
    {
        $r = Str::isUuid($id) ? Resource::query()->find($id) : null;
        if ($r === null || $r->archived_at !== null || ! in_array($r->typology->value, $this->webTypologies(), true)) {
            throw new ResourceError('Risorsa non disponibile.');
        }

        return $r;
    }
}
