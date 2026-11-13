<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Soci\Rules\Age;
use App\Modules\Soci\Services\RuntsCounts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Dati per il RUNTS: i numeri al 31 dicembre da comunicare entro il 30 giugno. */
final readonly class RuntsController
{
    public function __construct(private RuntsCounts $counts) {}

    public function index(Request $request): Response
    {
        $today = now();
        $thisYear = (int) $today->format('Y');
        $asked = $request->query('anno');
        // Di norma l'anno appena chiuso; fino al 30 giugno è ancora da comunicare.
        $year = is_string($asked) && ctype_digit($asked) && (int) $asked >= 2000 && (int) $asked < $thisYear ? (int) $asked : $thisYear - 1;
        $date = $year.'-12-31';
        $deadline = ($year + 1).'-06-30';

        return Inertia::render('Soci/Runts', [
            'year' => $year,
            'years' => range($thisYear - 1, max(2000, $thisYear - 6)),
            'counts' => $this->counts->at($date),
            'deadline' => Age::display($deadline),
            'overdue' => $today->toDateString() > $deadline,
            'min' => ['persons' => RuntsCounts::MIN_PERSONS, 'entities' => RuntsCounts::MIN_ENTITIES],
        ]);
    }
}
