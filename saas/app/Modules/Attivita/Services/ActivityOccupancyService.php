<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use App\Modules\Attivita\Contracts\ActivityOccupancy;
use App\Modules\Attivita\Rules\SessionRules;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;

/**
 * Quando le attività occupano le risorse, viste da fuori del modulo. Una data senza orari occupa il giorno intero; un
 * corso occupa il giorno intero nei giorni delle sue lezioni.
 */
final readonly class ActivityOccupancyService implements ActivityOccupancy
{
    /** Giorni al massimo da guardare per i corsi (una prenotazione senza fine arriva fino al 9999). */
    private const COURSE_HORIZON_DAYS = 3660;

    public function __construct(private DatabaseManager $db) {}

    public function busy(string $resourceId, string $from, string $to, ?string $exceptSessionId = null, ?string $exceptActivityId = null): array
    {
        if (! Str::isUuid($resourceId)) {
            return [];
        }
        $out = [...$this->sessions($resourceId, $from, $to, $exceptSessionId, $exceptActivityId), ...$this->courses($resourceId, $from, $to, $exceptActivityId)];
        usort($out, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return $out;
    }

    /** @return list<array{id: string, activity_id: string, from: string, to: string, label: string}> */
    private function sessions(string $resourceId, string $from, string $to, ?string $exceptSessionId, ?string $exceptActivityId): array
    {
        $q = $this->db->connection('pgsql')->table('app.activity_sessions as s')
            ->join('app.activities as a', static fn (JoinClause $j) => $j->on('a.org_id', '=', 's.org_id')->on('a.id', '=', 's.activity_id'))
            ->where('a.resource_id', $resourceId)->whereNull('a.archived_at')->whereNull('s.cancelled_at')
            ->whereRaw("(s.session_date + coalesce(s.start_time, '00:00')::time) < ?::timestamp", [$to])
            ->whereRaw('(case when s.end_time is null then (s.session_date + 1)::timestamp else (s.session_date + s.end_time::time) end) > ?::timestamp', [$from])
            ->whereRaw("(not a.occupy_on_booking or exists (select 1 from app.session_bookings b where b.org_id = s.org_id and b.session_id = s.id and b.status = 'booked'))");
        if ($exceptSessionId !== null && Str::isUuid($exceptSessionId)) {
            $q->where('s.id', '<>', $exceptSessionId);
        }
        if ($exceptActivityId !== null && Str::isUuid($exceptActivityId)) {
            $q->where('a.id', '<>', $exceptActivityId);
        }

        $out = [];
        foreach ($q->orderBy('s.session_date')->orderBy('s.start_time')->get(['s.id', 'a.id as activity_id', 's.session_date', 's.start_time', 's.end_time', 'a.name']) as $r) {
            $start = is_scalar($r->start_time) ? (string) $r->start_time : null;
            $end = is_scalar($r->end_time) ? (string) $r->end_time : null;
            [$f, $t] = SessionRules::slot(substr(self::text($r->session_date), 0, 10), $start, $end);
            $out[] = ['id' => self::text($r->id), 'activity_id' => self::text($r->activity_id), 'from' => $f, 'to' => $t, 'label' => self::text($r->name)];
        }

        return $out;
    }

    /** @return list<array{id: string, activity_id: string, from: string, to: string, label: string}> */
    private function courses(string $resourceId, string $from, string $to, ?string $exceptActivityId): array
    {
        $first = substr($from, 0, 10);
        $last = min(substr(date('Y-m-d H:i:s', (int) strtotime($to) - 1), 0, 10), date('Y-m-d', (int) strtotime($first.' +'.self::COURSE_HORIZON_DAYS.' days')));
        if ($last < $first) {
            return [];
        }
        $q = $this->db->connection('pgsql')->table('app.activities')
            ->where('kind', 'course')->where('resource_id', $resourceId)->whereNull('archived_at')->whereNotNull('starts_on')
            ->where('starts_on', '<=', $last)->where(static fn (Builder $w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $first));
        if ($exceptActivityId !== null && Str::isUuid($exceptActivityId)) {
            $q->where('id', '<>', $exceptActivityId);
        }

        $out = [];
        foreach ($q->get(['id', 'name', 'weekdays', 'starts_on', 'ends_on']) as $a) {
            $weekdays = array_values(array_map('intval', array_filter(explode(',', trim(self::text($a->weekdays), '{}')), 'is_numeric')));
            $ends = $a->ends_on === null ? null : substr(self::text($a->ends_on), 0, 10);
            foreach (SessionRules::courseDays($weekdays, substr(self::text($a->starts_on), 0, 10), $ends, $first, self::COURSE_HORIZON_DAYS) as $day) {
                if ($day <= $last) {
                    [$f, $t] = SessionRules::slot($day, null, null);
                    $out[] = ['id' => self::text($a->id), 'activity_id' => self::text($a->id), 'from' => $f, 'to' => $t, 'label' => self::text($a->name)];
                }
            }
        }

        return $out;
    }

    private static function text(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
