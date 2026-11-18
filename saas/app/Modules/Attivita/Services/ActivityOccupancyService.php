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

    public function busy(string $resourceId, string $from, string $to, ?string $exceptSessionId = null, ?string $exceptActivityId = null, ?string $exceptBlock = null): array
    {
        if (! Str::isUuid($resourceId)) {
            return [];
        }
        $out = [...$this->sessions($resourceId, $from, $to, $exceptSessionId, $exceptActivityId), ...$this->courses($resourceId, $from, $to, $exceptActivityId),
            ...$this->blocks($resourceId, $from, $to, $exceptSessionId, $exceptActivityId, $exceptBlock)];
        usort($out, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return $out;
    }

    public function blockSpan(string $resourceId, string $key, array $extra = [], ?string $exceptSessionId = null): ?array
    {
        $windows = $extra;
        foreach ($this->blockRows($resourceId, $key, $exceptSessionId) as $r) {
            $windows[] = $r['window'];
        }
        if ($windows === []) {
            return null;
        }

        return ['from' => min(array_column($windows, 0)), 'to' => max(array_column($windows, 1))];
    }

    /**
     * Le date non annullate degli eventi in un blocco sulla risorsa (tutti i blocchi, o solo $key).
     *
     * @return list<array{key: string, activity_id: string, name: string, window: array{0: string, 1: string}}>
     */
    private function blockRows(string $resourceId, ?string $key, ?string $exceptSessionId): array
    {
        if (! Str::isUuid($resourceId)) {
            return [];
        }
        $q = $this->db->connection('pgsql')->table('app.activity_sessions as s')
            ->join('app.activities as a', static fn (JoinClause $j) => $j->on('a.org_id', '=', 's.org_id')->on('a.id', '=', 's.activity_id'))
            ->where('a.resource_id', $resourceId)->whereNotNull('a.resource_block')->where('a.occupy_on_booking', false)->whereNull('a.archived_at')->whereNull('s.cancelled_at');
        if ($key !== null) {
            $q->where('a.resource_block', $key);
        }
        if ($exceptSessionId !== null && Str::isUuid($exceptSessionId)) {
            $q->where('s.id', '<>', $exceptSessionId);
        }
        $out = [];
        foreach ($q->get(['a.id as activity_id', 'a.name', 'a.resource_block', 's.session_date', 's.end_date', 's.start_time', 's.end_time']) as $r) {
            $start = is_scalar($r->start_time) ? (string) $r->start_time : null;
            $end = is_scalar($r->end_time) ? (string) $r->end_time : null;
            $endDate = is_scalar($r->end_date) ? substr((string) $r->end_date, 0, 10) : null;
            $out[] = ['key' => self::text($r->resource_block), 'activity_id' => self::text($r->activity_id), 'name' => self::text($r->name),
                'window' => SessionRules::slot(substr(self::text($r->session_date), 0, 10), $start, $end, $endDate)];
        }

        return $out;
    }

    /**
     * Un periodo per blocco, dal primo inizio all'ultima fine delle date di tutti gli eventi collegati: si ricalcola a ogni
     * richiesta, non è una prenotazione. Ogni evento del blocco occupa il periodo intero, tra un evento e l'altro compreso.
     *
     * @return list<array{id: string, activity_id: string, from: string, to: string, label: string}>
     */
    private function blocks(string $resourceId, string $from, string $to, ?string $exceptSessionId, ?string $exceptActivityId, ?string $exceptBlock): array
    {
        $spans = [];
        $names = [];
        foreach ($this->blockRows($resourceId, null, $exceptSessionId) as $r) {
            $k = $r['key'];
            $spans[$k] = isset($spans[$k]) ? [min($spans[$k][0], $r['window'][0]), max($spans[$k][1], $r['window'][1])] : $r['window'];
            $names[$k][$r['activity_id']] = $r['name'];
        }
        $out = [];
        foreach ($spans as $k => $span) {
            if ($k === $exceptBlock || $span[0] >= $to || $span[1] <= $from) {
                continue;
            }
            foreach ($names[$k] as $activityId => $name) {
                if ($activityId !== $exceptActivityId) {
                    $out[] = ['id' => $activityId, 'activity_id' => $activityId, 'from' => $span[0], 'to' => $span[1], 'label' => $name];
                }
            }
        }

        return $out;
    }

    /** @return list<array{id: string, activity_id: string, from: string, to: string, label: string}> */
    private function sessions(string $resourceId, string $from, string $to, ?string $exceptSessionId, ?string $exceptActivityId): array
    {
        $q = $this->db->connection('pgsql')->table('app.activity_sessions as s')
            ->join('app.activities as a', static fn (JoinClause $j) => $j->on('a.org_id', '=', 's.org_id')->on('a.id', '=', 's.activity_id'))
            ->where('a.resource_id', $resourceId)->whereNull('a.archived_at')->whereNull('s.cancelled_at')
            ->whereRaw("(s.session_date + coalesce(s.start_time, '00:00')::time) < ?::timestamp", [$to])
            ->whereRaw('(case when s.end_time is null then (coalesce(s.end_date, s.session_date) + 1)::timestamp else (coalesce(s.end_date, s.session_date) + s.end_time::time) end) > ?::timestamp', [$from])
            ->whereRaw('(a.resource_block is null or a.occupy_on_booking)')
            ->whereRaw("(not a.occupy_on_booking or exists (select 1 from app.session_bookings b where b.org_id = s.org_id and b.session_id = s.id and b.status = 'booked'))");
        if ($exceptSessionId !== null && Str::isUuid($exceptSessionId)) {
            $q->where('s.id', '<>', $exceptSessionId);
        }
        if ($exceptActivityId !== null && Str::isUuid($exceptActivityId)) {
            $q->where('a.id', '<>', $exceptActivityId);
        }

        $out = [];
        foreach ($q->orderBy('s.session_date')->orderBy('s.start_time')->get(['s.id', 'a.id as activity_id', 's.session_date', 's.end_date', 's.start_time', 's.end_time', 'a.name']) as $r) {
            $start = is_scalar($r->start_time) ? (string) $r->start_time : null;
            $end = is_scalar($r->end_time) ? (string) $r->end_time : null;
            $endDate = is_scalar($r->end_date) ? substr((string) $r->end_date, 0, 10) : null;
            [$f, $t] = SessionRules::slot(substr(self::text($r->session_date), 0, 10), $start, $end, $endDate);
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
