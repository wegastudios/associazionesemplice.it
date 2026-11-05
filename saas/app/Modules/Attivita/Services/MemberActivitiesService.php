<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\MemberActivities;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Models\ActivitySession;
use App\Modules\Attivita\Models\Enrollment;
use App\Modules\Attivita\Models\SessionBooking;
use App\Modules\Attivita\Rules\SessionRules;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\DatabaseManager;

/** Le attività viste dall'area soci (vedi il contratto). */
final readonly class MemberActivitiesService implements MemberActivities
{
    public const DAYS_AHEAD = 60;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private ActivityService $activities,
        private SessionService $sessions,
    ) {}

    public function enrollments(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $byActivity = [];
        foreach (Enrollment::query()->whereIn('person_id', $personIds)->get(['activity_id', 'person_id']) as $e) {
            $byActivity[$e->activity_id][] = $e->person_id;
        }
        $out = [];
        foreach (Activity::query()->whereIn('id', array_keys($byActivity))->whereNull('archived_at')->orderByDesc('social_year')->orderBy('name')->get() as $a) {
            foreach ($this->activities->status($a) as $s) {
                if (in_array($s['person_id'], $byActivity[$a->id], true)) {
                    $out[] = [
                        'person_id' => $s['person_id'], 'activity' => $a->name, 'kind' => $a->kind->label(),
                        'from' => ActivityService::monthLabel($s['start_month']), 'to' => $s['end_month'] === null ? null : ActivityService::monthLabel($s['end_month']),
                        'due' => $s['summary']['total_due'], 'paid' => $s['summary']['total_paid'], 'regular' => $s['summary']['regular'],
                    ];
                }
            }
        }

        return $out;
    }

    public function upcoming(string $personId): array
    {
        $today = now('Europe/Rome')->toDateString();
        $until = now('Europe/Rome')->addDays(self::DAYS_AHEAD)->toDateString();
        $activities = Activity::query()->where('kind', 'recurring')->whereNull('archived_at')->get()->keyBy('id');
        if ($activities->isEmpty()) {
            return [];
        }
        $sessions = ActivitySession::query()->whereIn('activity_id', $activities->keys())->whereNull('cancelled_at')
            ->whereBetween('session_date', [$today, $until])->orderBy('session_date')->orderBy('start_time')->limit(200)->get();
        $ids = array_values($sessions->map(static fn (ActivitySession $s): string => $s->id)->all());
        if ($ids === []) {
            return [];
        }
        $c = $this->db->connection($this->context->require()->connection);
        $taken = [];
        foreach ($c->table('app.session_bookings')->whereIn('session_id', $ids)->where('status', 'booked')->groupBy('session_id')->selectRaw('session_id, count(*) AS n')->get() as $r) {
            if (is_string($r->session_id ?? null)) {
                $taken[$r->session_id] = is_numeric($r->n ?? null) ? (int) $r->n : 0;
            }
        }
        $mine = SessionBooking::query()->whereIn('session_id', $ids)->where('person_id', $personId)->where('status', 'booked')->get()->keyBy('session_id');
        $paid = $this->sessions->paid(array_values($mine->map(static fn (SessionBooking $b): string => $b->id)->all()));
        $waiting = $c->table('app.session_waitlist')->whereIn('session_id', $ids)->where('person_id', $personId)->where('status', 'waiting')->pluck('session_id')->all();

        $out = [];
        foreach ($sessions as $s) {
            $a = $activities->get($s->activity_id);
            if (! $a instanceof Activity) {
                continue;
            }
            $b = $mine->get($s->id);
            $out[] = [
                'session_id' => $s->id, 'activity_id' => $a->id, 'activity' => $a->name, 'date' => $s->session_date->format('Y-m-d'),
                'start' => $s->start_time, 'end' => $s->end_time, 'location' => $s->location ?? $a->location, 'capacity' => $s->capacity,
                'free' => SessionRules::free($s->capacity, $taken[$s->id] ?? 0), 'booking_id' => $b instanceof SessionBooking ? $b->id : null,
                'waiting' => in_array($s->id, $waiting, true), 'fee' => $b instanceof SessionBooking ? $b->fee_due_cents : $a->fee_cents,
                'paid' => $b instanceof SessionBooking ? ($paid[$b->id] ?? 0) : 0,
                'cancellable' => $b instanceof SessionBooking && $b->package_of === null && $b->package_entries <= 1 && $b->price_list === null
                    && ! isset($paid[$b->id]) && $s->session_date->format('Y-m-d') > $today,
            ];
        }

        return $out;
    }

    public function book(string $sessionId, string $personId): void
    {
        $this->assertBookable($sessionId);
        $this->sessions->book($sessionId, $personId);
    }

    public function cancel(string $bookingId, string $personId): array
    {
        $b = $this->sessions->booking($bookingId);
        if ($b->person_id !== $personId || $b->status !== 'booked') {
            throw new ActivityError('Prenotazione non trovata.');
        }
        if ($b->package_of !== null || $b->package_entries > 1 || $b->price_list !== null) {
            throw new ActivityError('Questa prenotazione fa parte di un listino o di un pacchetto: per annullarla scrivi alla segreteria.');
        }
        if ($this->sessions->paid([$b->id]) !== []) {
            throw new ActivityError('Questa prenotazione è già pagata: per annullarla scrivi alla segreteria.');
        }
        $s = $this->sessions->session($b->session_id);
        if ($s->session_date->format('Y-m-d') <= now('Europe/Rome')->toDateString()) {
            throw new ActivityError('Si annulla fino al giorno prima: ormai scrivi alla segreteria.');
        }

        return $this->sessions->cancelBooking($b->id);
    }

    public function joinWaitlist(string $sessionId, string $personId): void
    {
        $this->assertBookable($sessionId);
        $this->sessions->waitlistJoin($sessionId, $personId);
    }

    private function assertBookable(string $sessionId): void
    {
        $s = $this->sessions->session($sessionId);
        $a = $this->activities->find($s->activity_id);
        $today = now('Europe/Rome')->toDateString();
        if (! $a->kind->usesSessions() || $a->archived_at !== null || $s->cancelled_at !== null || $s->session_date->format('Y-m-d') < $today
            || $s->session_date->format('Y-m-d') > now('Europe/Rome')->addDays(self::DAYS_AHEAD)->toDateString()) {
            throw new ActivityError('Questa data non si prenota dall\'area soci.');
        }
    }
}
