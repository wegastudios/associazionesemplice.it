<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Export;

use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\Csv;
use App\Modules\Risorse\Models\Resource;
use App\Modules\Risorse\Rules\ResourceRules;
use Illuminate\Database\DatabaseManager;

/** Risorse, prenotazioni (anche annullate) e lista d'attesa. */
final readonly class RisorseDataExport implements DataExport
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
        $resources = Resource::query()->orderBy('name')->get();
        if ($resources->isEmpty()) {
            return [];
        }
        $names = [];
        $rows = [];
        foreach ($resources as $r) {
            $names[$r->id] = $r->name;
            $rows[] = [$r->name, ResourceRules::categories()[$r->category] ?? $r->category, $r->typology->label(), (string) $r->open_time, (string) $r->close_time,
                (string) $r->slot_minutes, Money::plain($r->fee_cents), $r->nonmember_fee_cents === null ? '' : Money::plain($r->nonmember_fee_cents),
                ResourceRules::ratesText($r->rates), ResourceRules::servicesText($r->services), $r->archived_at !== null ? 'sì' : ''];
        }
        $files = ['risorse/risorse.csv' => Csv::build(['Risorsa', 'Tipo', 'Si prenota', 'Apre', 'Chiude', 'Minuti a fascia', 'Tariffa', 'Tariffa non soci', 'Tariffe a fasce', 'Servizi', 'Archiviata'], $rows)];

        $c = $this->db->connection($this->context->require()->connection);
        $s = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        $rows = [];
        foreach ($c->table('app.reservations as r')->leftJoin('app.people as p', 'p.id', '=', 'r.person_id')->orderBy('r.starts_at')
            ->get(['r.resource_id', 'r.kind', 'r.starts_at', 'r.ends_at', 'p.last_name', 'p.first_name', 'r.holder_name', 'r.member', 'r.fee_due_cents', 'r.status', 'r.note']) as $b) {
            $rows[] = [$names[$s($b->resource_id ?? '')] ?? '', $b->kind === 'block' ? 'Blocco' : 'Prenotazione', $s($b->starts_at ?? ''), $s($b->ends_at ?? ''),
                trim($s($b->last_name ?? '').' '.$s($b->first_name ?? '')) ?: $s($b->holder_name ?? ''), ($b->member ?? false) === true ? 'sì' : 'no',
                Money::plain(is_numeric($b->fee_due_cents ?? null) ? (int) $b->fee_due_cents : 0), $b->status === 'cancelled' ? 'annullata' : 'attiva', $s($b->note ?? '')];
        }
        $files['risorse/prenotazioni.csv'] = Csv::build(['Risorsa', 'Tipo', 'Inizio', 'Fine', 'Chi', 'Socio', 'Dovuto', 'Stato', 'Note'], $rows);

        $rows = [];
        foreach ($c->table('app.resource_waitlist as w')->join('app.people as p', 'p.id', '=', 'w.person_id')->orderBy('w.created_at')
            ->get(['w.resource_id', 'p.last_name', 'p.first_name', 'w.status', 'w.created_at', 'w.note']) as $w) {
            $rows[] = [$names[$s($w->resource_id ?? '')] ?? '', trim($s($w->last_name ?? '').' '.$s($w->first_name ?? '')),
                ['waiting' => 'in attesa', 'done' => 'assegnata', 'left' => 'uscito'][$s($w->status ?? '')] ?? '', substr($s($w->created_at ?? ''), 0, 10), $s($w->note ?? '')];
        }
        $files['risorse/lista-d-attesa.csv'] = Csv::build(['Risorsa', 'Persona', 'Stato', 'Dal', 'Note'], $rows);

        return $files;
    }
}
