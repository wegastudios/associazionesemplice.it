<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use App\Modules\Attivita\Contracts\Activities;
use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\ActivityKind;
use App\Modules\Attivita\Contracts\ActivityOccupancy;
use App\Modules\Attivita\Contracts\Billing;
use App\Modules\Attivita\Contracts\ResourceChangeConflict;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Models\ActivitySession;
use App\Modules\Attivita\Models\Enrollment;
use App\Modules\Attivita\Models\SessionBooking;
use App\Modules\Attivita\Rules\ActivityRules;
use App\Modules\Attivita\Rules\PaymentCalc;
use App\Modules\Attivita\Rules\Pricing;
use App\Modules\Attivita\Rules\SessionRules;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Expense;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Risorse\Contracts\ResourceBookings;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Soci\Contracts\Members;
use App\Modules\Soci\Contracts\MemberType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Attività come centri di costo (dall'ActivityService del plugin): corsi ed eventi, iscrizioni registrate dalla
 * segreteria, situazione dei contributi, incassi e spese legati all'attività in prima nota.
 *
 * @phpstan-import-type Summary from PaymentCalc
 */
final readonly class ActivityService implements Activities
{
    private const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private Ledger $ledger,
        private Members $members,
        private ResourceBookings $resources,
        private ActivityOccupancy $occupancy,
    ) {}

    public function options(): array
    {
        return array_values(Activity::query()->whereNull('archived_at')->orderByDesc('social_year')->orderBy('name')->get(['id', 'name', 'social_year'])
            ->map(static fn (Activity $a): array => ['id' => $a->id, 'name' => $a->name.' ('.$a->social_year.')'])->all());
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Activity::query()->whereIn('id', $ids)->get(['id', 'name'])->mapWithKeys(static fn (Activity $a): array => [$a->id => $a->name])->all();
    }

    /**
     * Attività di un anno sociale con iscritti ed entrate e uscite.
     *
     * @return list<array{id: string, name: string, kind: string, archived: bool, participants: int, income: int, expense: int}>
     */
    public function forYear(string $label): array
    {
        $activities = Activity::query()->where('social_year', $label)->orderByRaw('archived_at IS NOT NULL')->orderBy('name')->get();
        $ids = array_values($activities->map(static fn (Activity $a): string => $a->id)->all());
        $totals = $this->ledger->activityTotals($ids);
        $participants = [];
        foreach ($this->connection()->table('app.enrollments')->whereIn('activity_id', $ids)->whereNull('end_month')
            ->groupBy('activity_id')->selectRaw('activity_id, count(*) AS n')->get() as $r) {
            if (is_string($r->activity_id ?? null)) {
                $participants[$r->activity_id] = is_numeric($r->n ?? null) ? (int) $r->n : 0;
            }
        }
        // Eventi con prenotazioni: chi ha almeno una prenotazione attiva.
        foreach ($this->connection()->table('app.session_bookings as b')->join('app.activity_sessions as s', 's.id', '=', 'b.session_id')
            ->whereIn('s.activity_id', $ids)->where('b.status', 'booked')->whereNull('s.cancelled_at')
            ->groupBy('s.activity_id')->selectRaw('s.activity_id, count(DISTINCT b.person_id) AS n')->get() as $r) {
            if (is_string($r->activity_id ?? null)) {
                $participants[$r->activity_id] = is_numeric($r->n ?? null) ? (int) $r->n : 0;
            }
        }

        return array_values($activities->map(static fn (Activity $a): array => [
            'id' => $a->id,
            'name' => $a->name,
            'kind' => $a->kind->label(),
            'archived' => $a->archived_at !== null,
            'participants' => $participants[$a->id] ?? 0,
            'income' => $totals[$a->id]['income'] ?? 0,
            'expense' => $totals[$a->id]['expense'] ?? 0,
        ])->all());
    }

    /** @return list<string> anni sociali con attività, più quello in corso */
    public function years(): array
    {
        $years = array_values(array_filter(Activity::query()->distinct()->pluck('social_year')->all(), is_string(...)));
        $years[] = $this->members->socialYearLabel();
        $years = array_values(array_unique($years));
        rsort($years);

        return $years;
    }

    public function find(string $id): Activity
    {
        $activity = Str::isUuid($id) ? Activity::query()->find($id) : null;

        return $activity ?? throw new ActivityError('Attività non trovata.');
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ResourceChangeConflict se cambiando la risorsa alcune date future si sovrappongono (e $moveConflicts è falso)
     */
    public function save(?string $id, array $data, bool $moveConflicts = false): Activity
    {
        $activity = $id === null ? new Activity : $this->find($id);
        $data['social_year'] = is_string($data['social_year'] ?? null) ? $data['social_year'] : $this->members->socialYearLabel();
        if ($this->members->socialYearInfo($data['social_year']) === null) {
            throw new ActivityError('Anno sociale non valido.');
        }
        if (in_array($data['kind'] ?? null, [ActivityKind::Event->value, ActivityKind::Recurring->value], true)) {
            $data['billing'] = Billing::Once->value;
        }
        $instructor = is_string($data['instructor_person_id'] ?? null) ? $this->person($data['instructor_person_id']) : null;
        $errors = ActivityRules::validate($data, $instructor === null ? null : MemberType::from($instructor['type']));
        if (is_string($data['instructor_person_id'] ?? null) && $instructor === null) {
            $errors[] = 'Referente non trovato.';
        }
        if ($errors !== []) {
            throw new ActivityError(implode(' ', $errors));
        }
        $resourceId = null;
        if (array_key_exists('resource_id', $data) || array_key_exists('no_resource', $data)) {
            $resourceId = is_string($data['resource_id'] ?? null) && $data['resource_id'] !== '' ? $data['resource_id'] : null;
            $data['resource_id'] = $resourceId;
            $data['no_resource'] = $resourceId === null && ($data['no_resource'] ?? false) === true;
            if ($resourceId !== null && ! isset($this->resources->names([$resourceId])[$resourceId])) {
                throw new ActivityError('Risorsa non trovata.');
            }
        }
        $activity->fill(array_intersect_key($data, array_flip($activity->getFillable())));

        return $this->connection()->transaction(function () use ($activity, $resourceId, $moveConflicts, $id): Activity {
            $plan = null;
            if ($id !== null && $resourceId !== null && $activity->isDirty('resource_id')) {
                $plan = $this->settleResourceChange($activity, $activity->getOriginal('resource_id'), $resourceId, $moveConflicts);
            } elseif ($resourceId !== null && ($id === null || $activity->isDirty(['kind', 'weekdays', 'starts_on', 'ends_on']))) {
                $this->assertCourseFree($activity);
            }
            $activity->save();
            if ($plan !== null) {
                // Prima l'attività lascia la risorsa di prima, poi ci passano gli elementi in conflitto: tutto o niente.
                $this->swapOntoOldResource($plan);
            }

            return $activity;
        });
    }

    /**
     * Gli intervalli futuri in cui l'attività occupa la sua risorsa: le sue date (senza orari = giorno intero; con «occupa
     * solo se prenotata» solo quelle prenotate) e, per un corso, i giorni delle lezioni (giorno intero).
     *
     * @return list<array{0: string, 1: string, 2: string}> inizio, fine e come si legge nei messaggi
     */
    private function occupiedSlots(Activity $activity): array
    {
        $today = now()->toDateString();
        $out = [];
        if ($activity->id !== null) {
            $sessions = ActivitySession::query()->where('activity_id', $activity->id)->whereNull('cancelled_at')->where('session_date', '>=', $today)
                ->orderBy('session_date')->orderBy('start_time')->get();
            foreach ($sessions as $s) {
                if ($activity->occupy_on_booking && ! SessionBooking::query()->where('session_id', $s->id)->where('status', 'booked')->exists()) {
                    continue;
                }
                $day = $s->session_date->format('Y-m-d');
                [$from, $to] = SessionRules::slot($day, $s->start_time, $s->end_time);
                $out[] = [$from, $to, date('d/m/Y', (int) strtotime($day)).($s->start_time === null ? ' (giorno intero)' : ' '.$s->start_time)];
            }
        }
        if ($activity->kind === ActivityKind::Course) {
            foreach (SessionRules::courseDays($activity->weekdays, $activity->starts_on?->format('Y-m-d'), $activity->ends_on?->format('Y-m-d'), $today) as $day) {
                [$from, $to] = SessionRules::slot($day, null, null);
                $out[] = [$from, $to, date('d/m/Y', (int) strtotime($day)).' (giorno intero)'];
            }
        }

        return $out;
    }

    /** Un corso senza orari occupa la risorsa nei giorni delle sue lezioni: si rifiuta se è già occupata. */
    private function assertCourseFree(Activity $activity): void
    {
        if ($activity->kind !== ActivityKind::Course || $activity->resource_id === null) {
            return;
        }
        $found = [];
        foreach ($this->occupiedSlots($activity) as [$from, $to, $when]) {
            $busy = $this->resources->busy($activity->resource_id, $from, $to)[0]['label'] ?? null;
            $busy ??= ($o = $this->occupancy->busy($activity->resource_id, $from, $to, null, $activity->id)[0] ?? null) === null ? null : 'l\'attività «'.$o['label'].'»';
            if ($busy !== null) {
                $found[] = $when.': c\'è '.$busy;
            }
            if (count($found) >= 5) {
                break;
            }
        }
        if ($found !== []) {
            throw new ActivityError('La risorsa è già occupata nei giorni delle lezioni: '.implode('; ', $found).'.');
        }
    }

    /**
     * Cambiando risorsa si ricontrollano le date future (e i giorni delle lezioni di un corso): se sulla nuova risorsa c'è
     * già qualcosa si segnala (ResourceChangeConflict). Con $move si prepara lo scambio: le prenotazioni dei soci in
     * conflitto e le altre attività (per intero, la risorsa è per attività) passano sulla risorsa che si libera. I blocchi
     * di risorsa non si spostano. Si guarda e si sposta solo il futuro: le prenotazioni passate restano dove sono.
     *
     * @return array{old: string, reservations: array<string, array{0: string, 1: string}>, activities: list<string>}|null
     */
    private function settleResourceChange(Activity $activity, mixed $oldId, string $newId, bool $move): ?array
    {
        $old = is_string($oldId) && $oldId !== '' ? $oldId : null;
        $texts = [];
        $reservations = [];
        $activities = [];
        $blocked = false;
        foreach ($this->occupiedSlots($activity) as [$from, $to, $when]) {
            foreach ($this->resources->busy($newId, $from, $to) as $r) {
                $texts[] = $when.': c\'è '.$r['label'];
                if ($r['kind'] === 'block') {
                    $blocked = true;
                } else {
                    $reservations[$r['id']] = [$r['from'], $r['to'] ?? '9999-12-31 00:00:00'];
                }
            }
            foreach ($this->occupancy->busy($newId, $from, $to) as $o) {
                $texts[] = $when.': c\'è l\'attività «'.$o['label'].'»';
                $activities[$o['activity_id']] = $o['activity_id'];
            }
        }
        if ($texts === []) {
            return null;
        }
        if (! $move) {
            throw new ResourceChangeConflict($texts, ! $blocked && $old !== null);
        }
        if ($blocked || $old === null) {
            throw new ActivityError('Non si possono spostare: '.implode('; ', $texts).'.');
        }

        return ['old' => $old, 'reservations' => $reservations, 'activities' => array_values($activities)];
    }

    /**
     * Sposta sulla risorsa di prima le prenotazioni e le attività in conflitto (già ricontrollate sulla nuova risorsa).
     * Se anche lì c'è un conflitto, lancia: la transazione annulla tutto.
     *
     * @param  array{old: string, reservations: array<string, array{0: string, 1: string}>, activities: list<string>}  $plan
     */
    private function swapOntoOldResource(array $plan): void
    {
        $old = $plan['old'];
        foreach ($plan['reservations'] as [$from, $to]) {
            $other = $this->occupancy->busy($old, $from, $to)[0]['label'] ?? null;
            if ($other !== null) {
                throw new ActivityError('Sulla risorsa di prima c\'è già l\'attività «'.$other.'»: non si sposta niente.');
            }
        }
        try {
            $this->resources->move(array_keys($plan['reservations']), $old);
        } catch (ResourceError $e) {
            throw new ActivityError($e->getMessage());
        }
        foreach ($plan['activities'] as $activityId) {
            $moved = $this->find($activityId);
            foreach ($this->occupiedSlots($moved) as [$from, $to, $when]) {
                $busy = $this->resources->busy($old, $from, $to)[0]['label'] ?? null;
                $busy ??= ($o = $this->occupancy->busy($old, $from, $to, null, $moved->id)[0] ?? null) === null ? null : 'l\'attività «'.$o['label'].'»';
                if ($busy !== null) {
                    throw new ActivityError('«'.$moved->name.'» non si sposta: '.$when.' sulla risorsa di prima c\'è già '.$busy.'. Non si sposta niente.');
                }
            }
            $moved->resource_id = $old;
            $moved->no_resource = false;
            $moved->save();
        }
    }

    public function archive(string $id, bool $archived): void
    {
        $activity = $this->find($id);
        $activity->archived_at = $archived ? now()->toImmutable() : null;
        $activity->save();
    }

    /** Iscrive (o riattiva): per un corso le quote sono dovute da $startMonth, per un evento dalla sua data. */
    public function enroll(string $activityId, string $personId, ?string $startMonth = null): Enrollment
    {
        $activity = $this->find($activityId);
        if ($activity->archived_at !== null) {
            throw new ActivityError('L\'attività è archiviata.');
        }
        if ($activity->kind->usesSessions()) {
            throw new ActivityError('A un evento con prenotazioni ci si prenota alle singole date.');
        }
        $person = $this->person($personId) ?? throw new ActivityError('Persona non trovata.');
        if ($person['type'] === MemberType::Guardian->value) {
            throw new ActivityError('Un genitore o tutore non socio non partecipa alle attività: iscrivi il minore.');
        }
        $year = $this->year($activity);
        $start = $activity->kind === ActivityKind::Event
            ? ($activity->firstMonth() ?? $year['months'][0])
            : ($startMonth ?? $this->defaultStart($activity, $year['months']));
        if (! in_array($start, $year['months'], true)) {
            throw new ActivityError('Il mese di inizio non è nell\'anno sociale '.$activity->social_year.'.');
        }

        $enrollment = Enrollment::query()->where('activity_id', $activity->id)->where('person_id', $person['id'])->first();
        if ($enrollment !== null && $enrollment->end_month === null) {
            throw new ActivityError($person['name'].' è già iscritto.');
        }
        $enrollment ??= new Enrollment(['activity_id' => $activity->id, 'person_id' => $person['id']]);
        $enrollment->fill(['start_month' => $start, 'end_month' => null])->save();

        return $enrollment;
    }

    /** Chiude l'iscrizione: per un corso $lastMonth è l'ultimo mese dovuto. I pagamenti restano registrati. */
    public function unenroll(string $activityId, string $personId, ?string $lastMonth = null): void
    {
        $activity = $this->find($activityId);
        $enrollment = Enrollment::query()->where('activity_id', $activity->id)->where('person_id', $personId)->whereNull('end_month')->first()
            ?? throw new ActivityError('Iscrizione non trovata.');
        $last = $activity->kind === ActivityKind::Event ? $enrollment->start_month : ($lastMonth ?? now()->format('Y-m'));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $last) !== 1) {
            throw new ActivityError('Mese non valido.');
        }
        $enrollment->end_month = max($last, $enrollment->start_month);
        $enrollment->save();
    }

    /**
     * Iscritti con la situazione dei contributi, attivi per primi.
     *
     * @return list<array{person_id: string, name: string, type: string, start_month: string, end_month: string|null, active: bool, fee: int, summary: Summary}>
     */
    public function status(Activity $activity): array
    {
        $enrollments = Enrollment::query()->where('activity_id', $activity->id)->get();
        $people = $this->people(array_values($enrollments->map(static fn (Enrollment $e): string => $e->person_id)->all()));
        $paid = $this->ledger->activityPayments($activity->id);
        $out = [];
        foreach ($enrollments as $e) {
            $p = $people[$e->person_id] ?? null;
            if ($p === null) {
                continue;
            }
            $fee = Pricing::feeFor($activity->fee_cents, $activity->guest_fee_cents, $p['type'] === MemberType::Guest->value);
            $out[] = [
                'person_id' => $e->person_id,
                'name' => $p['name'],
                'type' => MemberType::from($p['type'])->label(),
                'start_month' => $e->start_month,
                'end_month' => $e->end_month,
                'active' => $e->end_month === null,
                'fee' => $fee,
                'summary' => $this->summary($activity, $e, $fee, $paid[$e->person_id] ?? []),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['active'] ? 0 : 1, mb_strtolower($a['name'])] <=> [$b['active'] ? 0 : 1, mb_strtolower($b['name'])]);

        return $out;
    }

    /**
     * Incassa il contributo in prima nota (con ricevuta), legato all'attività e alla persona. Per un corso a mesi una
     * voce per mese; altrimenti una voce sola. Senza importo vale il contributo della persona (o quanto manca).
     *
     * @param  list<string>  $months
     * @return string|null il numero di ricevuta
     */
    public function collect(string $activityId, string $personId, string $accountId, string $date, array $months, ?int $amountCents = null, ?PaymentMethod $method = null): ?string
    {
        $activity = $this->find($activityId);
        $row = null;
        foreach ($this->status($activity) as $s) {
            if ($s['person_id'] === $personId) {
                $row = $s;
            }
        }
        if ($row === null) {
            throw new ActivityError('La persona non è iscritta a questa attività.');
        }
        $category = $this->ledger->categoryFor(CategoryKind::ActivityFee) ?? throw new ActivityError('Manca la categoria «Quota attività / corso» in prima nota.');

        $lines = [];
        if ($activity->kind === ActivityKind::Course && $activity->billing !== Billing::Once) {
            $year = $this->year($activity);
            $months = array_values(array_unique($months));
            if ($months === []) {
                throw new ActivityError('Scegli almeno un mese.');
            }
            foreach ($months as $m) {
                if (! in_array($m, $year['months'], true)) {
                    throw new ActivityError('Il mese '.$m.' non è nell\'anno sociale dell\'attività.');
                }
                $cents = $amountCents ?? $row['fee'];
                if ($cents <= 0) {
                    throw new ActivityError('Indica l\'importo.');
                }
                $lines[] = new IncomeLine($category, $cents, 'Contributo '.$activity->name.' '.self::monthLabel($m), $m);
            }
        } else {
            $cents = $amountCents ?? Pricing::remaining($row['summary']['total_due'] > 0 ? $row['summary']['total_due'] : $row['fee'], $row['summary']['total_paid']);
            if ($cents <= 0) {
                throw new ActivityError('Non c\'è niente da incassare: indica l\'importo.');
            }
            $lines[] = new IncomeLine($category, $cents, 'Contributo '.$activity->name, $row['start_month']);
        }

        return $this->ledger->recordIncome(new Income($date, $accountId, $lines, $method, $personId, true, null, $activity->id))['receipt'];
    }

    /** Spesa dell'attività in prima nota (categoria «Costo attività» se non indicata). */
    public function expense(string $activityId, string $accountId, string $date, int $cents, string $description, ?string $categoryId = null, ?PaymentMethod $method = null, ?string $documentRef = null): string
    {
        $activity = $this->find($activityId);
        $category = $categoryId ?? $this->ledger->categoryFor(CategoryKind::ActivityCost) ?? throw new ActivityError('Manca la categoria «Costo attività» in prima nota.');

        return $this->ledger->recordExpense(new Expense($date, $accountId, $category, $cents, $method, $description !== '' ? $description : $activity->name, null, $documentRef, null, $activity->id));
    }

    /** @return array{label: string, start: string, end: string, months: list<string>, next: string, previous: string} */
    public function year(Activity $activity): array
    {
        return $this->members->socialYearInfo($activity->social_year) ?? throw new ActivityError('Anno sociale non valido.');
    }

    public static function monthLabel(string $ym): string
    {
        return self::MONTHS[(int) substr($ym, 5, 2) - 1].' '.substr($ym, 0, 4);
    }

    /**
     * @param  array<string, int>  $paid
     * @return Summary
     */
    private function summary(Activity $activity, Enrollment $e, int $fee, array $paid): array
    {
        if ($activity->kind === ActivityKind::Event || $activity->billing === Billing::Once) {
            return PaymentCalc::computeOnce($fee, $e->start_month, $e->end_month, $paid);
        }
        $end = $e->end_month;
        if ($activity->ends_on !== null) { // il corso finisce: dopo quel mese non si paga più
            $last = $activity->ends_on->format('Y-m');
            $end = $end === null ? $last : min($end, $last);
        }

        return PaymentCalc::compute($fee, $e->start_month, $end, now()->format('Y-m'), $this->year($activity)['months'], $paid, $activity->weekdays, now()->toDateString(), $activity->billing->step());
    }

    /** @param  list<string>  $months */
    private function defaultStart(Activity $activity, array $months): string
    {
        $candidate = max(now()->format('Y-m'), $activity->firstMonth() ?? $months[0]);

        return in_array($candidate, $months, true) ? $candidate : $months[0];
    }

    /** @return array{id: string, name: string, type: string}|null */
    private function person(string $id): ?array
    {
        return Str::isUuid($id) ? ($this->people([$id])[$id] ?? null) : null;
    }

    /**
     * Nome e tipo (in chiaro nell'anagrafica) delle persone, senza aprire i dati cifrati.
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, name: string, type: string}>
     */
    private function people(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->connection()->table('app.people')->whereIn('id', $ids)->whereNull('deleted_at')->get(['id', 'first_name', 'last_name', 'type']) as $p) {
            if (is_string($p->id ?? null) && is_string($p->type ?? null)) {
                $out[$p->id] = ['id' => $p->id, 'name' => trim((is_string($p->last_name ?? null) ? $p->last_name : '').' '.(is_string($p->first_name ?? null) ? $p->first_name : '')), 'type' => $p->type];
            }
        }

        return $out;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
