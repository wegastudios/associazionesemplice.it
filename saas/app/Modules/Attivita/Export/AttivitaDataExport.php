<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Export;

use App\Modules\Attivita\Models\Activity;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\Csv;
use Illuminate\Database\DatabaseManager;

/** Attività e iscrizioni. */
final readonly class AttivitaDataExport implements DataExport
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function files(Operator $operator): array
    {
        if (! $operator->can(Ability::ViewPeople)) {
            return [];
        }
        $rows = [];
        foreach (Activity::query()->orderBy('social_year')->orderBy('name')->get() as $a) {
            $rows[] = [$a->name, $a->kind->label(), $a->social_year, $a->billing->label(), Money::plain($a->fee_cents), $a->guest_fee_cents === null ? '' : Money::plain($a->guest_fee_cents),
                $a->starts_on?->format('d/m/Y') ?? '', $a->ends_on?->format('d/m/Y') ?? '', (string) $a->location, $a->archived_at !== null ? 'sì' : ''];
        }
        $files = ['attivita/attivita.csv' => Csv::build(['Attività', 'Tipo', 'Anno sociale', 'Contributo', 'Importo', 'Importo ospiti', 'Inizio', 'Fine', 'Luogo', 'Archiviata'], $rows)];

        $rows = [];
        $q = $this->db->connection($this->context->require()->connection)->table('app.enrollments as e')
            ->join('app.activities as a', 'a.id', '=', 'e.activity_id')->join('app.people as p', 'p.id', '=', 'e.person_id')
            ->orderBy('a.name')->orderBy('p.last_name')->get(['a.name', 'a.social_year', 'p.last_name', 'p.first_name', 'e.start_month', 'e.end_month']);
        $s = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        foreach ($q as $e) {
            $rows[] = [$s($e->name ?? ''), $s($e->social_year ?? ''), trim($s($e->last_name ?? '').' '.$s($e->first_name ?? '')), $s($e->start_month ?? ''), $s($e->end_month ?? '')];
        }
        $files['attivita/iscrizioni.csv'] = Csv::build(['Attività', 'Anno sociale', 'Persona', 'Dal mese', 'Fino al mese'], $rows);

        return $files;
    }
}
