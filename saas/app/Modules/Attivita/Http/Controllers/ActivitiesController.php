<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Http\Controllers;

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\ActivityKind;
use App\Modules\Attivita\Contracts\Billing;
use App\Modules\Attivita\Contracts\ResourceChangeConflict;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Rules\PaymentCalc;
use App\Modules\Attivita\Rules\SessionRules;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\Input;
use App\Modules\Risorse\Contracts\ResourceBookings;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Attività nel gestionale: elenco con il bilancio di ciascuna, scheda con iscritti, contributi e movimenti. */
final readonly class ActivitiesController
{
    public function __construct(
        private ActivityService $activities,
        private SessionService $sessions,
        private Ledger $ledger,
        private ResourceBookings $resourceList,
        private DatabaseManager $db,
    ) {}

    public function index(Request $request): Response
    {
        $years = $this->activities->years();
        $year = $request->string('anno')->toString();
        $year = in_array($year, $years, true) ? $year : $years[0];
        $rows = $this->activities->forYear($year);

        return Inertia::render('Attivita/Elenco', [
            'year' => $year,
            'years' => $years,
            'rows' => array_map(static fn (array $r): array => $r + [
                'income_label' => Money::format($r['income']),
                'expense_label' => Money::format(-$r['expense']),
                'balance_label' => Money::format($r['income'] - $r['expense']),
                'negative' => $r['income'] < $r['expense'],
            ], $rows),
            'totals' => [
                'income' => Money::format((int) array_sum(array_column($rows, 'income'))),
                'expense' => Money::format(-(int) array_sum(array_column($rows, 'expense'))),
                'balance' => Money::format((int) array_sum(array_column($rows, 'income')) - (int) array_sum(array_column($rows, 'expense'))),
            ],
            'canEdit' => $request->user()?->can('people.edit') ?? false,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, $request);
    }

    public function show(Request $request, string $activity): Response
    {
        try {
            return $this->form($this->activities->find($activity), $request);
        } catch (ActivityError) {
            abort(404);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->attempt(function () use ($data): RedirectResponse {
            $a = $this->activities->save(null, $data);

            return redirect()->route('activities.show', $a->id)->with('status', 'Attività creata.');
        });
    }

    public function update(Request $request, string $activity): RedirectResponse
    {
        $data = $this->validated($request);

        $move = $request->boolean('move_conflicts');

        try {
            return $this->attempt(fn (): RedirectResponse => $this->done(fn () => $this->activities->save($activity, $data, $move), 'Attività salvata.'));
        } catch (ResourceChangeConflict $e) {
            // Il modulo mostra l'elenco e propone: lasciare tutto com'è, oppure (se si può) spostare gli slot occupati sulla risorsa che si libera.
            throw ValidationException::withMessages(['resource' => $e->getMessage(), 'resource_movable' => $e->movable ? '1' : '0']);
        }
    }

    public function archive(Request $request, string $activity): RedirectResponse
    {
        $archived = $request->boolean('archived');

        return $this->attempt(fn (): RedirectResponse => $this->done(fn () => $this->activities->archive($activity, $archived), $archived ? 'Attività archiviata.' : 'Attività riaperta.'));
    }

    public function enroll(Request $request, string $activity): RedirectResponse
    {
        $data = Input::of($request->validate([
            'person_id' => ['required', 'uuid'],
            'start_month' => ['nullable', 'date_format:Y-m'],
        ]));

        return $this->attempt(fn (): RedirectResponse => $this->done(fn () => $this->activities->enroll($activity, Input::string($data, 'person_id'), Input::optionalString($data, 'start_month')), 'Iscrizione registrata.'));
    }

    public function unenroll(Request $request, string $activity, string $person): RedirectResponse
    {
        $data = Input::of($request->validate(['last_month' => ['nullable', 'date_format:Y-m']]));

        return $this->attempt(fn (): RedirectResponse => $this->done(fn () => $this->activities->unenroll($activity, $person, Input::optionalString($data, 'last_month')), 'Iscrizione chiusa.'));
    }

    public function collect(Request $request, string $activity): RedirectResponse
    {
        $data = Input::of($request->validate([
            'person_id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'months' => ['array', 'max:12'],
            'months.*' => ['date_format:Y-m'],
            'amount' => ['nullable', 'string', 'max:20'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
        ]));
        $amount = self::cents(Input::optionalString($data, 'amount'), true);
        $method = Input::optionalString($data, 'method');
        $months = array_values(array_filter(is_array($data['months'] ?? null) ? $data['months'] : [], is_string(...)));

        return $this->attempt(function () use ($activity, $data, $amount, $method, $months): RedirectResponse {
            $receipt = $this->activities->collect($activity, Input::string($data, 'person_id'), Input::string($data, 'account_id'), Input::string($data, 'date'), $months, $amount, $method === null ? null : PaymentMethod::from($method));

            return back()->with('status', $receipt === null ? 'Contributo incassato.' : "Contributo incassato: ricevuta n. {$receipt}.");
        });
    }

    public function expense(Request $request, string $activity): RedirectResponse
    {
        $data = Input::of($request->validate([
            'account_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
            'document_ref' => ['nullable', 'string', 'max:80'],
        ]));
        $amount = (int) self::cents(Input::string($data, 'amount'), false);
        $method = Input::optionalString($data, 'method');

        return $this->attempt(fn (): RedirectResponse => $this->done(fn () => $this->activities->expense(
            $activity, Input::string($data, 'account_id'), Input::string($data, 'date'), $amount, Input::optionalString($data, 'description') ?? '', null,
            $method === null ? null : PaymentMethod::from($method), Input::optionalString($data, 'document_ref'),
        ), 'Spesa registrata.'));
    }

    private function form(?Activity $activity, Request $request): Response
    {
        $year = $activity === null ? null : $this->activities->year($activity);
        $instructor = $activity?->instructor_person_id === null ? null
            : $this->db->connection($activity->getConnectionName())->table('app.people')->where('id', $activity->instructor_person_id)->first(['first_name', 'last_name']);
        $status = $activity === null ? [] : $this->activities->status($activity);
        $movements = $activity === null ? [] : $this->ledger->activityMovements($activity->id);
        $names = [];
        foreach ($status as $s) {
            $names[$s['person_id']] = $s['name'];
        }
        $totals = $activity === null ? ['income' => 0, 'expense' => 0] : ($this->ledger->activityTotals([$activity->id])[$activity->id] ?? ['income' => 0, 'expense' => 0]);

        return Inertia::render('Attivita/Scheda', [
            'activity' => $activity === null ? null : [
                'id' => $activity->id,
                'name' => $activity->name,
                'kind' => $activity->kind->value,
                'social_year' => $activity->social_year,
                'billing' => $activity->billing->value,
                'fee' => Money::plain($activity->fee_cents),
                'guest_fee' => $activity->guest_fee_cents === null ? '' : Money::plain($activity->guest_fee_cents),
                'instructor_person_id' => $activity->instructor_person_id,
                'instructor_name' => $instructor === null ? null : trim((is_string($instructor->last_name ?? null) ? $instructor->last_name : '').' '.(is_string($instructor->first_name ?? null) ? $instructor->first_name : '')),
                'weekdays' => $activity->weekdays,
                'starts_on' => $activity->starts_on?->format('Y-m-d'),
                'ends_on' => $activity->ends_on?->format('Y-m-d'),
                'location' => $activity->location,
                'notes' => $activity->notes,
                'archived' => $activity->archived_at !== null,
                'bookable' => $activity->kind->usesSessions(),
                'price_lists' => SessionRules::priceListsText($activity->price_lists),
                'resource' => $activity->no_resource ? 'none' : ($activity->resource_id ?? ''),
                'occupy_on_booking' => $activity->occupy_on_booking,
                'sessions' => $activity->kind->usesSessions() ? array_map(static fn (array $x): array => $x + ['label' => SessionsController::dayLabel($x['date'])], $this->sessions->sessions($activity)) : [],
                'packages' => $activity->kind->usesSessions() ? $this->sessions->paidPackages($activity->id) : [],
                'monthly' => $activity->kind === ActivityKind::Course && $activity->billing !== Billing::Once,
                'months' => array_map(static fn (string $m): array => ['value' => $m, 'label' => ActivityService::monthLabel($m)], $year['months'] ?? []),
                'totals' => [
                    'income' => Money::format($totals['income']),
                    'expense' => Money::format(-$totals['expense']),
                    'balance' => Money::format($totals['income'] - $totals['expense']),
                    'negative' => $totals['income'] < $totals['expense'],
                ],
                'enrollments' => array_map(static fn (array $s): array => [
                    'person_id' => $s['person_id'],
                    'name' => $s['name'],
                    'type' => $s['type'],
                    'from' => ActivityService::monthLabel($s['start_month']),
                    'to' => $s['end_month'] === null ? null : ActivityService::monthLabel($s['end_month']),
                    'active' => $s['active'],
                    'fee' => Money::plain($s['fee']),
                    'due' => Money::format($s['summary']['total_due']),
                    'paid' => Money::format($s['summary']['total_paid']),
                    'balance' => $s['summary']['balance'],
                    'balance_label' => Money::format($s['summary']['balance']),
                    'regular' => $s['summary']['regular'],
                    'unpaid' => array_map(static fn (array $m): string => $m['month'], $s['summary']['unpaid_months']),
                    'paid_months' => array_values(array_map(static fn (array $m): string => $m['month'], array_filter($s['summary']['months'], static fn (array $m): bool => in_array($m['state'], [PaymentCalc::PAID, PaymentCalc::ADVANCE], true)))),
                ], $status),
                'movements' => array_map(static fn (array $m): array => [
                    'id' => $m['id'],
                    'date' => date('d/m/Y', (int) strtotime($m['date'])),
                    'amount' => Money::format($m['type'] === 'expense' ? -$m['cents'] : $m['cents']),
                    'income' => $m['type'] === 'income',
                    'category' => $m['category'],
                    'person' => $m['person_id'] === null ? null : ($names[$m['person_id']] ?? null),
                    'description' => $m['description'],
                    'receipt' => $m['receipt'],
                    'receipt_id' => $m['receipt_id'],
                    'voided' => $m['voided'],
                ], $movements),
            ],
            'resources' => $this->resourceList->options(),
            'kinds' => array_map(static fn (ActivityKind $k): array => ['value' => $k->value, 'label' => $k->label()], ActivityKind::cases()),
            'billings' => array_map(static fn (Billing $b): array => ['value' => $b->value, 'label' => $b->label()], Billing::cases()),
            'years' => $this->activities->years(),
            'accounts' => $this->ledger->openAccounts(),
            'methods' => array_map(static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
            'canEdit' => $request->user()?->can('people.edit') ?? false,
            'canAccounting' => $request->user()?->can('accounting.edit') ?? false,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = Input::of($request->validate([
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::enum(ActivityKind::class)],
            'social_year' => ['required', 'regex:/^\d{4}(\/\d{4})?$/'],
            'billing' => ['nullable', Rule::enum(Billing::class)],
            'fee' => ['nullable', 'string', 'max:20'],
            'guest_fee' => ['nullable', 'string', 'max:20'],
            'instructor_person_id' => ['nullable', 'uuid'],
            'weekdays' => ['array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'location' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'price_lists' => ['nullable', 'string', 'max:200000'],
            'resource' => ['required', 'string', 'max:40'],
            'occupy_on_booking' => ['boolean'],
        ]));
        $resource = Input::string($data, 'resource');
        if ($resource !== 'none' && ! Str::isUuid($resource)) {
            throw ValidationException::withMessages(['resource' => 'Scegli dove si svolge: una risorsa, oppure «Nessuna (fuori sede)».']);
        }
        try {
            $lists = SessionRules::parsePriceLists(Input::optionalString($data, 'price_lists') ?? '');
        } catch (ActivityError $e) {
            throw ValidationException::withMessages(['price_lists' => $e->getMessage()]);
        }

        return [
            'name' => trim(Input::string($data, 'name')),
            'kind' => Input::string($data, 'kind'),
            'social_year' => Input::string($data, 'social_year'),
            'billing' => Input::optionalString($data, 'billing') ?? Billing::Monthly->value,
            'fee_cents' => self::cents(Input::optionalString($data, 'fee'), true, 'fee') ?? 0,
            'guest_fee_cents' => self::cents(Input::optionalString($data, 'guest_fee'), true, 'guest_fee'),
            'instructor_person_id' => Input::optionalString($data, 'instructor_person_id'),
            'weekdays' => array_values(array_map('intval', array_filter(is_array($data['weekdays'] ?? null) ? $data['weekdays'] : [], is_numeric(...)))),
            'starts_on' => Input::optionalString($data, 'starts_on'),
            'ends_on' => Input::optionalString($data, 'ends_on'),
            'location' => Input::optionalString($data, 'location'),
            'notes' => Input::optionalString($data, 'notes'),
            'price_lists' => $lists,
            'resource_id' => $resource === 'none' ? null : $resource,
            'no_resource' => $resource === 'none',
            'occupy_on_booking' => (bool) ($data['occupy_on_booking'] ?? false),
        ];
    }

    private static function cents(?string $value, bool $optional, string $field = 'amount'): ?int
    {
        if ($value === null || trim($value) === '') {
            if ($optional) {
                return null;
            }
            throw ValidationException::withMessages([$field => 'Indica l\'importo.']);
        }
        $cents = Money::parse($value);
        if ($cents === null || $cents < 0 || (! $optional && $cents === 0)) {
            throw ValidationException::withMessages([$field => 'Importo non valido.']);
        }

        return $cents;
    }

    /** @param  Closure(): mixed  $action */
    private function done(Closure $action, string $message): RedirectResponse
    {
        $action();

        return back()->with('status', $message);
    }

    /** @param  Closure(): RedirectResponse  $action */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (ActivityError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        } catch (LedgerError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }
    }
}
