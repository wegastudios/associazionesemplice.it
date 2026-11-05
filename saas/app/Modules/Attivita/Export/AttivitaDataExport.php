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

        $c = $this->db->connection($this->context->require()->connection);
        $rows = [];
        foreach ($c->table('app.session_bookings as b')->join('app.activity_sessions as s', 's.id', '=', 'b.session_id')->join('app.activities as a', 'a.id', '=', 's.activity_id')
            ->join('app.people as p', 'p.id', '=', 'b.person_id')->orderBy('s.session_date')->orderBy('a.name')->orderBy('p.last_name')
            ->get(['a.name', 's.session_date', 's.start_time', 's.cancelled_at', 'p.last_name', 'p.first_name', 'b.fee_due_cents', 'b.price_list', 'b.status']) as $b) {
            $rows[] = [$s($b->name ?? ''), substr($s($b->session_date ?? ''), 0, 10), $s($b->start_time ?? ''), trim($s($b->last_name ?? '').' '.$s($b->first_name ?? '')),
                Money::plain(is_numeric($b->fee_due_cents ?? null) ? (int) $b->fee_due_cents : 0), $s($b->price_list ?? ''),
                $b->status === 'cancelled' ? 'annullata' : (($b->cancelled_at ?? null) !== null ? 'data annullata' : 'attiva')];
        }
        $files['attivita/prenotazioni.csv'] = Csv::build(['Attività', 'Data', 'Ora', 'Persona', 'Dovuto', 'Listino', 'Stato'], $rows);

        $rows = [];
        foreach ($c->table('app.attendance as t')->join('app.activities as a', 'a.id', '=', 't.activity_id')->join('app.people as p', 'p.id', '=', 't.person_id')
            ->orderBy('t.lesson_date')->orderBy('a.name')->orderBy('p.last_name')->get(['a.name', 't.lesson_date', 'p.last_name', 'p.first_name', 't.present']) as $t) {
            $rows[] = [$s($t->name ?? ''), substr($s($t->lesson_date ?? ''), 0, 10), trim($s($t->last_name ?? '').' '.$s($t->first_name ?? '')), ($t->present ?? false) === true ? 'presente' : 'assente'];
        }
        $files['attivita/presenze.csv'] = Csv::build(['Attività', 'Data', 'Persona', 'Presenza'], $rows);

        return $files;
    }
}
