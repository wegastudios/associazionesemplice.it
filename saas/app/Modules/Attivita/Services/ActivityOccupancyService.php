<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use App\Modules\Attivita\Contracts\ActivityOccupancy;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;

/** Quando le attività occupano le risorse (date con orario), viste da fuori del modulo. */
final readonly class ActivityOccupancyService implements ActivityOccupancy
{
    public function __construct(private DatabaseManager $db) {}

    public function busy(string $resourceId, string $from, string $to, ?string $exceptSessionId = null): array
    {
        if (! Str::isUuid($resourceId)) {
            return [];
        }
        $q = $this->db->connection('pgsql')->table('app.activity_sessions as s')
            ->join('app.activities as a', static fn (JoinClause $j) => $j->on('a.org_id', '=', 's.org_id')->on('a.id', '=', 's.activity_id'))
            ->where('a.resource_id', $resourceId)->whereNull('a.archived_at')->whereNull('s.cancelled_at')
            ->whereNotNull('s.start_time')->whereNotNull('s.end_time')
            ->whereRaw('(s.session_date + s.start_time::time) < ?::timestamp', [$to])
            ->whereRaw('(s.session_date + s.end_time::time) > ?::timestamp', [$from])
            ->whereRaw("(not a.occupy_on_booking or exists (select 1 from app.session_bookings b where b.org_id = s.org_id and b.session_id = s.id and b.status = 'booked'))");
        if ($exceptSessionId !== null && Str::isUuid($exceptSessionId)) {
            $q->where('s.id', '<>', $exceptSessionId);
        }

        $out = [];
        foreach ($q->orderBy('s.session_date')->orderBy('s.start_time')->get(['s.id', 's.session_date', 's.start_time', 's.end_time', 'a.name']) as $r) {
            $day = substr(self::text($r->session_date), 0, 10);
            $out[] = ['id' => self::text($r->id), 'from' => $day.' '.self::text($r->start_time).':00', 'to' => $day.' '.self::text($r->end_time).':00', 'label' => self::text($r->name)];
        }

        return $out;
    }

    private static function text(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
