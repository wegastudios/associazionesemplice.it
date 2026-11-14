<?php

declare(strict_types=1);

namespace App\Modules\Soci\Export;

use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\Csv;
use App\Modules\Soci\Contracts\CertificateKind;
use App\Modules\Soci\Models\Application;
use App\Modules\Soci\Models\MedicalCertificate;
use App\Modules\Soci\Services\MemberBookService;
use App\Modules\Soci\Services\MemberBookSnapshots;
use App\Modules\Soci\Services\PeopleExportService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/** Anagrafica, libro soci, tessere, tutori, consensi, livelli, domande e (per chi può) certificati medici. */
final readonly class SociDataExport implements DataExport
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private PeopleExportService $people,
        private MemberBookService $book,
        private MemberBookSnapshots $snapshots,
    ) {}

    public function files(Operator $operator): array
    {
        if (! $operator->can(Ability::ViewPeople)) {
            return [];
        }
        $name = $this->names();
        $s = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        $files = [
            'soci/anagrafica.csv' => $this->people->csv()['csv'],
            'soci/libro-soci.csv' => $this->book->csv($this->book->rows()),
        ];

        $rows = [];
        foreach ($this->db()->table('app.memberships')->orderBy('valid_from')->get() as $m) {
            $rows[] = [$name($m->person_id ?? null), $s($m->social_year ?? ''), $s($m->valid_from ?? ''), $s($m->valid_to ?? ''),
                ($m->source ?? '') === 'payment' ? 'con incasso' : 'senza incasso', ($m->voided_at ?? null) !== null ? 'sì' : ''];
        }
        $files['soci/tessere.csv'] = Csv::build(['Socio', 'Anno sociale', 'Dal', 'Al', 'Origine', 'Tolta'], $rows);

        $rows = [];
        foreach ($this->db()->table('app.guardians')->get() as $g) {
            $rows[] = [$name($g->person_id ?? null), $name($g->guardian_person_id ?? null), ($g->is_operative ?? false) === true ? 'sì' : ''];
        }
        $files['soci/tutori.csv'] = Csv::build(['Minore', 'Genitore o tutore', 'Operativo'], $rows);

        $rows = [];
        foreach ($this->db()->table('app.consent_events')->orderBy('occurred_at')->get() as $c) {
            $rows[] = [$s($c->occurred_at ?? ''), $name($c->person_id ?? null), $s($c->channel ?? ''), ($c->granted ?? false) === true ? 'dato' : 'revocato',
                $s($c->source ?? ''), $name($c->given_by_person_id ?? null)];
        }
        $files['soci/consensi.csv'] = Csv::build(['Quando', 'Persona', 'Canale', 'Stato', 'Come', 'Dato da'], $rows);

        $rows = [];
        foreach ($this->db()->table('app.member_levels')->orderBy('sort_order')->get() as $l) {
            $rows[] = [$s($l->name ?? ''), $s($l->base_type ?? ''), is_numeric($l->fee_cents ?? null) ? Money::plain((int) $l->fee_cents) : '', ($l->active ?? false) === true ? 'sì' : 'no'];
        }
        $files['soci/livelli.csv'] = Csv::build(['Livello', 'Tipo', 'Quota', 'Attivo'], $rows);

        $rows = [];
        foreach (Application::query()->orderBy('submitted_at')->get() as $a) {
            $d = $a->data;
            $t = static fn (string $k): string => is_scalar($d[$k] ?? null) ? (string) $d[$k] : '';
            $g = is_array($d['guardian'] ?? null) ? $d['guardian'] : [];
            $rows[] = [$a->submitted_at->format('d/m/Y H:i'), $a->status, $a->last_name.' '.$a->first_name, $t('tax_code'), $t('birth_date'), $t('email'), $t('phone'),
                $t('address'), trim($s($g['first_name'] ?? '').' '.$s($g['last_name'] ?? '')), $s($g['email'] ?? ''), (string) $a->reason];
        }
        $files['soci/domande-di-adesione.csv'] = Csv::build(['Arrivata', 'Stato', 'Nome', 'Codice fiscale', 'Nascita', 'Email', 'Cellulare', 'Indirizzo', 'Genitore', 'Email del genitore', 'Motivo'], $rows);

        if ($operator->can(Ability::ViewHealth)) {
            $rows = [];
            foreach (MedicalCertificate::query()->orderBy('expires_on')->get() as $c) {
                $rows[] = [$name($c->person_id), CertificateKind::tryFrom($c->kind)?->label() ?? $c->kind, (string) $c->doctor,
                    $c->issued_on->format('d/m/Y'), $c->expires_on->format('d/m/Y'), (string) $c->file_name];
            }
            $files['soci/certificati-medici.csv'] = Csv::build(['Persona', 'Tipo', 'Medico', 'Rilascio', 'Scadenza', 'File'], $rows);
        }

        // Le fotografie del libro soci di ogni esercizio chiuso, tutte le versioni (anche quelle superate da una riapertura).
        $files['soci/libri-soci-esercizi-chiusi.json'] = json_encode($this->snapshots->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        return $files;
    }

    /** @return callable(mixed): string */
    private function names(): callable
    {
        $map = [];
        foreach ($this->db()->table('app.people')->get(['id', 'first_name', 'last_name']) as $p) {
            if (is_string($p->id ?? null)) {
                $map[$p->id] = trim((is_string($p->last_name ?? null) ? $p->last_name : '').' '.(is_string($p->first_name ?? null) ? $p->first_name : ''));
            }
        }

        return static fn (mixed $id): string => is_string($id) ? ($map[$id] ?? '') : '';
    }

    private function db(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
