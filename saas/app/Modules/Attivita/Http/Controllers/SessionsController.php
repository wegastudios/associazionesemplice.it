<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Http\Controllers;

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Rules\CalendarView;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\AttendanceService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\Input;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Date degli eventi con prenotazioni, prenotazioni, lista d'attesa, presenze e calendario delle attività. */
final readonly class SessionsController
{
    private const DAYS = ['lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica'];

    public function __construct(
        private ActivityService $activities,
        private SessionService $sessions,
        private AttendanceService $attendance,
        private Ledger $ledger,
    ) {}

    public function addDates(Request $request, string $activity): RedirectResponse
    {
        $data = Input::of($request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'weekly' => ['boolean'],
            'start' => ['nullable', 'string', 'max:5'],
            'end' => ['nullable', 'string', 'max:5'],
            'location' => ['nullable', 'string', 'max:190'],
            'capacity' => ['nullable', 'integer', 'between:1,100000'],
        ]));

        return self::attempt(function () use ($activity, $data): RedirectResponse {
            $n = $this->sessions->addDates($activity, Input::string($data, 'from'), Input::optionalString($data, 'to'), Input::bool($data, 'weekly', false),
                Input::optionalString($data, 'start'), Input::optionalString($data, 'end'), Input::optionalString($data, 'location'),
                is_numeric($data['capacity'] ?? null) ? (int) $data['capacity'] : null);

            return back()->with('status', $n === 0 ? 'Nessuna data nuova: c\'erano già.' : ($n === 1 ? '1 data aggiunta.' : $n.' date aggiunte.'));
        });
    }

    public function show(Request $request, string $session): Response
    {
        try {
            $s = $this->sessions->session($session);
            $a = $this->activities->find($s->activity_id);
        } catch (ActivityError) {
            abort(404);
        }
        $info = collect($this->sessions->sessions($a))->firstWhere('id', $s->id);

        return Inertia::render('Attivita/Data', [
            'activity' => ['id' => $a->id, 'name' => $a->name, 'archived' => $a->archived_at !== null],
            'session' => [
                'id' => $s->id, 'date' => $s->session_date->format('Y-m-d'), 'label' => self::dayLabel($s->session_date->format('Y-m-d')),
                'start' => $s->start_time, 'end' => $s->end_time, 'location' => $s->location, 'capacity' => $s->capacity,
                'taken' => $info['taken'] ?? 0, 'free' => $info['free'] ?? null, 'cancelled' => $s->cancelled_at !== null,
                'past' => $s->session_date->format('Y-m-d') < now()->toDateString(),
            ],
            'bookings' => array_map(static fn (array $b): array => array_merge($b, [
                'fee' => Money::format($b['fee']), 'paid' => Money::format($b['paid']), 'due' => max(0, $b['fee'] - $b['paid']),
                'due_label' => Money::format(max(0, $b['fee'] - $b['paid'])),
            ]), $this->sessions->bookings($s->id)),
            'waitlist' => $this->sessions->waitlist($s->id),
            'priceLists' => array_map(static fn (array $l): array => ['key' => $l['key'], 'label' => $l['label'].' ('.Money::format($l['cents']).($l['entries'] > 1 ? ', '.$l['entries'].' ingressi' : '').')'], $a->price_lists),
            'fee' => Money::format($a->fee_cents),
            'accounts' => $this->ledger->openAccounts(),
            'methods' => array_map(static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
            'today' => now()->toDateString(),
            'canEdit' => $request->user()?->can('people.edit') ?? false,
            'canAccounting' => $request->user()?->can('accounting.edit') ?? false,
        ]);
    }

    public function update(Request $request, string $session): RedirectResponse
    {
        $data = Input::of($request->validate([
            'start' => ['nullable', 'string', 'max:5'],
            'end' => ['nullable', 'string', 'max:5'],
            'location' => ['nullable', 'string', 'max:190'],
            'capacity' => ['nullable', 'integer', 'between:1,100000'],
        ]));

        return self::attempt(function () use ($session, $data): RedirectResponse {
            $in = $this->sessions->updateSession($session, Input::optionalString($data, 'start'), Input::optionalString($data, 'end'), Input::optionalString($data, 'location'),
                is_numeric($data['capacity'] ?? null) ? (int) $data['capacity'] : null);

            return back()->with('status', 'Data salvata.'.self::promoted($in));
        });
    }

    public function cancel(Request $request, string $session): RedirectResponse
    {
        $future = $request->boolean('future');

        return self::attempt(function () use ($session, $future): RedirectResponse {
            $n = $this->sessions->cancelSession($session, $future);

            return back()->with('status', $n > 1 ? $n.' date annullate.' : 'Data annullata.');
        });
    }

    public function book(Request $request, string $session): RedirectResponse
    {
        $data = Input::of($request->validate([
            'person_id' => ['required', 'uuid'],
            'price_list' => ['nullable', 'string', 'max:40'],
            'use_package' => ['boolean'],
        ]));
        $user = $request->user()?->getAuthIdentifier();

        return self::attempt(function () use ($session, $data, $user): RedirectResponse {
            $this->sessions->book($session, Input::string($data, 'person_id'), Input::optionalString($data, 'price_list'), Input::bool($data, 'use_package', false), is_string($user) ? $user : null);

            return back()->with('status', 'Prenotazione registrata.');
        });
    }

    public function cancelBooking(string $booking): RedirectResponse
    {
        return self::attempt(fn (): RedirectResponse => back()->with('status', 'Prenotazione annullata.'.self::promoted($this->sessions->cancelBooking($booking))));
    }

    public function collect(Request $request, string $booking): RedirectResponse
    {
        $data = Input::of($request->validate([
            'account_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'string', 'max:20'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
        ]));
        $amount = Input::optionalString($data, 'amount');
        $cents = $amount === null ? null : Money::parse($amount);
        if ($amount !== null && ($cents === null || $cents <= 0)) {
            throw ValidationException::withMessages(['amount' => 'Importo non valido.']);
        }
        $method = Input::optionalString($data, 'method');

        return self::attempt(function () use ($booking, $data, $cents, $method): RedirectResponse {
            $receipt = $this->sessions->collect($booking, Input::string($data, 'account_id'), Input::string($data, 'date'), $cents, $method === null ? null : PaymentMethod::from($method));

            return back()->with('status', $receipt === null ? 'Incasso registrato.' : "Incasso registrato: ricevuta n. {$receipt}.");
        });
    }

    public function waitlistJoin(Request $request, string $session): RedirectResponse
    {
        $data = Input::of($request->validate(['person_id' => ['required', 'uuid']]));

        return self::attempt(function () use ($session, $data): RedirectResponse {
            $this->sessions->waitlistJoin($session, Input::string($data, 'person_id'));

            return back()->with('status', 'Aggiunto alla lista d\'attesa.');
        });
    }

    public function waitlistLeave(string $session, string $person): RedirectResponse
    {
        return self::attempt(function () use ($session, $person): RedirectResponse {
            $this->sessions->waitlistLeave($session, $person);

            return back()->with('status', 'Tolto dalla lista d\'attesa.');
        });
    }

    /** Il registro di un giorno con il riepilogo del mese. */
    public function attendance(Request $request, string $activity): Response
    {
        try {
            $a = $this->activities->find($activity);
        } catch (ActivityError) {
            abort(404);
        }
        $today = now('Europe/Rome')->toDateString();
        $month = $request->string('mese')->toString();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : substr($today, 0, 7);
        $first = $month.'-01';
        $last = date('Y-m-t', (int) strtotime($first));
        $dates = $this->attendance->lessonDates($a, $first, $last);
        $day = $request->string('giorno')->toString();
        if (! in_array($day, $dates, true)) {
            $past = array_values(array_filter($dates, static fn (string $d): bool => $d <= $today));
            $day = $past !== [] ? $past[count($past) - 1] : ($dates[0] ?? null);
        }
        $marks = $day === null ? [] : $this->attendance->marks($a->id, $day);
        $summary = $this->attendance->summary($a->id, $first, $last);

        return Inertia::render('Attivita/Presenze', [
            'activity' => ['id' => $a->id, 'name' => $a->name],
            'month' => $month,
            'monthLabel' => ActivityService::monthLabel($month),
            'previous' => date('Y-m', (int) strtotime($first.' -1 month')),
            'next' => date('Y-m', (int) strtotime($first.' +1 month')),
            'dates' => array_map(static fn (string $d): array => ['value' => $d, 'label' => self::dayLabel($d), 'done' => in_array($d, $summary['dates'], true)], $dates),
            'day' => $day,
            'dayLabel' => $day === null ? null : self::dayLabel($day),
            'future' => $day !== null && $day > $today,
            'saved' => $marks !== [],
            'roster' => $day === null ? [] : array_map(static fn (array $p): array => $p + ['present' => $marks[$p['id']] ?? true], $this->attendance->roster($a, $day)),
            'summary' => [
                'dates' => array_map(static fn (string $d): array => ['value' => $d, 'label' => date('j', (int) strtotime($d))], $summary['dates']),
                'people' => $summary['people'],
            ],
            'canEdit' => $request->user()?->can('people.edit') ?? false,
        ]);
    }

    public function saveAttendance(Request $request, string $activity): RedirectResponse
    {
        $data = Input::of($request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'present' => ['array'],
            'present.*' => ['uuid'],
        ]));
        $user = $request->user()?->getAuthIdentifier();
        $present = array_values(array_filter(is_array($data['present'] ?? null) ? $data['present'] : [], is_string(...)));

        return self::attempt(function () use ($activity, $data, $present, $user): RedirectResponse {
            $n = $this->attendance->save($activity, Input::string($data, 'date'), $present, is_string($user) ? $user : null);

            return back()->with('status', 'Presenze registrate: '.count($present).' su '.$n.'.');
        });
    }

    /** Lezioni e date in vista giorno, settimana (da $dal) o mese. */
    public function calendar(Request $request): Response
    {
        $from = $request->string('dal')->toString();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 && strtotime($from) !== false ? $from : now('Europe/Rome')->toDateString();
        $view = CalendarView::normalize($request->string('vista')->toString());
        $range = CalendarView::range($view, $from);
        $items = $this->attendance->calendar($range['from'], $range['to']);
        $days = [];
        for ($d = $range['from']; $d <= $range['to']; $d = date('Y-m-d', (int) strtotime($d.' +1 day'))) {
            $days[] = ['date' => $d, 'label' => self::dayLabel($d), 'items' => array_values(array_filter($items, static fn (array $x): bool => $x['date'] === $d))];
        }

        return Inertia::render('Attivita/Calendario', [
            'view' => $view,
            'from' => $range['from'],
            'previous' => $range['previous'],
            'next' => $range['next'],
            'days' => $days,
        ]);
    }

    public static function dayLabel(string $date): string
    {
        $t = (int) strtotime($date);

        return self::DAYS[(int) date('N', $t) - 1].' '.date('j', $t).' '.explode(' ', ActivityService::monthLabel(date('Y-m', $t)))[0];
    }

    /** @param  list<string>  $names */
    private static function promoted(array $names): string
    {
        return $names === [] ? '' : ' Dalla lista d\'attesa: '.implode(', ', $names).'.';
    }

    /** @param  Closure(): RedirectResponse  $action */
    private static function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (ActivityError|LedgerError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }
    }
}
