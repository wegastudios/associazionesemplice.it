<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Http\Controllers;

use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\Input;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Contracts\Typology;
use App\Modules\Risorse\Rules\ResourceRules;
use App\Modules\Risorse\Services\ReservationService;
use App\Modules\Risorse\Services\ResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Prenotazioni dalla segreteria, blocchi, annullamenti, incassi, lista d'attesa e calendario di occupazione. */
final readonly class ReservationsController
{
    public function __construct(
        private ResourceService $resources,
        private ReservationService $reservations,
    ) {}

    /** Il giorno scelto: le fasce delle risorse a ore, i 14 giorni delle risorse a giornata, i titolari delle stagionali. */
    public function calendar(Request $request): Response
    {
        $date = $request->string('giorno')->toString();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false ? $date : substr(ReservationService::now(), 0, 10);
        $days = [];
        for ($i = 0; $i < 14; $i++) {
            $days[] = date('Y-m-d', (int) strtotime($date.' +'.$i.' days'));
        }
        $list = $this->resources->all();
        $bookings = [];
        foreach ($this->reservations->between($date.' 00:00:00', date('Y-m-d', (int) strtotime($date.' +14 days')).' 00:00:00') as $b) {
            $bookings[$b['resource_id']][] = $b;
        }
        $label = self::label(...);

        $hourly = $daily = $seasonal = [];
        foreach ($list as $r) {
            $mine = $bookings[$r->id] ?? [];
            if ($r->typology === Typology::Hourly) {
                $slots = [];
                foreach (ResourceRules::daySlots($r->rules()) as $slot) {
                    $s = $date.' '.$slot['start'].':00';
                    $e = $date.' '.$slot['end'].':00';
                    $hit = null;
                    foreach ($mine as $b) {
                        if (ResourceRules::overlaps($s, $e, $b['starts_at'], $b['ends_at'])) {
                            $hit = $b;
                        }
                    }
                    $slots[] = ['start' => $slot['start'], 'busy' => $hit !== null, 'block' => ($hit['kind'] ?? '') === 'block', 'label' => $hit === null ? '' : $label($hit)];
                }
                $hourly[] = ['id' => $r->id, 'name' => $r->name, 'slots' => $slots];
            } elseif ($r->typology === Typology::Daily) {
                $cells = [];
                foreach ($days as $d) {
                    $hit = null;
                    foreach ($mine as $b) {
                        if (ResourceRules::overlaps($d.' 00:00:00', date('Y-m-d', (int) strtotime($d.' +1 day')).' 00:00:00', $b['starts_at'], $b['ends_at'])) {
                            $hit = $b;
                        }
                    }
                    $cells[] = ['date' => $d, 'busy' => $hit !== null, 'block' => ($hit['kind'] ?? '') === 'block', 'label' => $hit === null ? '' : $label($hit)];
                }
                $daily[] = ['id' => $r->id, 'name' => $r->name, 'days' => $cells];
            } else {
                $holder = null;
                foreach ($mine as $b) {
                    if ($b['starts_at'] <= $date.' 23:59:59') {
                        $holder = $b;
                    }
                }
                $seasonal[] = ['id' => $r->id, 'name' => $r->name, 'busy' => $holder !== null, 'label' => $holder === null ? 'libera' : $label($holder),
                    'until' => $holder === null ? null : ($holder['ends_at'] === null ? 'senza scadenza' : 'fino al '.date('d/m/Y', (int) strtotime($holder['ends_at']) - 86400))];
            }
        }

        return Inertia::render('Risorse/Calendario', [
            'date' => $date,
            'previous' => date('Y-m-d', (int) strtotime($date.' -1 day')),
            'next' => date('Y-m-d', (int) strtotime($date.' +1 day')),
            'days' => array_map(static fn (string $d): array => ['date' => $d, 'label' => self::weekday($d).' '.date('j/n', (int) strtotime($d))], $days),
            'dayLabel' => self::weekday($date).' '.date('d/m/Y', (int) strtotime($date)),
            'hourly' => $hourly,
            'daily' => $daily,
            'seasonal' => $seasonal,
        ]);
    }

    public function store(Request $request, string $resource): RedirectResponse
    {
        $in = $this->booking($request);

        return ResourcesController::attempt(function () use ($resource, $in, $request): RedirectResponse {
            $ids = $this->reservations->book($resource, $in, self::userId($request));

            return back()->with('status', count($ids) > 1 ? count($ids).' prenotazioni registrate.' : 'Prenotazione registrata.');
        });
    }

    public function quote(Request $request, string $resource): JsonResponse
    {
        $in = $this->booking($request);
        try {
            return response()->json(['fee' => Money::format($this->reservations->quote($resource, $in))]);
        } catch (ResourceError $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function block(Request $request, string $resource): RedirectResponse
    {
        $data = Input::of($request->validate([
            'from' => ['required', 'string', 'max:20'],
            'to' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:255'],
        ]));

        return ResourcesController::attempt(function () use ($resource, $data, $request): RedirectResponse {
            $this->reservations->block($resource, Input::string($data, 'from'), Input::string($data, 'to'), Input::optionalString($data, 'note'), self::userId($request));

            return back()->with('status', 'Risorsa bloccata.');
        });
    }

    public function cancel(Request $request, string $reservation): RedirectResponse
    {
        $series = $request->boolean('series');

        return ResourcesController::attempt(function () use ($reservation, $series): RedirectResponse {
            $n = $this->reservations->cancel($reservation, $series);

            return back()->with('status', $n > 1 ? $n.' prenotazioni annullate.' : 'Prenotazione annullata.');
        });
    }

    public function collect(Request $request, string $reservation): RedirectResponse
    {
        $data = Input::of($request->validate([
            'account_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'string', 'max:20'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
        ]));
        $amount = ResourcesController::cents(Input::optionalString($data, 'amount'), 'amount');
        $method = Input::optionalString($data, 'method');

        try {
            $receipt = $this->reservations->collect($reservation, Input::string($data, 'account_id'), Input::string($data, 'date'), $amount === 0 ? null : $amount, $method === null ? null : PaymentMethod::from($method));
        } catch (ResourceError|LedgerError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', $receipt === null ? 'Incasso registrato in prima nota.' : "Incasso registrato: ricevuta n. {$receipt}.");
    }

    public function waitlistJoin(Request $request, string $resource): RedirectResponse
    {
        $data = Input::of($request->validate(['person_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:255']]));

        return ResourcesController::attempt(function () use ($resource, $data): RedirectResponse {
            $this->reservations->waitlistJoin($resource, Input::string($data, 'person_id'), Input::optionalString($data, 'note'));

            return back()->with('status', 'Aggiunto alla lista d\'attesa.');
        });
    }

    public function waitlistLeave(string $resource, string $person): RedirectResponse
    {
        return ResourcesController::attempt(function () use ($resource, $person): RedirectResponse {
            $this->reservations->waitlistLeave($resource, $person);

            return back()->with('status', 'Tolto dalla lista d\'attesa.');
        });
    }

    /** @return array{person_id: string|null, holder_name: string|null, date: string, start: string, units: int, weeks: int, to: string|null, guests: list<string>, services: list<string>, note: string|null} */
    private function booking(Request $request): array
    {
        $data = Input::of($request->validate([
            'person_id' => ['nullable', 'uuid'],
            'holder_name' => ['nullable', 'string', 'max:160'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['nullable', 'date_format:H:i'],
            'units' => ['nullable', 'integer', 'between:1,48'],
            'weeks' => ['nullable', 'integer', 'between:1,'.ReservationService::MAX_WEEKS],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'guests' => ['nullable', 'string', 'max:2000'],
            'services' => ['array', 'max:20'],
            'services.*' => ['string', 'max:80'],
            'note' => ['nullable', 'string', 'max:255'],
        ]));
        $guests = preg_split('/[\n,]/u', Input::optionalString($data, 'guests') ?? '') ?: [];

        return [
            'person_id' => Input::optionalString($data, 'person_id'),
            'holder_name' => Input::optionalString($data, 'holder_name'),
            'date' => Input::string($data, 'date'),
            'start' => Input::optionalString($data, 'start') ?? '',
            'units' => is_numeric($data['units'] ?? null) ? (int) $data['units'] : 1,
            'weeks' => is_numeric($data['weeks'] ?? null) ? (int) $data['weeks'] : 1,
            'to' => Input::optionalString($data, 'to'),
            'guests' => array_values(array_filter(array_map('trim', $guests), static fn (string $g): bool => $g !== '')),
            'services' => array_values(array_filter(is_array($data['services'] ?? null) ? $data['services'] : [], is_string(...))),
            'note' => Input::optionalString($data, 'note'),
        ];
    }

    /** @param  array{kind: string, note: string|null, who: string|null}  $b */
    private static function label(array $b): string
    {
        return $b['kind'] === 'block' ? 'bloccata'.($b['note'] !== null ? ': '.$b['note'] : '') : (string) $b['who'];
    }

    private static function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }

    private static function weekday(string $date): string
    {
        return ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'][(int) date('N', (int) strtotime($date)) - 1];
    }
}
