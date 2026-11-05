<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use App\Modules\Attivita\Contracts\ActivityError;
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
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Contracts\MemberType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

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
    ) {}

    /**
     * Le date di un'attività con i posti presi e la lista d'attesa.
     *
     * @return list<array{id: string, date: string, start: string|null, end: string|null, location: string|null, capacity: int|null, taken: int, free: int|null, waiting: int, cancelled: bool}>
     */
    public function sessions(Activity $activity): array
    {
        $rows = ActivitySession::query()->where('activity_id', $activity->id)->orderBy('session_date')->orderBy('start_time')->get();
        $ids = array_values($rows->map(static fn (ActivitySession $s): string => $s->id)->all());
        $taken = $this->countBy('app.session_bookings', 'session_id', $ids, 'booked');
        $waiting = $this->countBy('app.session_waitlist', 'session_id', $ids, 'waiting');

        return array_values($rows->map(static fn (ActivitySession $s): array => [
            'id' => $s->id, 'date' => $s->session_date->format('Y-m-d'), 'start' => $s->start_time, 'end' => $s->end_time, 'location' => $s->location,
            'capacity' => $s->capacity, 'taken' => $taken[$s->id] ?? 0, 'free' => SessionRules::free($s->capacity, $taken[$s->id] ?? 0),
            'waiting' => $waiting[$s->id] ?? 0, 'cancelled' => $s->cancelled_at !== null,
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
    public function addDates(string $activityId, string $from, ?string $to, bool $weekly, ?string $start, ?string $end, ?string $location, ?int $capacity): int
    {
        $activity = $this->bookable($activityId);
        $start = SessionRules::time($start);
        $end = SessionRules::time($end);
        if ($start !== null && $end !== null && $end <= $start) {
            throw new ActivityError('L\'ora di fine deve essere dopo quella di inizio.');
        }
        if ($capacity !== null && $capacity < 1) {
            throw new ActivityError('I posti devono essere almeno 1 (vuoto = senza limite).');
        }
        $year = $this->activities->year($activity);
        $dates = SessionRules::newDates($from, $to, $weekly);
        foreach ($dates as $d) {
            if ($d < $year['start'] || $d > $year['end']) {
                throw new ActivityError('La data '.date('d/m/Y', (int) strtotime($d)).' è fuori dall\'anno sociale '.$activity->social_year.'.');
            }
        }
        $have = [];
        foreach (ActivitySession::query()->where('activity_id', $activity->id)->get(['session_date', 'start_time']) as $s) {
            $have[$s->session_date->format('Y-m-d').'|'.($s->start_time ?? '')] = true;
        }
        $made = 0;
        $this->connection()->transaction(function () use ($dates, $have, $activity, $start, $end, $location, $capacity, &$made): void {
            foreach ($dates as $d) {
                if (! isset($have[$d.'|'.($start ?? '')])) {
                    (new ActivitySession(['activity_id' => $activity->id, 'session_date' => $d, 'start_time' => $start, 'end_time' => $end,
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
    public function updateSession(string $sessionId, ?string $start, ?string $end, ?string $location, ?int $capacity): array
    {
        $s = $this->session($sessionId);
        $start = SessionRules::time($start);
        $end = SessionRules::time($end);
        if ($start !== null && $end !== null && $end <= $start) {
            throw new ActivityError('L\'ora di fine deve essere dopo quella di inizio.');
        }
        $taken = $this->taken($s->id);
        if ($capacity !== null && $capacity < max(1, $taken)) {
            throw new ActivityError('Ci sono già '.$taken.' prenotati: i posti non possono essere meno.');
        }
        $s->fill(['start_time' => $start, 'end_time' => $end, 'location' => $location === null || trim($location) === '' ? null : mb_substr(trim($location), 0, 190), 'capacity' => $capacity]);
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
            $person = $this->person($personId) ?? throw new ActivityError('Persona non trovata.');
            if ($person['type'] === MemberType::Guardian->value) {
                throw new ActivityError('Un genitore o tutore non socio non partecipa alle attività: prenota il minore.');
            }
            $existing = SessionBooking::query()->where('session_id', $s->id)->where('person_id', $personId)->first();
            if ($existing?->status === 'booked') {
                throw new ActivityError($person['name'].' è già prenotato a questa data.');
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
            throw new ActivityError('Questa persona è già prenotata a questa data.');
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
            throw new ActivityError('Non è in lista d\'attesa.');
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
        if ($s->cancelled_at !== null || $s->session_date->format('Y-m-d') < now()->toDateString()) {
            return [];
        }
        $in = [];
        foreach ($this->waitlist($s->id) as $w) {
            if (SessionRules::free($s->capacity, $this->taken($s->id)) === 0) {
                break;
            }
            try {
                $this->book($s->id, $w['person_id']);
                $in[] = $w['name'];
                $this->audit->record('waitlist.promoted', 'app.activities', $s->activity_id, ['session' => $s->id, 'person_id' => $w['person_id']]);
            } catch (ActivityError) {
                $this->connection()->table('app.session_waitlist')->where('session_id', $s->id)->where('person_id', $w['person_id'])->where('status', 'waiting')
                    ->update(['status' => 'left', 'resolved_at' => now()]);
            }
        }

        return $in;
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
        $category = $this->ledger->categoryFor(CategoryKind::ActivityFee) ?? throw new ActivityError('Manca la categoria «Quota attività / corso» in prima nota.');
        $list = $b->price_list === null ? null : SessionRules::findList($activity->price_lists, $b->price_list);
        $description = mb_substr('Contributo '.$activity->name.' '.$s->session_date->format('d/m/Y').($list !== null ? ' · '.$list['label'] : ''), 0, 255);

        return $this->connection()->transaction(function () use ($b, $s, $activity, $accountId, $date, $cents, $method, $category, $description): ?string {
            $out = $this->ledger->recordIncome(new Income($date, $accountId, [new IncomeLine($category, $cents, $description, $s->session_date->format('Y-m'))], $method, $b->person_id, true, null, $activity->id));
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

            return ['fee_due_cents' => $l['cents'], 'price_list' => $l['key'], 'package_entries' => $l['entries'] > 1 ? $l['entries'] : 0, 'package_of' => null];
        }

        return ['fee_due_cents' => Pricing::feeFor($activity->fee_cents, $activity->guest_fee_cents, $person['type'] === MemberType::Guest->value),
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
