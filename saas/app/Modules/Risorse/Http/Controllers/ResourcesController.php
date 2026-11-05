<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Http\Controllers;

use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\Input;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Contracts\Typology;
use App\Modules\Risorse\Models\Resource;
use App\Modules\Risorse\Rules\ResourceRules;
use App\Modules\Risorse\Services\ReservationService;
use App\Modules\Risorse\Services\ResourceService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Risorse nel gestionale: elenco con i limiti del pacchetto, scheda con impostazioni, prenotazioni e lista d'attesa. */
final readonly class ResourcesController
{
    public function __construct(
        private ResourceService $resources,
        private ReservationService $reservations,
        private Ledger $ledger,
    ) {}

    public function index(Request $request): Response
    {
        $archived = $request->boolean('archiviate');
        $list = $this->resources->all($archived);
        $now = ReservationService::now();
        $today = substr($now, 0, 10);
        $tomorrow = date('Y-m-d', (int) strtotime($today.' +1 day'));
        $busy = [];
        foreach ($this->reservations->between($today.' 00:00:00', $tomorrow.' 00:00:00') as $r) {
            if ($r['kind'] === 'booking') {
                $busy[$r['resource_id']][] = $r;
            }
        }

        return Inertia::render('Risorse/Elenco', [
            'archived' => $archived,
            'limits' => array_values(array_map(static fn (string $k, array $l): array => $l + ['value' => $k], array_keys($this->resources->limits()), $this->resources->limits())),
            'rows' => array_map(static function (Resource $r) use ($busy, $now): array {
                $today = $busy[$r->id] ?? [];
                $holder = null;
                foreach ($today as $b) {
                    if ($b['starts_at'] <= $now && ($b['ends_at'] === null || $b['ends_at'] > $now)) {
                        $holder = $b['who'];
                    }
                }

                return [
                    'id' => $r->id, 'name' => $r->name, 'category' => ResourceRules::categories()[$r->category] ?? $r->category,
                    'typology' => $r->typology->label(), 'fee' => Money::format($r->fee_cents).match ($r->typology) {
                        Typology::Hourly => ' a fascia', Typology::Daily => ' al giorno', Typology::Seasonal => ' a stagione',
                    },
                    'today' => $r->typology === Typology::Seasonal ? ($holder ?? 'libera') : (count($today) === 0 ? 'nessuna prenotazione oggi' : count($today).(count($today) === 1 ? ' prenotazione oggi' : ' prenotazioni oggi')),
                ];
            }, $list),
            'canEdit' => $request->user()?->can('people.edit') ?? false,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, $request);
    }

    public function show(Request $request, string $resource): Response
    {
        try {
            return $this->form($this->resources->find($resource), $request);
        } catch (ResourceError) {
            abort(404);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        return self::attempt(function () use ($data): RedirectResponse {
            $r = $this->resources->save(null, $data);

            return redirect()->route('resources.show', $r->id)->with('status', 'Risorsa creata.');
        });
    }

    public function update(Request $request, string $resource): RedirectResponse
    {
        $data = $this->validated($request);

        return self::attempt(function () use ($resource, $data): RedirectResponse {
            $this->resources->save($resource, $data);

            return back()->with('status', 'Risorsa salvata.');
        });
    }

    public function archive(Request $request, string $resource): RedirectResponse
    {
        $archived = $request->boolean('archived');

        return self::attempt(function () use ($resource, $archived): RedirectResponse {
            $this->resources->archive($resource, $archived);

            return back()->with('status', $archived ? 'Risorsa archiviata.' : 'Risorsa riattivata.');
        });
    }

    private function form(?Resource $r, Request $request): Response
    {
        $date = $request->string('giorno')->toString();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false ? $date : substr(ReservationService::now(), 0, 10);
        $grid = [];
        if ($r !== null && $r->typology === Typology::Hourly) {
            $day = $this->reservations->between($date.' 00:00:00', date('Y-m-d', (int) strtotime($date.' +1 day')).' 00:00:00', [$r->id]);
            foreach (ResourceRules::daySlots($r->rules()) as $slot) {
                $s = $date.' '.$slot['start'].':00';
                $e = $date.' '.$slot['end'].':00';
                $taken = null;
                foreach ($day as $b) {
                    if (ResourceRules::overlaps($s, $e, $b['starts_at'], $b['ends_at'])) {
                        $taken = $b;
                    }
                }
                $grid[] = ['start' => $slot['start'], 'end' => $slot['end'], 'busy' => $taken !== null,
                    'label' => $taken === null ? 'libera' : ($taken['kind'] === 'block' ? 'bloccata'.($taken['note'] !== null ? ': '.$taken['note'] : '') : (string) $taken['who']),
                    'price' => Money::format(ResourceRules::bandFee($r->rules(), $s))];
            }
        }

        return Inertia::render('Risorse/Scheda', [
            'resource' => $r === null ? null : [
                'id' => $r->id, 'name' => $r->name, 'category' => $r->category, 'typology' => $r->typology->value, 'description' => $r->description,
                'open_time' => $r->open_time, 'close_time' => $r->close_time, 'slot_minutes' => $r->slot_minutes, 'min_slots' => $r->min_slots,
                'max_slots' => $r->max_slots, 'advance_days' => $r->advance_days, 'fee' => Money::plain($r->fee_cents),
                'nonmember_fee' => $r->nonmember_fee_cents === null ? '' : Money::plain($r->nonmember_fee_cents),
                'guests_max' => $r->guests_max, 'guest_fee' => Money::plain($r->guest_fee_cents), 'rates' => ResourceRules::ratesText($r->rates),
                'services' => ResourceRules::servicesText($r->services), 'service_list' => array_map(static fn (array $s): array => ['name' => $s['name'], 'label' => $s['name'].' ('.Money::format($s['fee_cents']).')'], $r->services),
                'category_id' => $r->category_id, 'archived' => $r->archived_at !== null,
                'upcoming' => array_map(fn (array $b): array => $this->row($b, $r), $this->reservations->upcoming($r->id)),
                'waitlist' => $r->typology === Typology::Seasonal ? $this->reservations->waitlist($r->id) : [],
            ],
            'date' => $date,
            'grid' => $grid,
            'categories' => array_map(static fn (string $k, string $v): array => ['value' => $k, 'label' => $v], array_keys(ResourceRules::categories()), ResourceRules::categories()),
            'typologies' => array_map(static fn (Typology $t): array => ['value' => $t->value, 'label' => $t->label()], Typology::cases()),
            'incomeCategories' => $this->ledger->incomeCategories(),
            'accounts' => $this->ledger->openAccounts(),
            'methods' => array_map(static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
            'today' => substr(ReservationService::now(), 0, 10),
            'canEdit' => $request->user()?->can('people.edit') ?? false,
            'canAccounting' => $request->user()?->can('accounting.edit') ?? false,
        ]);
    }

    /**
     * @param  array{id: string, kind: string, starts_at: string, ends_at: string|null, who: string|null, member: bool, units: int, guests: list<string>, services: list<array{name: string, fee_cents: int}>, fee: int, paid: int, note: string|null, series: bool}  $b
     * @return array<string, mixed>
     */
    private function row(array $b, Resource $r): array
    {
        $s = (int) strtotime($b['starts_at']);
        $e = $b['ends_at'] === null ? null : (int) strtotime($b['ends_at']);
        $when = match ($r->typology) {
            Typology::Hourly => date('d/m/Y H:i', $s).'–'.($e === null ? '' : date('H:i', $e)),
            default => 'dal '.date('d/m/Y', $s).($e === null ? ' senza scadenza' : ' al '.date('d/m/Y', $e - 86400)),
        };

        return [
            'id' => $b['id'], 'block' => $b['kind'] === 'block', 'when' => $when, 'who' => $b['who'], 'member' => $b['member'],
            'guests' => implode(', ', $b['guests']), 'services' => implode(', ', array_column($b['services'], 'name')),
            'fee' => Money::format($b['fee']), 'paid' => Money::format($b['paid']), 'due' => max(0, $b['fee'] - $b['paid']),
            'due_label' => Money::format(max(0, $b['fee'] - $b['paid'])), 'note' => $b['note'], 'series' => $b['series'],
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = Input::of($request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(array_keys(ResourceRules::categories()))],
            'typology' => ['required', Rule::enum(Typology::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
            'slot_minutes' => ['nullable', 'integer', 'between:5,1440'],
            'min_slots' => ['nullable', 'integer', 'between:1,48'],
            'max_slots' => ['nullable', 'integer', 'between:1,48'],
            'advance_days' => ['nullable', 'integer', 'between:0,730'],
            'fee' => ['nullable', 'string', 'max:20'],
            'nonmember_fee' => ['nullable', 'string', 'max:20'],
            'guests_max' => ['nullable', 'integer', 'between:0,100'],
            'guest_fee' => ['nullable', 'string', 'max:20'],
            'rates' => ['nullable', 'string', 'max:2000'],
            'services' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'uuid'],
        ]));

        return [
            'name' => Input::string($data, 'name'),
            'category' => Input::string($data, 'category'),
            'typology' => Input::string($data, 'typology'),
            'description' => Input::optionalString($data, 'description'),
            'open_time' => Input::optionalString($data, 'open_time'),
            'close_time' => Input::optionalString($data, 'close_time'),
            'slot_minutes' => Input::raw($data, 'slot_minutes') ?? 60,
            'min_slots' => Input::raw($data, 'min_slots') ?? 1,
            'max_slots' => Input::raw($data, 'max_slots') ?? 1,
            'advance_days' => Input::raw($data, 'advance_days') ?? 0,
            'fee_cents' => self::cents(Input::optionalString($data, 'fee'), 'fee') ?? 0,
            'nonmember_fee_cents' => self::cents(Input::optionalString($data, 'nonmember_fee'), 'nonmember_fee'),
            'guests_max' => Input::raw($data, 'guests_max') ?? 0,
            'guest_fee_cents' => self::cents(Input::optionalString($data, 'guest_fee'), 'guest_fee') ?? 0,
            'rates' => Input::optionalString($data, 'rates') ?? '',
            'services' => Input::optionalString($data, 'services') ?? '',
            'category_id' => Input::optionalString($data, 'category_id'),
        ];
    }

    public static function cents(?string $value, string $field): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $cents = Money::parse($value);
        if ($cents === null || $cents < 0) {
            throw ValidationException::withMessages([$field => 'Importo non valido.']);
        }

        return $cents;
    }

    /** @param  Closure(): RedirectResponse  $action */
    public static function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (ResourceError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }
    }
}
