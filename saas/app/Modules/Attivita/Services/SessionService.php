<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\ActivityOccupancy;
use App\Modules\Attivita\Jobs\SendPromotionEmail;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Models\ActivitySession;
use App\Modules\Attivita\Models\SessionBooking;
use App\Modules\Attivita\Rules\Pricing;
use App\Modules\Attivita\Rules\SessionRules;
use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\DonationInvites;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use App\Modules\Risorse\Contracts\ResourceBookings;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\People;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Eventi con prenotazioni (dall'ActivityService e dal Waitlist del plugin): date con i loro posti, prenotazioni alla
 * singola data con il dovuto fissato (contributo, listino o ingresso di un pacchetto), lista d'attesa che si scorre da
 * sola quando si libera un posto, incassi in prima nota legati all'attività.
 *
 * @phpstan-type BookingRow array{id: string, person_id: string, name: string, type: string, active: bool, fee: int, paid: int, state: string, list: string|null, package_left: int|null, from_package: bool}
 */
final readonly class SessionService
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private ActivityService $activities,
        private Ledger $ledger,
        private AuditLog $audit,
        private ResourceBookings $resources,
        private ActivityOccupancy $occupancy,
        private ActivityVat $vat,
        private Entitlements $entitlements,
        private People $people,
        private MemberFeeOption $memberFees,
        private DonationInvites $invites,
    ) {}

    /**
     * Rifiuta una data con orario che si sovrappone a una prenotazione della risorsa dell'attività o a una data di
     * un'altra attività sulla stessa risorsa. Senza risorsa o senza orario non c'è niente da controllare.
     */
    private function assertFree(Activity $activity, string $date, ?string $start, ?string $end, ?string $exceptSessionId = null, ?string $endDate = null): void
    {
        if ($activity->resource_id === null) {
            return;
        }
        // Senza orari la data occupa il giorno intero.
        [$from, $to] = SessionRules::slot($date, $start, $end, $endDate);
        $when = $endDate !== null ? 'fino al '.date('d/m/Y', (int) strtotime($endDate)) : ($start === null && $end === null ? 'tutto il giorno' : 'dalle '.($start ?? '00:00').' alle '.($end ?? '24:00'));
        $what = $this->resources->busy($activity->resource_id, $from, $to)[0]['label'] ?? null;
        $what = $what !== null ? 'già '.$what : null;
        if ($what === null) {
            $other = $this->occupancy->busy($activity->resource_id, $from, $to, $exceptSessionId)[0]['label'] ?? null;
            $what = $other !== null ? 'già l\'attività «'.$other.'»' : null;
        }
        if ($what !== null) {
            $name = $this->resources->names([$activity->resource_id])[$activity->resource_id] ?? 'La risorsa';
            throw new ActivityError($name.' è occupata il '.date('d/m/Y', (int) strtotime($date)).' '.$when.': c\'è '.$what.'.');
        }
    }

    /** Oltre una settimana serve la conferma di chi inserisce: un anno sbagliato fa durare una data mesi. */
    private function assertDuration(int $days, bool $confirmed): void
    {
        if ($days > SessionRules::LONG_DAYS && ! $confirmed) {
            throw new ActivityError('Questa data dura '.$days.' giorni: è giusto? Se sì, spunta «Confermo la durata» e salva di nuovo.');
        }
    }

    /** Due date della stessa attività non si sovrappongono quando una delle due dura più giorni. */
    private function assertNoOverlap(string $activityId, string $date, ?string $endDate, ?string $exceptSessionId = null): void
    {
        $q = ActivitySession::query()->where('activity_id', $activityId)->whereNull('cancelled_at');
        if ($exceptSessionId !== null) {
            $q->where('id', '!=', $exceptSessionId);
        }
        foreach ($q->get(['session_date', 'end_date']) as $o) {
            $oEnd = $o->end_date?->format('Y-m-d');
            if (($endDate !== null || $oEnd !== null) && SessionRules::overlap($date, $endDate, $o->session_date->format('Y-m-d'), $oEnd)) {
                throw new ActivityError('Due date della stessa attività non possono sovrapporsi.');
            }
        }
    }

    /**
     * Le date di un'attività con i posti presi e la lista d'attesa.
     *
     * @return list<array{id: string, date: string, end_date: string|null, start: string|null, end: string|null, location: string|null, capacity: int|null, taken: int, free: int|null, waiting: int, cancelled: bool, ongoing: bool}>
     */
    public function sessions(Activity $activity): array
    {
        $today = now()->toDateString();
        $rows = ActivitySession::query()->where('activity_id', $activity->id)->orderBy('session_date')->orderBy('start_time')->get();
        $ids = array_values($rows->map(static fn (ActivitySession $s): string => $s->id)->all());
        $taken = $this->countBy('app.session_bookings', 'session_id', $ids, 'booked');
        $waiting = $this->countBy('app.session_waitlist', 'session_id', $ids, 'waiting');

        return array_values($rows->map(static fn (ActivitySession $s): array => [
            'id' => $s->id, 'date' => $s->session_date->format('Y-m-d'), 'end_date' => $s->end_date?->format('Y-m-d'), 'start' => $s->start_time, 'end' => $s->end_time, 'location' => $s->location,
            'capacity' => $s->capacity, 'taken' => $taken[$s->id] ?? 0, 'free' => SessionRules::free($s->capacity, $taken[$s->id] ?? 0),
            'waiting' => $waiting[$s->id] ?? 0, 'cancelled' => $s->cancelled_at !== null,
            'ongoing' => $s->end_date !== null && $s->session_date->format('Y-m-d') <= $today && $s->end_date->format('Y-m-d') >= $today,
        ])->all());
    }

    public function session(string $id): ActivitySession
    {
        $s = Str::isUuid($id) ? ActivitySession::query()->find($id) : null;

        return $s ?? throw new ActivityError('Data non trovata.');
    }

    /**
     * Aggiunge una data, o una ogni settimana fino a $to. Le date già presenti (stesso giorno e ora) non si duplicano.
     *
     * @return int date create
     */
    public function addDates(string $activityId, string $from, ?string $to, bool $weekly, ?string $start, ?string $end, ?string $location, ?int $capacity, ?string $endDate = null, bool $confirmLong = false): int
    {
        $activity = $this->bookable($activityId);
        $start = SessionRules::time($start);
        $end = SessionRules::time($end);
        $endDate = SessionRules::endDate($from, $endDate);
        $days = SessionRules::days($from, $endDate);
        $this->assertDuration($days, $confirmLong);
        if ($endDate === null && $start !== null && $end !== null && $end <= $start) {
            throw new ActivityError('L\'ora di fine deve essere dopo quella di inizio.');
        }
        if ($capacity !== null && $capacity < 1) {
            throw new ActivityError('I posti devono essere almeno 1 (lascia vuoto per non mettere limite).');
        }
        $year = $this->activities->year($activity);
        $dates = SessionRules::newDates($from, $to, $weekly);
        foreach ($dates as $i => $d) {
            $last = SessionRules::shifted($d, $days) ?? $d;
            if ($d < $year['start'] || $last > $year['end']) {
                throw new ActivityError('La data '.date('d/m/Y', (int) strtotime($d)).' è fuori dall\'anno delle attività '.$activity->social_year.'.');
            }
            if ($days > 1 && isset($dates[$i + 1]) && $dates[$i + 1] <= $last) {
                throw new ActivityError('Due date della stessa attività non possono sovrapporsi.');
            }
        }
        $have = [];
        foreach (ActivitySession::query()->where('activity_id', $activity->id)->get(['session_date', 'start_time']) as $s) {
            $have[$s->session_date->format('Y-m-d').'|'.($s->start_time ?? '')] = true;
        }
        $windows = [];
        foreach ($dates as $d) {
            if (! isset($have[$d.'|'.($start ?? '')])) {
                $this->assertNoOverlap($activity->id, $d, SessionRules::shifted($d, $days));
                $windows[] = SessionRules::slot($d, $start, $end, SessionRules::shifted($d, $days));
                if (! $activity->occupy_on_booking && $activity->resource_block === null) {
                    $this->assertFree($activity, $d, $start, $end, null, SessionRules::shifted($d, $days));
                }
            }
        }
        if ($windows !== []) {
            // Evento in un blocco di manifestazione: il periodo si allarga alle date nuove e va controllato per intero.
            $this->activities->assertBlockFits($activity, $windows);
        }
        $made = 0;
        $this->connection()->transaction(function () use ($dates, $have, $activity, $start, $end, $days, $location, $capacity, &$made): void {
            foreach ($dates as $d) {
                if (! isset($have[$d.'|'.($start ?? '')])) {
                    (new ActivitySession(['activity_id' => $activity->id, 'session_date' => $d, 'end_date' => SessionRules::shifted($d, $days), 'start_time' => $start, 'end_time' => $end,
                        'location' => $location === null || trim($location) === '' ? null : mb_substr(trim($location), 0, 190), 'capacity' => $capacity]))->save();
                    $made++;
                }
            }
        });
        if ($made > 0) {
            $this->audit->record('session.added', 'app.activities', $activity->id, ['count' => $made, 'first' => $dates[0]]);
        }

        return $made;
    }

    /**
     * Cambia orario, luogo e posti di una data; con più posti entrano quelli in lista d'attesa.
     *
     * @return list<string> chi è entrato
     */
    public function updateSession(string $sessionId, ?string $start, ?string $end, ?string $location, ?int $capacity, ?string $endDate = null, bool $confirmLong = false): array
    {
        $s = $this->session($sessionId);
        $start = SessionRules::time($start);
        $end = SessionRules::time($end);
        $day = $s->session_date->format('Y-m-d');
        $endDate = SessionRules::endDate($day, $endDate);
        $this->assertDuration(SessionRules::days($day, $endDate), $confirmLong);
        if ($endDate === null && $start !== null && $end !== null && $end <= $start) {
            throw new ActivityError('L\'ora di fine deve essere dopo quella di inizio.');
        }
        $activity = $this->activities->find($s->activity_id);
        $this->assertNoOverlap($activity->id, $day, $endDate, $s->id);
        if (! $activity->occupy_on_booking && $activity->resource_block === null) {
            $this->assertFree($activity, $day, $start, $end, $s->id, $endDate);
        }
        // Evento in un blocco: la vecchia posizione di questa data non conta, il periodo si ricalcola con la nuova.
        $this->activities->assertBlockFits($activity, [SessionRules::slot($day, $start, $end, $endDate)], $s->id);
        $taken = $this->taken($s->id);
        if ($capacity !== null && $capacity < max(1, $taken)) {
            throw new ActivityError('Ci sono già '.$taken.' prenotati: i posti non possono essere meno.');
        }
        $s->fill(['end_date' => $endDate, 'start_time' => $start, 'end_time' => $end, 'location' => $location === null || trim($location) === '' ? null : mb_substr(trim($location), 0, 190), 'capacity' => $capacity]);
        $s->save();
        $this->audit->record('session.updated', 'app.activities', $s->activity_id, ['session' => $s->id]);

        return $this->promote($s->id);
    }

    /** Annulla la data (con $future anche le successive). Le prenotazioni restano ma non contano; i rimborsi a parte. */
    public function cancelSession(string $sessionId, bool $future = false): int
    {
        $s = $this->session($sessionId);
        $q = ActivitySession::query()->where('activity_id', $s->activity_id)->whereNull('cancelled_at');
        $future ? $q->where('session_date', '>=', $s->session_date->format('Y-m-d')) : $q->where('id', $s->id);
        $n = $q->update(['cancelled_at' => now(), 'updated_at' => now()]);
        $this->audit->record('session.cancelled', 'app.activities', $s->activity_id, ['session' => $s->id, 'count' => $n]);

        return $n;
    }

    /**
     * Prenota una persona a una data. Con $priceList il dovuto è quello del listino (un pacchetto copre più ingressi);
     * con $usePackage si usa un ingresso di un pacchetto già preso; altrimenti il contributo dei soci o degli ospiti.
     */
    public function book(string $sessionId, string $personId, ?string $priceList = null, bool $usePackage = false, ?string $userId = null): SessionBooking
    {
        return $this->connection()->transaction(function () use ($sessionId, $personId, $priceList, $usePackage, $userId): SessionBooking {
            // La data bloccata finché la transazione non finisce: due prenotazioni insieme non superano i posti.
            $this->connection()->table('app.activity_sessions')->where('id', $sessionId)->lockForUpdate()->first(['id']);
            $s = $this->session($sessionId);
            if ($s->cancelled_at !== null) {
                throw new ActivityError('Questa data è annullata.');
            }
            $activity = $this->activities->find($s->activity_id);
            if ($activity->archived_at !== null) {
                throw new ActivityError('L\'attività è archiviata.');
            }
            if ($activity->occupy_on_booking) {
                // La data occupa la risorsa solo da quando ha prenotazioni: se la fascia è già occupata altrove si rifiuta.
                $this->assertFree($activity, $s->session_date->format('Y-m-d'), $s->start_time, $s->end_time, $s->id, $s->end_date?->format('Y-m-d'));
            }
            $person = $this->person($personId) ?? throw new ActivityError('Persona non trovata.');
            if ($person['type'] === MemberType::Guardian->value) {
                throw new ActivityError('Un genitore o tutore non socio non partecipa alle attività: prenota il minore.');
            }
            $existing = SessionBooking::query()->where('session_id', $s->id)->where('person_id', $personId)->first();
            if ($existing?->status === 'booked') {
                throw new ActivityError($person['name'].' ha già una prenotazione per questa data.');
            }
            $free = SessionRules::free($s->capacity, $this->taken($s->id));
            if ($free === 0) {
                throw new ActivityError('Posti esauriti per questa data ('.$s->capacity.'): puoi mettere la persona in lista d\'attesa.');
            }
            $price = $this->price($activity, $person, $priceList, $usePackage);
            $booking = $existing ?? new SessionBooking(['session_id' => $s->id, 'person_id' => $personId]);
            $booking->fill($price + ['status' => 'booked', 'created_by' => $userId]);
            $booking->cancelled_at = null;
            $booking->save();
            $this->connection()->table('app.session_waitlist')->where('session_id', $s->id)->where('person_id', $personId)->where('status', 'waiting')
                ->update(['status' => 'promoted', 'resolved_at' => now()]);
            $this->audit->record('booking.created', 'app.activities', $activity->id, ['session' => $s->id, 'person_id' => $personId, 'fee' => $booking->fee_due_cents, 'package' => $booking->package_of !== null]);

            return $booking;
        });
    }

    /**
     * Annulla la prenotazione (gli incassi restano: il rimborso si registra a parte).
     *
     * @return list<string> chi è entrato dalla lista d'attesa
     */
    public function cancelBooking(string $bookingId): array
    {
        $b = $this->booking($bookingId);
        if ($b->status !== 'booked') {
            throw new ActivityError('La prenotazione è già annullata.');
        }
        if ($b->package_entries > 1 && SessionBooking::query()->where('package_of', $b->id)->where('status', 'booked')->exists()) {
            throw new ActivityError('Con questo pacchetto ci sono altre prenotazioni: annulla prima quelle.');
        }
        $b->status = 'cancelled';
        $b->cancelled_at = now()->toImmutable();
        $b->save();
        $s = $this->session($b->session_id);
        $this->audit->record('booking.cancelled', 'app.activities', $s->activity_id, ['session' => $s->id, 'person_id' => $b->person_id]);

        return $this->promote($s->id);
    }

    public function booking(string $id): SessionBooking
    {
        $b = Str::isUuid($id) ? SessionBooking::query()->find($id) : null;

        return $b ?? throw new ActivityError('Prenotazione non trovata.');
    }

    /**
     * Prenotati a una data, attivi per primi, con il pagato.
     *
     * @return list<BookingRow>
     */
    public function bookings(string $sessionId): array
    {
        $s = $this->session($sessionId);
        $activity = $this->activities->find($s->activity_id);
        $rows = SessionBooking::query()->where('session_id', $s->id)->get();
        $people = $this->people(array_values(array_unique($rows->map(static fn (SessionBooking $b): string => $b->person_id)->all())));
        $paid = $this->paid(array_values($rows->map(static fn (SessionBooking $b): string => $b->id)->all()));
        $used = $this->packageUsed(array_values($rows->filter(static fn (SessionBooking $b): bool => $b->package_entries > 1)->map(static fn (SessionBooking $b): string => $b->id)->all()));
        $out = [];
        foreach ($rows as $b) {
            $p = $people[$b->person_id] ?? null;
            if ($p === null) {
                continue;
            }
            $list = $b->price_list === null ? null : SessionRules::findList($activity->price_lists, $b->price_list);
            $out[] = [
                'id' => $b->id, 'person_id' => $b->person_id, 'name' => $p['name'], 'type' => MemberType::from($p['type'])->label(), 'active' => $b->status === 'booked',
                'fee' => $b->fee_due_cents, 'paid' => $paid[$b->id] ?? 0, 'state' => Pricing::state($b->fee_due_cents, $paid[$b->id] ?? 0),
                'list' => $list['label'] ?? null, 'package_left' => $b->package_entries > 1 ? $b->package_entries - ($used[$b->id] ?? 1) : null,
                'from_package' => $b->package_of !== null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['active'] ? 0 : 1, mb_strtolower($a['name'])] <=> [$b['active'] ? 0 : 1, mb_strtolower($b['name'])]);

        return $out;
    }

    /**
     * Pacchetti di una persona per l'attività con ingressi ancora liberi, il più vecchio per primo.
     *
     * @return list<array{id: string, label: string, left: int}>
     */
    public function openPackages(string $personId, string $activityId): array
    {
        $activity = $this->activities->find($activityId);
        $rows = SessionBooking::query()->join('app.activity_sessions as s', 's.id', '=', 'app.session_bookings.session_id')
            ->where('app.session_bookings.person_id', $personId)->where('s.activity_id', $activity->id)->where('app.session_bookings.status', 'booked')
            ->where('app.session_bookings.package_entries', '>', 1)->orderBy('app.session_bookings.created_at')->get(['app.session_bookings.*']);
        $used = $this->packageUsed(array_values($rows->map(static fn (SessionBooking $b): string => $b->id)->all()));
        $out = [];
        foreach ($rows as $b) {
            $left = $b->package_entries - ($used[$b->id] ?? 1);
            if ($left > 0) {
                $out[] = ['id' => $b->id, 'label' => SessionRules::findList($activity->price_lists, (string) $b->price_list)['label'] ?? 'Pacchetto', 'left' => $left];
            }
        }

        return $out;
    }

    /**
     * Panoramica per la segreteria: i pacchetti dell'attività già pagati che hanno ancora ingressi, il più vecchio per primo.
     *
     * @return list<array{id: string, person_id: string, name: string, label: string, left: int, entries: int}>
     */
    public function paidPackages(string $activityId): array
    {
        $activity = $this->activities->find($activityId);
        $rows = SessionBooking::query()->join('app.activity_sessions as s', 's.id', '=', 'app.session_bookings.session_id')
            ->where('s.activity_id', $activity->id)->where('app.session_bookings.status', 'booked')
            ->where('app.session_bookings.package_entries', '>', 1)->orderBy('app.session_bookings.created_at')->get(['app.session_bookings.*']);
        $ids = array_values($rows->map(static fn (SessionBooking $b): string => $b->id)->all());
        $used = $this->packageUsed($ids);
        $paid = $this->paid($ids);
        $people = $this->people(array_values(array_unique($rows->map(static fn (SessionBooking $b): string => $b->person_id)->all())));
        $out = [];
        foreach ($rows as $b) {
            $left = $b->package_entries - ($used[$b->id] ?? 1);
            $state = Pricing::state($b->fee_due_cents, $paid[$b->id] ?? 0);
            if ($left <= 0 || ! in_array($state, [Pricing::PAID, Pricing::FREE], true) || ! isset($people[$b->person_id])) {
                continue;
            }
            $out[] = [
                'id' => $b->id, 'person_id' => $b->person_id, 'name' => $people[$b->person_id]['name'],
                'label' => SessionRules::findList($activity->price_lists, (string) $b->price_list)['label'] ?? 'Pacchetto',
                'left' => $left, 'entries' => $b->package_entries,
            ];
        }

        return $out;
    }

    /** In lista d'attesa per una data piena. */
    public function waitlistJoin(string $sessionId, string $personId): void
    {
        $s = $this->session($sessionId);
        if ($s->cancelled_at !== null) {
            throw new ActivityError('Questa data è annullata.');
        }
        if (SessionRules::free($s->capacity, $this->taken($s->id)) !== 0) {
            throw new ActivityError('Ci sono ancora posti liberi: puoi prenotare direttamente.');
        }
        $this->person($personId) ?? throw new ActivityError('Persona non trovata.');
        if (SessionBooking::query()->where('session_id', $s->id)->where('person_id', $personId)->where('status', 'booked')->exists()) {
            throw new ActivityError('Questa persona ha già una prenotazione per questa data.');
        }
        $c = $this->connection();
        if ($c->table('app.session_waitlist')->where('session_id', $s->id)->where('person_id', $personId)->where('status', 'waiting')->exists()) {
            throw new ActivityError('Questa persona è già in lista d\'attesa.');
        }
        $c->table('app.session_waitlist')->insert(['org_id' => $this->context->require()->id, 'id' => Str::uuid7()->toString(), 'session_id' => $s->id, 'person_id' => $personId]);
        $this->audit->record('waitlist.joined', 'app.activities', $s->activity_id, ['session' => $s->id, 'person_id' => $personId]);
    }

    public function waitlistLeave(string $sessionId, string $personId): void
    {
        $s = $this->session($sessionId);
        $n = $this->connection()->table('app.session_waitlist')->where('session_id', $s->id)->where('person_id', $personId)->where('status', 'waiting')
            ->update(['status' => 'left', 'resolved_at' => now()]);
        if ($n === 0) {
            throw new ActivityError('Questa persona non è in lista d\'attesa.');
        }
        $this->audit->record('waitlist.left', 'app.activities', $s->activity_id, ['session' => $s->id, 'person_id' => $personId]);
    }

    /** @return list<array{person_id: string, name: string, since: string}> nell'ordine di arrivo */
    public function waitlist(string $sessionId): array
    {
        $out = [];
        foreach ($this->connection()->table('app.session_waitlist as w')->join('app.people as p', 'p.id', '=', 'w.person_id')
            ->where('w.session_id', $sessionId)->where('w.status', 'waiting')->orderBy('w.created_at')->orderBy('w.id')
            ->get(['w.person_id', 'p.first_name', 'p.last_name', 'w.created_at']) as $w) {
            if (is_string($w->person_id ?? null)) {
                $out[] = ['person_id' => $w->person_id, 'name' => self::name($w->last_name ?? null, $w->first_name ?? null),
                    'since' => is_string($w->created_at ?? null) ? date('d/m/Y', (int) strtotime($w->created_at)) : ''];
            }
        }

        return $out;
    }

    /**
     * Finché ci sono posti prenota il primo in lista (al contributo normale). Le date passate o annullate non si toccano.
     *
     * @return list<string> chi è entrato
     */
    public function promote(string $sessionId): array
    {
        $s = $this->session($sessionId);
        if ($s->cancelled_at !== null || ($s->end_date ?? $s->session_date)->format('Y-m-d') < now()->toDateString()) {
            return [];
        }
        $in = [];
        foreach ($this->waitlist($s->id) as $w) {
            if (SessionRules::free($s->capacity, $this->taken($s->id)) === 0) {
                break;
            }
            try {
                $booking = $this->book($s->id, $w['person_id']);
                $in[] = $w['name'];
                $mail = $this->notifyPromoted($s, $w['person_id'], $booking->fee_due_cents > 0);
                $this->audit->record('waitlist.promoted', 'app.activities', $s->activity_id, ['session' => $s->id, 'person_id' => $w['person_id'], 'mail' => $mail]);
            } catch (ActivityError) {
                $this->connection()->table('app.session_waitlist')->where('session_id', $s->id)->where('person_id', $w['person_id'])->where('status', 'waiting')
                    ->update(['status' => 'left', 'resolved_at' => now()]);
            }
        }

        return $in;
    }

    /**
     * Accoda l'avviso per email a chi è passato dalla lista d'attesa alla prenotazione. Nel lavoro ci sono solo
     * identificativi: indirizzo e testo si ricavano al momento dell'invio. Un errore non tocca l'iscrizione.
     *
     * @return 'queued'|'failed'
     */
    private function notifyPromoted(ActivitySession $s, string $personId, bool $toPay): string
    {
        try {
            SendPromotionEmail::dispatch($this->context->require()->id, $s->id, $personId, $toPay);
        } catch (Throwable $e) {
            report($e);

            return 'failed';
        }

        return 'queued';
    }

    /**
     * Manda l'avviso di promozione (dal lavoro in coda): il genitore o tutore operativo per un minore. Senza email
     * non fa niente; un errore di posta si rilancia, così il lavoro riprova.
     */
    public function sendPromotion(string $sessionId, string $personId, bool $toPay): void
    {
        $s = ActivitySession::query()->find($sessionId);
        if ($s === null) {
            return;
        }
        $c = $this->connection();
        $p = $c->table('app.people')->where('id', $personId)->first(['first_name', 'email', 'anonymized_at', 'left_on']);
        if ($p === null || $p->anonymized_at !== null || $p->left_on !== null) {
            return;
        }
        $birth = $this->people->card($personId)['birth_date'] ?? null;
        $minor = is_string($birth) && CarbonImmutable::parse($birth)->addYears(18)->isFuture();
        $about = is_string($p->first_name ?? null) ? $p->first_name : '';
        $to = $p;
        if ($minor) {
            $guardian = $this->people->guardians($personId)[0] ?? null;
            $to = $guardian === null ? null : (object) ['first_name' => $guardian['first_name'], 'email' => $guardian['email']];
        }
        $email = $to !== null && is_string($to->email ?? null) ? $to->email : '';
        if ($to === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $activity = $this->activities->find($s->activity_id);
        $when = $s->session_date->format('d/m/Y').(is_string($s->start_time) && $s->start_time !== '' ? ', alle ore '.substr($s->start_time, 0, 5) : '');
        $org = $this->db->connection('pgsql')->table('core.orgs')->where('id', $this->context->require()->id)->first(['id', 'name', 'slug']);
        if ($org === null || ! is_string($org->id ?? null)) {
            return;
        }
        $area = $this->areaUrl($org->id, is_string($org->slug ?? null) ? $org->slug : '');
        $cancel = $area === null ? 'scrivi alla segreteria' : "annulla dall'area soci o scrivi alla segreteria";
        $body = $minor
            ? "Si è liberato un posto: ora {$about} ha un posto per {$activity->name} del {$when}. Se non può venire, {$cancel}."
            : "Si è liberato un posto: ora hai un posto per {$activity->name} del {$when}. Se non puoi venire, {$cancel}.";
        $payLine = $area === null ? 'Per il contributo da pagare scrivi alla segreteria.' : 'Il contributo da pagare lo trovi in «Quote», nell\'area soci.';

        // Siamo già dentro un lavoro in coda: la posta parte subito, senza accodarla una seconda volta.
        Notification::route('mail', $email)->notifyNow(new WaitlistPromotedNotification(
            'Ciao '.(is_string($to->first_name ?? null) ? $to->first_name : '').',',
            $body,
            $toPay ? $payLine : null,
            $area,
            is_string($org->name ?? null) ? $org->name : '',
            $area !== null && $this->invites->enabled(DonationInvites::EMAIL) ? $area.'/paga' : null,
        ));
    }

    /** Indirizzo dell'area soci (dominio proprio se verificato), solo se l'associazione ha il pacchetto. */
    private function areaUrl(string $orgId, string $slug): ?string
    {
        if ($slug === '' || ! $this->entitlements->for($orgId)->has(Package::MemberArea)) {
            return null;
        }
        $custom = $this->db->connection('pgsql')->table('core.org_domains')->where('org_id', $orgId)->where('state', 'active')->value('domain');
        $app = config('app.url');
        $scheme = is_string($app) ? parse_url($app, PHP_URL_SCHEME) : null;
        $members = config('saas.members_domain');
        $host = is_string($custom) && $custom !== '' ? $custom : $slug.'.'.(is_string($members) ? $members : 'associazionesemplice.it');

        return (is_string($scheme) ? $scheme : 'https').'://'.$host.'/area';
    }

    /**
     * Incassa una prenotazione in prima nota (quota attività, con ricevuta), legata all'attività. Senza importo quanto manca.
     *
     * @return string|null il numero di ricevuta
     */
    public function collect(string $bookingId, string $accountId, string $date, ?int $cents, ?PaymentMethod $method): ?string
    {
        $b = $this->booking($bookingId);
        if ($b->status !== 'booked') {
            throw new ActivityError('La prenotazione è annullata.');
        }
        $s = $this->session($b->session_id);
        $activity = $this->activities->find($s->activity_id);
        $cents ??= Pricing::remaining($b->fee_due_cents, $this->paid([$b->id])[$b->id] ?? 0);
        if ($cents <= 0) {
            throw new ActivityError('Non c\'è niente da incassare: indica l\'importo.');
        }
        $fee = $this->ledger->categoryFor(CategoryKind::ActivityFee) ?? throw new ActivityError('Manca la voce «Quota attività / corso» in prima nota.');
        // Opzione fiscale (spenta di default): il contributo di un socio si incassa come erogazione liberale, senza IVA.
        ['category' => $category, 'donation' => $donation] = $this->memberFees->category($fee, $this->person($b->person_id)['type'] ?? '');
        $list = $b->price_list === null ? null : SessionRules::findList($activity->price_lists, $b->price_list);
        $description = mb_substr(MemberFeeOption::description($donation, $activity->kind, $activity->name, $s->session_date->format('d/m/Y')).($list !== null ? ' · '.$list['label'] : ''), 0, 255);

        return $this->connection()->transaction(function () use ($b, $s, $activity, $accountId, $date, $cents, $method, $category, $description, $donation): ?string {
            $out = $this->ledger->recordIncome(new Income($date, $accountId, [new IncomeLine($category, $cents, $description, $s->session_date->format('Y-m'), $donation ? null : $this->vat->rate($activity))], $method, $b->person_id, true, null, $activity->id));
            foreach ($out['transactions'] as $tx) {
                $this->connection()->table('app.booking_payments')->insert(['org_id' => $this->context->require()->id, 'transaction_id' => $tx, 'booking_id' => $b->id]);
            }

            return $out['receipt'];
        });
    }

    /**
     * Incassato per prenotazione (movimenti non stornati).
     *
     * @param  list<string>  $bookingIds
     * @return array<string, int>
     */
    public function paid(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }
        $txs = [];
        foreach ($this->connection()->table('app.booking_payments')->whereIn('booking_id', $bookingIds)->get(['transaction_id', 'booking_id']) as $l) {
            if (is_string($l->transaction_id ?? null) && is_string($l->booking_id ?? null)) {
                $txs[$l->transaction_id] = $l->booking_id;
            }
        }
        $out = [];
        foreach ($this->ledger->liveAmounts(array_keys($txs)) as $tx => $cents) {
            $out[$txs[$tx]] = ($out[$txs[$tx]] ?? 0) + $cents;
        }

        return $out;
    }

    /**
     * @param  array{id: string, name: string, type: string}  $person
     * @return array{fee_due_cents: int, price_list: string|null, package_entries: int, package_of: string|null}
     */
    private function price(Activity $activity, array $person, ?string $priceList, bool $usePackage): array
    {
        if ($usePackage) {
            $open = $this->openPackages($person['id'], $activity->id);
            $pk = $open[0] ?? throw new ActivityError($person['name'].' non ha un pacchetto con ingressi liberi per questa attività.');
            $key = SessionBooking::query()->whereKey($pk['id'])->value('price_list');

            return ['fee_due_cents' => 0, 'price_list' => is_string($key) ? $key : null, 'package_entries' => 0, 'package_of' => $pk['id']];
        }
        if ($priceList !== null && $priceList !== '') {
            $l = SessionRules::findList($activity->price_lists, $priceList) ?? throw new ActivityError('Listino non trovato per questa attività.');

            return ['fee_due_cents' => $this->vat->gross($activity, $l['cents']), 'price_list' => $l['key'], 'package_entries' => $l['entries'] > 1 ? $l['entries'] : 0, 'package_of' => null];
        }

        return ['fee_due_cents' => $this->vat->gross($activity, Pricing::feeFor($activity->fee_cents, $activity->guest_fee_cents, $person['type'] === MemberType::Guest->value)),
            'price_list' => null, 'package_entries' => 0, 'package_of' => null];
    }

    private function bookable(string $activityId): Activity
    {
        $a = $this->activities->find($activityId);
        if (! $a->kind->usesSessions()) {
            throw new ActivityError('Le date con prenotazione ci sono solo negli eventi con prenotazioni.');
        }
        if ($a->archived_at !== null) {
            throw new ActivityError('L\'attività è archiviata.');
        }

        return $a;
    }

    private function taken(string $sessionId): int
    {
        return SessionBooking::query()->where('session_id', $sessionId)->where('status', 'booked')->count();
    }

    /**
     * Ingressi usati di ogni pacchetto (la prenotazione che l'ha comprato conta come uno).
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function packageUsed(array $ids): array
    {
        $out = array_fill_keys($ids, 1);
        foreach ($this->countBy('app.session_bookings', 'package_of', $ids, 'booked') as $id => $n) {
            $out[$id] = 1 + $n;
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function countBy(string $table, string $column, array $ids, string $status): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->connection()->table($table)->whereIn($column, $ids)->where('status', $status)->groupBy($column)->select([$column.' as k'])->selectRaw('count(*) AS n')->get() as $r) {
            if (is_string($r->k ?? null)) {
                $out[$r->k] = is_numeric($r->n ?? null) ? (int) $r->n : 0;
            }
        }

        return $out;
    }

    /** @return array{id: string, name: string, type: string}|null */
    private function person(string $id): ?array
    {
        return Str::isUuid($id) ? ($this->people([$id])[$id] ?? null) : null;
    }

    /**
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
                $out[$p->id] = ['id' => $p->id, 'name' => self::name($p->last_name ?? null, $p->first_name ?? null), 'type' => $p->type];
            }
        }

        return $out;
    }

    private static function name(mixed $last, mixed $first): string
    {
        return trim((is_string($last) ? $last : '').' '.(is_string($first) ? $first : ''));
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
