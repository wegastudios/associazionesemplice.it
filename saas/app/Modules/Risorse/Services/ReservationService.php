<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Contracts\Typology;
use App\Modules\Risorse\Models\Reservation;
use App\Modules\Risorse\Models\Resource;
use App\Modules\Risorse\Rules\ResourceRules;
use App\Modules\Soci\Contracts\Members;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Prenotazioni dalla segreteria (dal plugin): a fasce (anche ogni settimana per più settimane), a giornate, per una
 * stagione; blocchi per chiusure e manutenzioni; annullamenti; incassi in prima nota con ricevuta; lista d'attesa delle
 * risorse stagionali. Due prenotazioni non si sovrappongono: lo controlla il servizio con un messaggio chiaro e, sotto,
 * il database. Chi ha la tessera valida paga la tariffa soci, gli altri quella dei non soci se c'è.
 *
 * @phpstan-type Booking array{person_id?: string|null, holder_name?: string|null, date?: string, start?: string, units?: int, weeks?: int, to?: string|null, guests?: list<string>, services?: list<string>, note?: string|null}
 */
final readonly class ReservationService
{
    public const TIMEZONE = 'Europe/Rome';

    public const MAX_WEEKS = 52;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private ResourceService $resources,
        private Members $members,
        private Ledger $ledger,
        private AuditLog $audit,
    ) {}

    /** Adesso, nel fuso dell'associazione, come testo confrontabile. */
    public static function now(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d H:i:s');
    }

    /**
     * Una prenotazione (o una serie settimanale, per le risorse a ore) per una persona dell'anagrafica o per un esterno.
     *
     * @param  Booking  $in
     * @return list<string> le prenotazioni create
     */
    public function book(string $resourceId, array $in, ?string $userId): array
    {
        $res = $this->resources->find($resourceId);
        if ($res->archived_at !== null) {
            throw new ResourceError('La risorsa è archiviata: riattivala per prenotarla.');
        }
        $personId = self::filled($in['person_id'] ?? null);
        $holder = $personId === null ? self::filled($in['holder_name'] ?? null) : null;
        if ($personId === null && $holder === null) {
            throw new ResourceError('Scegli la persona dall\'anagrafica o scrivi il nome di chi prenota.');
        }
        $date = (string) ($in['date'] ?? '');
        $units = (int) ($in['units'] ?? 1);
        $member = $personId !== null && $this->members->isActive($personId, preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null);
        $rules = $res->rules();
        $guests = ResourceRules::guests($res->guests_max, $in['guests'] ?? []);
        $services = $this->chosenServices($res, $in['services'] ?? []);
        $now = self::now();

        $ranges = [];
        switch ($res->typology) {
            case Typology::Hourly:
                $weeks = max(1, min(self::MAX_WEEKS, (int) ($in['weeks'] ?? 1)));
                foreach (ResourceRules::seriesDates($date, $weeks) as $day) {
                    [$s, $e] = ResourceRules::slotRange($rules, $day, (string) ($in['start'] ?? ''), $units, $now, true);
                    $ranges[] = [$s, $e, ResourceRules::fee($rules, $units, count($guests), $s, $member, $services)];
                }
                break;
            case Typology::Daily:
                [$s, $e] = ResourceRules::dayRange($rules, $date, $units, $now, true);
                $ranges[] = [$s, $e, ResourceRules::fee($rules, $units, count($guests), $s, $member, $services)];
                break;
            case Typology::Seasonal:
                if ($personId === null) {
                    throw new ResourceError('Una risorsa stagionale si assegna a una persona dell\'anagrafica.');
                }
                $units = 1;
                [$s, $e] = ResourceRules::seasonRange($date, self::filled($in['to'] ?? null));
                $ranges[] = [$s, $e, ResourceRules::fee($rules, 1, 0, $s, $member)];
                break;
        }

        $ids = $this->insert($res, $ranges, [
            'kind' => 'booking', 'person_id' => $personId, 'holder_name' => $holder === null ? null : mb_substr($holder, 0, 160), 'member' => $member,
            'units' => $units, 'guests' => $guests, 'services' => $services, 'note' => self::note($in['note'] ?? null), 'created_by' => $userId,
        ]);
        if ($res->typology === Typology::Seasonal && $personId !== null) {
            // Chi era in lista d'attesa ora ha la risorsa.
            $this->connection()->table('app.resource_waitlist')->where('resource_id', $res->id)->where('person_id', $personId)->where('status', 'waiting')
                ->update(['status' => 'done', 'resolved_at' => now()]);
        }
        $this->audit->record(count($ids) > 1 ? 'reservation.series' : 'reservation.created', 'app.resources', $res->id, [
            'reservations' => count($ids), 'first' => $ranges[0][0], 'person_id' => $personId, 'external' => $holder !== null, 'member' => $member,
            'fee' => array_sum(array_column($ranges, 2)),
        ]);

        return $ids;
    }

    /**
     * Il dovuto di una prenotazione, con gli stessi controlli (senza scrivere niente).
     *
     * @param  Booking  $in
     */
    public function quote(string $resourceId, array $in): int
    {
        $res = $this->resources->find($resourceId);
        $personId = self::filled($in['person_id'] ?? null);
        $date = (string) ($in['date'] ?? '');
        $member = $personId !== null && $this->members->isActive($personId, preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null);
        $units = (int) ($in['units'] ?? 1);
        $rules = $res->rules();
        $guests = ResourceRules::guests($res->guests_max, $in['guests'] ?? []);
        $start = match ($res->typology) {
            Typology::Hourly => ResourceRules::slotRange($rules, $date, (string) ($in['start'] ?? ''), $units, self::now(), true)[0],
            Typology::Daily => ResourceRules::dayRange($rules, $date, $units, self::now(), true)[0],
            Typology::Seasonal => ResourceRules::seasonRange($date, self::filled($in['to'] ?? null))[0],
        };

        return ResourceRules::fee($rules, $units, count($guests), $start, $member, $res->typology === Typology::Seasonal ? [] : $this->chosenServices($res, $in['services'] ?? []));
    }

    /** Blocca la risorsa (manutenzione, chiusura, torneo): da «Y-m-d H:i» a «Y-m-d H:i». */
    public function block(string $resourceId, string $from, string $to, ?string $note, ?string $userId): string
    {
        $res = $this->resources->find($resourceId);
        $s = self::moment($from);
        $e = self::moment($to);
        if ($e <= $s) {
            throw new ResourceError('La fine del blocco deve essere dopo l\'inizio.');
        }
        $id = $this->insert($res, [[$s, $e, 0]], ['kind' => 'block', 'note' => self::note($note), 'created_by' => $userId, 'units' => 1])[0];
        $this->audit->record('reservation.blocked', 'app.resources', $res->id, ['starts' => $s, 'ends' => $e]);

        return $id;
    }

    /**
     * Annulla una prenotazione o un blocco; con $series anche le successive della stessa serie. Gli incassi restano in
     * prima nota (un rimborso si registra a parte). Una risorsa stagionale liberata si vede in testa alla lista d'attesa.
     *
     * @return int prenotazioni annullate
     */
    public function cancel(string $reservationId, bool $series = false): int
    {
        $r = $this->find($reservationId);
        if ($r->status !== 'booked') {
            throw new ResourceError('La prenotazione è già annullata.');
        }
        $q = Reservation::query()->where('status', 'booked');
        if ($series && $r->series_id !== null) {
            $q->where('series_id', $r->series_id)->where('starts_at', '>=', $r->starts_at);
        } else {
            $q->where('id', $r->id);
        }
        $n = $q->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
        $this->audit->record($n > 1 ? 'reservation.series_cancelled' : 'reservation.cancelled', 'app.resources', $r->resource_id, [
            'reservation' => $r->id, 'kind' => $r->kind, 'count' => $n,
        ]);

        return $n;
    }

    /**
     * Incasso di una prenotazione in prima nota (voce della risorsa, o «Altre entrate»), con ricevuta se chi paga è in
     * anagrafica. Senza importo si incassa quello che manca.
     *
     * @return string|null il numero di ricevuta
     */
    public function collect(string $reservationId, string $accountId, string $date, ?int $cents, ?PaymentMethod $method): ?string
    {
        $r = $this->find($reservationId);
        if ($r->kind !== 'booking') {
            throw new ResourceError('Un blocco non si incassa.');
        }
        $res = $this->resources->find($r->resource_id);
        $cents ??= max(0, $r->fee_due_cents - ($this->paid([$r->id])[$r->id] ?? 0));
        if ($cents <= 0) {
            throw new ResourceError('Non c\'è niente da incassare: indica l\'importo.');
        }
        $category = $res->category_id ?? $this->ledger->categoryFor(CategoryKind::OtherIncome) ?? throw new ResourceError('Manca la categoria «Altre entrate» in prima nota.');
        $when = $res->typology === Typology::Hourly ? date('d/m/Y H:i', (int) strtotime($r->starts_at)) : date('d/m/Y', (int) strtotime($r->starts_at));
        $description = mb_substr($res->name.' · '.$when.($r->holder_name !== null ? ' · '.$r->holder_name : ''), 0, 255);

        return $this->connection()->transaction(function () use ($r, $accountId, $date, $cents, $method, $category, $description): ?string {
            $out = $this->ledger->recordIncome(new Income($date, $accountId, [new IncomeLine($category, $cents, $description)], $method, $r->person_id, $r->person_id !== null));
            foreach ($out['transactions'] as $tx) {
                $this->connection()->table('app.reservation_payments')->insert(['org_id' => $this->context->require()->id, 'transaction_id' => $tx, 'reservation_id' => $r->id]);
            }
            $this->audit->record('reservation.paid', 'app.resources', $r->resource_id, ['reservation' => $r->id, 'cents' => $cents]);

            return $out['receipt'];
        });
    }

    /**
     * Incassato per prenotazione (movimenti non stornati).
     *
     * @param  list<string>  $reservationIds
     * @return array<string, int>
     */
    public function paid(array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }
        $links = $this->connection()->table('app.reservation_payments')->whereIn('reservation_id', $reservationIds)->get(['transaction_id', 'reservation_id']);
        $txs = [];
        foreach ($links as $l) {
            if (is_string($l->transaction_id ?? null) && is_string($l->reservation_id ?? null)) {
                $txs[$l->transaction_id] = $l->reservation_id;
            }
        }
        $out = [];
        foreach ($this->ledger->liveAmounts(array_keys($txs)) as $tx => $cents) {
            $out[$txs[$tx]] = ($out[$txs[$tx]] ?? 0) + $cents;
        }

        return $out;
    }

    public function find(string $id): Reservation
    {
        return Reservation::query()->find($id) ?? throw new ResourceError('Prenotazione non trovata.');
    }

    /**
     * Prenotazioni e blocchi attivi delle risorse nel periodo [da, a), con il nome di chi prenota.
     *
     * @param  list<string>|null  $resourceIds  null = tutte
     * @return list<array{id: string, resource_id: string, kind: string, starts_at: string, ends_at: string|null, who: string|null, person_id: string|null, member: bool, units: int, guests: list<string>, services: list<array{name: string, fee_cents: int}>, fee: int, paid: int, note: string|null, series: bool}>
     */
    public function between(string $from, string $to, ?array $resourceIds = null): array
    {
        $q = Reservation::query()->where('status', 'booked')->where('starts_at', '<', $to)
            ->where(static fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $from))->orderBy('starts_at');
        if ($resourceIds !== null) {
            $q->whereIn('resource_id', $resourceIds);
        }

        return $this->decorate(array_values($q->limit(2000)->get()->all()));
    }

    /**
     * Le prossime (e quelle in corso) di una risorsa.
     *
     * @return list<array{id: string, resource_id: string, kind: string, starts_at: string, ends_at: string|null, who: string|null, person_id: string|null, member: bool, units: int, guests: list<string>, services: list<array{name: string, fee_cents: int}>, fee: int, paid: int, note: string|null, series: bool}>
     */
    public function upcoming(string $resourceId, int $limit = 200): array
    {
        $now = self::now();
        $rows = Reservation::query()->where('resource_id', $resourceId)->where('status', 'booked')
            ->where(static fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now))->orderBy('starts_at')->limit($limit)->get();

        return $this->decorate(array_values($rows->all()));
    }

    /** Mette una persona in lista d'attesa per una risorsa stagionale. */
    public function waitlistJoin(string $resourceId, string $personId, ?string $note): void
    {
        $res = $this->resources->find($resourceId);
        if ($res->typology !== Typology::Seasonal) {
            throw new ResourceError('La lista d\'attesa c\'è solo per le risorse stagionali.');
        }
        $holds = Reservation::query()->where('resource_id', $res->id)->where('person_id', $personId)->where('status', 'booked')
            ->where(static fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', self::now()))->exists();
        if ($holds) {
            throw new ResourceError('Questa persona ha già la risorsa.');
        }
        $c = $this->connection();
        if ($c->table('app.resource_waitlist')->where('resource_id', $res->id)->where('person_id', $personId)->where('status', 'waiting')->exists()) {
            throw new ResourceError('È già in lista d\'attesa.');
        }
        if (! $c->table('app.people')->where('id', $personId)->whereNull('deleted_at')->exists()) {
            throw new ResourceError('Persona non trovata.');
        }
        $c->table('app.resource_waitlist')->insert([
            'org_id' => $this->context->require()->id, 'id' => Str::uuid7()->toString(), 'resource_id' => $res->id, 'person_id' => $personId, 'note' => self::note($note),
        ]);
        $this->audit->record('resource.waitlist_joined', 'app.resources', $res->id, ['person_id' => $personId]);
    }

    public function waitlistLeave(string $resourceId, string $personId): void
    {
        $n = $this->connection()->table('app.resource_waitlist')->where('resource_id', $resourceId)->where('person_id', $personId)->where('status', 'waiting')
            ->update(['status' => 'left', 'resolved_at' => now()]);
        if ($n === 0) {
            throw new ResourceError('Non è in lista d\'attesa.');
        }
        $this->audit->record('resource.waitlist_left', 'app.resources', $resourceId, ['person_id' => $personId]);
    }

    /** @return list<array{person_id: string, name: string, since: string, note: string|null}> nell'ordine di arrivo */
    public function waitlist(string $resourceId): array
    {
        $rows = $this->connection()->table('app.resource_waitlist as w')->join('app.people as p', 'p.id', '=', 'w.person_id')
            ->where('w.resource_id', $resourceId)->where('w.status', 'waiting')->orderBy('w.created_at')->orderBy('w.id')
            ->get(['w.person_id', 'p.first_name', 'p.last_name', 'w.created_at', 'w.note']);
        $out = [];
        foreach ($rows as $w) {
            if (is_string($w->person_id ?? null)) {
                $out[] = ['person_id' => $w->person_id, 'name' => self::name($w->last_name ?? null, $w->first_name ?? null),
                    'since' => is_string($w->created_at ?? null) ? date('d/m/Y', (int) strtotime($w->created_at)) : '', 'note' => is_string($w->note ?? null) ? $w->note : null];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2: int}>  $ranges  inizio, fine, dovuto
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private function insert(Resource $res, array $ranges, array $fields): array
    {
        return $this->connection()->transaction(function () use ($res, $ranges, $fields): array {
            // Un controllo con il messaggio giusto (quale giorno è occupato e da cosa); il vincolo del database resta la garanzia.
            foreach ($ranges as [$s, $e]) {
                $q = Reservation::query()->where('resource_id', $res->id)->where('status', 'booked')
                    ->where(static fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $s));
                if ($e !== null) {
                    $q->where('starts_at', '<', $e);
                }
                $busy = $q->first();
                if ($busy !== null) {
                    throw new ResourceError(self::busyMessage($res, $s, $busy));
                }
            }
            $series = count($ranges) > 1 ? Str::uuid7()->toString() : null;
            $ids = [];
            try {
                foreach ($ranges as [$s, $e, $fee]) {
                    $r = new Reservation($fields + ['resource_id' => $res->id, 'starts_at' => $s, 'ends_at' => $e, 'fee_due_cents' => $fee, 'status' => 'booked', 'series_id' => $series]);
                    $r->save();
                    $ids[] = $r->id;
                }
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23P01') {
                    throw new ResourceError('La risorsa è stata appena prenotata da qualcun altro in quell\'orario: ricarica e scegli un altro orario.');
                }
                throw $e;
            }

            return $ids;
        });
    }

    private static function busyMessage(Resource $res, string $start, Reservation $busy): string
    {
        $when = $res->typology === Typology::Hourly ? 'il '.date('d/m/Y \a\l\l\e H:i', (int) strtotime($start)) : 'dal '.date('d/m/Y', (int) strtotime($start));

        return $busy->kind === 'block'
            ? 'La risorsa è bloccata '.$when.($busy->note !== null ? ' ('.$busy->note.')' : '').'.'
            : 'La risorsa è già occupata '.$when.'.';
    }

    /**
     * @param  list<Reservation>  $rows
     * @return list<array{id: string, resource_id: string, kind: string, starts_at: string, ends_at: string|null, who: string|null, person_id: string|null, member: bool, units: int, guests: list<string>, services: list<array{name: string, fee_cents: int}>, fee: int, paid: int, note: string|null, series: bool}>
     */
    private function decorate(array $rows): array
    {
        $people = array_values(array_unique(array_filter(array_map(static fn (Reservation $r): ?string => $r->person_id, $rows))));
        $names = [];
        if ($people !== []) {
            foreach ($this->connection()->table('app.people')->whereIn('id', $people)->get(['id', 'first_name', 'last_name']) as $p) {
                if (is_string($p->id ?? null)) {
                    $names[$p->id] = self::name($p->last_name ?? null, $p->first_name ?? null);
                }
            }
        }
        $paid = $this->paid(array_map(static fn (Reservation $r): string => $r->id, $rows));

        return array_map(static fn (Reservation $r): array => [
            'id' => $r->id, 'resource_id' => $r->resource_id, 'kind' => $r->kind, 'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at,
            'who' => $r->person_id !== null ? ($names[$r->person_id] ?? null) : $r->holder_name, 'person_id' => $r->person_id, 'member' => $r->member,
            'units' => $r->units, 'guests' => $r->guests, 'services' => $r->services, 'fee' => $r->fee_due_cents, 'paid' => $paid[$r->id] ?? 0,
            'note' => $r->note, 'series' => $r->series_id !== null,
        ], $rows);
    }

    /**
     * @param  list<string>  $names
     * @return list<array{name: string, fee_cents: int}>
     */
    private function chosenServices(Resource $res, array $names): array
    {
        $out = [];
        foreach (array_unique($names) as $n) {
            $found = null;
            foreach ($res->services as $s) {
                if ($s['name'] === $n) {
                    $found = $s;
                }
            }
            $out[] = $found ?? throw new ResourceError('Servizio aggiuntivo non previsto: '.$n.'.');
        }

        return $out;
    }

    private static function moment(string $v): string
    {
        $v = str_replace('T', ' ', trim($v));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) ([01]\d|2[0-3]):([0-5]\d)$/', $v, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ResourceError('Data e ora non valide.');
        }

        return $v.':00';
    }

    private static function filled(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    private static function note(mixed $v): ?string
    {
        $v = self::filled($v);

        return $v === null ? null : mb_substr($v, 0, 255);
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
