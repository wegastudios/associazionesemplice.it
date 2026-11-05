<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\ActivityKind;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Rules\SessionRules;
use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Presenze e calendario (da Attendance e Calendar del plugin). Le lezioni di un corso sono i suoi giorni della
 * settimana nel periodo del corso; un evento ha la sua data; un evento con prenotazioni le sue date non annullate.
 * Chi è atteso: gli iscritti al corso in quel mese, gli iscritti all'evento, i prenotati alla data.
 */
final readonly class AttendanceService
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private ActivityService $activities,
        private AuditLog $audit,
    ) {}

    /** @return list<string> le date in cui si tiene l'attività tra $from e $to (comprese) */
    public function lessonDates(Activity $a, string $from, string $to): array
    {
        if ($a->kind->usesSessions()) {
            $dates = $this->connection()->table('app.activity_sessions')->where('activity_id', $a->id)->whereNull('cancelled_at')
                ->whereBetween('session_date', [$from, $to])->orderBy('session_date')->distinct()->pluck('session_date')->all();

            return array_values(array_map(static fn (mixed $d): string => is_string($d) ? substr($d, 0, 10) : '', $dates));
        }
        if ($a->kind === ActivityKind::Event) {
            $d = $a->starts_on?->format('Y-m-d');

            return $d !== null && $d >= $from && $d <= $to ? [$d] : [];
        }
        $year = $this->activities->year($a);

        return SessionRules::lessonDates($a->weekdays, $a->starts_on?->format('Y-m-d') ?? $year['start'], $a->ends_on?->format('Y-m-d') ?? $year['end'], $from, $to);
    }

    /** @return list<array{id: string, name: string}> chi è atteso a quella data */
    public function roster(Activity $a, string $date): array
    {
        $c = $this->connection();
        if ($a->kind->usesSessions()) {
            $q = $c->table('app.people as p')->join('app.session_bookings as b', 'b.person_id', '=', 'p.id')->join('app.activity_sessions as s', 's.id', '=', 'b.session_id')
                ->where('s.activity_id', $a->id)->where('s.session_date', $date)->whereNull('s.cancelled_at')->where('b.status', 'booked');
        } else {
            $ym = substr($date, 0, 7);
            $q = $c->table('app.people as p')->join('app.enrollments as e', 'e.person_id', '=', 'p.id')->where('e.activity_id', $a->id);
            if ($a->kind === ActivityKind::Course) {
                $q->where('e.start_month', '<=', $ym)->where(static fn (Builder $w) => $w->whereNull('e.end_month')->orWhere('e.end_month', '>=', $ym));
            } else {
                $q->whereNull('e.end_month');
            }
        }
        $out = [];
        foreach ($q->whereNull('p.deleted_at')->orderBy('p.last_name')->orderBy('p.first_name')->distinct()->get(['p.id', 'p.first_name', 'p.last_name']) as $p) {
            if (is_string($p->id ?? null)) {
                $out[] = ['id' => $p->id, 'name' => trim((is_string($p->last_name ?? null) ? $p->last_name : '').' '.(is_string($p->first_name ?? null) ? $p->first_name : ''))];
            }
        }

        return $out;
    }

    /** @return array<string, bool> persona => presente, per una data già registrata (vuoto se non ancora) */
    public function marks(string $activityId, string $date): array
    {
        $out = [];
        foreach ($this->connection()->table('app.attendance')->where('activity_id', $activityId)->where('lesson_date', $date)->get(['person_id', 'present']) as $r) {
            if (is_string($r->person_id ?? null)) {
                $out[$r->person_id] = (bool) $r->present;
            }
        }

        return $out;
    }

    /**
     * Registra la lezione: chi è in $present è presente, gli altri attesi assenti. Si registra a lezione fatta.
     *
     * @param  list<string>  $present
     * @return int righe registrate
     */
    public function save(string $activityId, string $date, array $present, ?string $userId): int
    {
        $a = $this->activities->find($activityId);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new ActivityError('Data non valida.');
        }
        if ($date > now('Europe/Rome')->toDateString()) {
            throw new ActivityError('Le presenze si registrano a lezione fatta, non in anticipo.');
        }
        if ($this->lessonDates($a, $date, $date) === []) {
            throw new ActivityError('In quella data l\'attività non ha lezione.');
        }
        $roster = $this->roster($a, $date);
        $here = array_flip($present);
        $org = $this->context->require()->id;
        $c = $this->connection();
        $c->transaction(function () use ($c, $a, $date, $roster, $here, $org, $userId): void {
            $c->table('app.attendance')->where('activity_id', $a->id)->where('lesson_date', $date)->delete();
            foreach ($roster as $p) {
                $c->table('app.attendance')->insert(['org_id' => $org, 'activity_id' => $a->id, 'lesson_date' => $date, 'person_id' => $p['id'],
                    'present' => isset($here[$p['id']]), 'marked_by' => $userId]);
            }
        });
        $this->audit->record('attendance.saved', 'app.activities', $a->id, ['date' => $date, 'expected' => count($roster),
            'present' => count(array_filter($roster, static fn (array $p): bool => isset($here[$p['id']])))]);

        return count($roster);
    }

    /**
     * Riepilogo del periodo: lezioni registrate e, per persona, presenze sul totale.
     *
     * @return array{dates: list<string>, people: list<array{id: string, name: string, present: int, total: int, by_date: array<string, bool>}>}
     */
    public function summary(string $activityId, string $from, string $to): array
    {
        $rows = $this->connection()->table('app.attendance as a')->join('app.people as p', 'p.id', '=', 'a.person_id')
            ->where('a.activity_id', $activityId)->whereBetween('a.lesson_date', [$from, $to])->orderBy('a.lesson_date')
            ->get(['a.person_id', 'a.lesson_date', 'a.present', 'p.first_name', 'p.last_name']);
        $dates = [];
        $people = [];
        foreach ($rows as $r) {
            if (! is_string($r->person_id ?? null) || ! is_string($r->lesson_date ?? null)) {
                continue;
            }
            $d = substr($r->lesson_date, 0, 10);
            $dates[$d] = true;
            $people[$r->person_id] ??= ['id' => $r->person_id, 'name' => trim((is_string($r->last_name ?? null) ? $r->last_name : '').' '.(is_string($r->first_name ?? null) ? $r->first_name : '')),
                'present' => 0, 'total' => 0, 'by_date' => []];
            $people[$r->person_id]['total']++;
            $people[$r->person_id]['present'] += $r->present ? 1 : 0;
            $people[$r->person_id]['by_date'][$d] = (bool) $r->present;
        }
        $list = array_values($people);
        usort($list, static fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));

        return ['dates' => array_keys($dates), 'people' => $list];
    }

    /**
     * Lezioni e date di tutte le attività attive tra due date, in ordine.
     *
     * @return list<array{date: string, start: string|null, end: string|null, title: string, kind: string, activity_id: string, location: string|null, session_id: string|null, taken: int|null, capacity: int|null}>
     */
    public function calendar(string $from, string $to): array
    {
        $out = [];
        $activities = Activity::query()->whereNull('archived_at')->get();
        foreach ($activities as $a) {
            if ($a->kind->usesSessions()) {
                continue;
            }
            foreach ($this->lessonDates($a, $from, $to) as $d) {
                $out[] = ['date' => $d, 'start' => null, 'end' => null, 'title' => $a->name, 'kind' => $a->kind->label(), 'activity_id' => $a->id,
                    'location' => $a->location, 'session_id' => null, 'taken' => null, 'capacity' => null];
            }
        }
        $names = [];
        foreach ($activities as $a) {
            $names[$a->id] = $a;
        }
        $sessions = $this->connection()->table('app.activity_sessions as s')->whereNull('s.cancelled_at')->whereBetween('s.session_date', [$from, $to])
            ->whereIn('s.activity_id', array_keys($names))
            ->selectRaw("s.id, s.activity_id, s.session_date, s.start_time, s.end_time, s.location, s.capacity, (SELECT count(*) FROM app.session_bookings b WHERE b.session_id = s.id AND b.status = 'booked') AS taken")
            ->get();
        foreach ($sessions as $s) {
            $a = is_string($s->activity_id ?? null) ? ($names[$s->activity_id] ?? null) : null;
            if ($a === null || ! is_string($s->id ?? null) || ! is_string($s->session_date ?? null)) {
                continue;
            }
            $out[] = ['date' => substr($s->session_date, 0, 10), 'start' => is_string($s->start_time ?? null) ? $s->start_time : null, 'end' => is_string($s->end_time ?? null) ? $s->end_time : null,
                'title' => $a->name, 'kind' => $a->kind->label(), 'activity_id' => $a->id, 'location' => is_string($s->location ?? null) ? $s->location : $a->location,
                'session_id' => $s->id, 'taken' => is_numeric($s->taken ?? null) ? (int) $s->taken : 0, 'capacity' => is_numeric($s->capacity ?? null) ? (int) $s->capacity : null];
        }
        usort($out, static fn (array $x, array $y): int => [$x['date'], $x['start'] ?? '', mb_strtolower($x['title'])] <=> [$y['date'], $y['start'] ?? '', mb_strtolower($y['title'])]);

        return $out;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
