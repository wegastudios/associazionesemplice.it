<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Home;

use App\Modules\Attivita\Services\AttendanceService;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\HomeItem;
use App\Modules\Core\Contracts\HomeWidget;
use App\Modules\Core\Contracts\Operator;

/** Home: lezioni ed eventi di oggi. */
final readonly class ActivitiesHome implements HomeWidget
{
    public function __construct(private AttendanceService $attendance) {}

    public function items(Operator $operator): array
    {
        if (! $operator->can(Ability::ViewPeople)) {
            return [];
        }
        $today = now('Europe/Rome')->toDateString();
        $out = [];
        foreach ($this->attendance->calendar($today, $today) as $x) {
            $detail = $x['taken'] !== null ? $x['taken'].($x['capacity'] !== null ? ' su '.$x['capacity'] : '').' prenotati' : $x['kind'];
            $out[] = HomeItem::today($x['start'] ?? '00:00', $x['title'], $detail, $x['session_id'] !== null ? '/gestionale/attivita/date/'.$x['session_id'] : '/gestionale/attivita/'.$x['activity_id']);
        }

        return $out;
    }
}
