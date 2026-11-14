<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\Csv;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Models\MemberLevel;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Export dell'anagrafica in CSV per Excel (separatore «;», UTF-8 con BOM). Le colonne sono quelle che l'import
 * riconosce: il file si può correggere e ricaricare. Ogni export finisce nel registro delle azioni.
 */
final readonly class PeopleExportService
{
    public const HEADER = [
        'Numero tessera', 'Tipo', 'Nome', 'Cognome', 'Email', 'Cellulare', 'Codice fiscale', 'Data di nascita',
        'Luogo di nascita', 'Indirizzo', 'Data di ingresso', 'Data di uscita', 'Livello', 'Ospite di', 'Tutore',
    ];

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private PeopleService $people,
        private MembershipService $memberships,
        private AuditLog $audit,
    ) {}

    /** @return array{csv: string, rows: int} */
    public function csv(string $q = '', ?MemberType $type = null): array
    {
        $year = $this->memberships->socialYear();
        $header = [...self::HEADER, 'In regola '.$year->label(), 'Tipo soggetto', 'Stessa sezione RUNTS'];

        $query = $this->people->search($q)->orderBy('last_name')->orderBy('first_name')->orderBy('id');
        if ($type !== null) {
            $query->where('type', $type->value);
        }
        $levels = MemberLevel::query()->get(['id', 'name'])->mapWithKeys(static fn (MemberLevel $l): array => [$l->id => $l->name])->all();
        $refs = Person::query()->whereNull('anonymized_at')->get(['id', 'card_number', 'email', 'first_name', 'last_name'])
            ->mapWithKeys(static fn (Person $p): array => [$p->id => $p->card_number ?? $p->email ?? $p->last_name.' '.$p->first_name])->all();
        $guardians = [];
        foreach ($this->db->connection($this->context->require()->connection)->table('app.guardians')->where('is_operative', true)->get(['person_id', 'guardian_person_id']) as $g) {
            if (is_string($g->person_id ?? null) && is_string($g->guardian_person_id ?? null)) {
                $guardians[$g->person_id] = $g->guardian_person_id;
            }
        }

        $out = fopen('php://temp', 'w+b');
        if ($out === false) {
            throw new RuntimeException('Impossibile preparare il file.');
        }
        fwrite($out, "\xEF\xBB\xBF");
        $this->line($out, $header);
        $count = 0;
        $query->chunk(500, function ($chunk) use ($out, $levels, $refs, $guardians, &$count): void {
            $active = $this->memberships->activeAmong(array_values($chunk->map(static fn (Person $p): string => $p->id)->all()));
            foreach ($chunk as $p) {
                /** @var Person $p */
                $guardian = $guardians[$p->id] ?? null;
                $this->line($out, [
                    $p->card_number, $p->type->label(), $p->first_name, $p->last_name, $p->email, $p->phone, $p->tax_code,
                    $p->birth_date?->format('d/m/Y'), $p->birth_place,
                    is_array($p->address) && is_string($p->address['text'] ?? null) ? $p->address['text'] : null,
                    $p->joined_on?->format('d/m/Y'), $p->left_on?->format('d/m/Y'),
                    $p->level_id !== null ? ($levels[$p->level_id] ?? null) : null,
                    $p->host_person_id !== null ? ($refs[$p->host_person_id] ?? null) : null,
                    $guardian !== null ? ($refs[$guardian] ?? null) : null,
                    $p->type->isMember() ? (in_array($p->id, $active, true) ? 'sì' : 'no') : null,
                    $p->is_entity ? 'Ente' : 'Persona',
                    $p->is_entity && $p->entity_same_section !== null ? ($p->entity_same_section ? 'sì' : 'no') : null,
                ]);
                $count++;
            }
        });
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->record('export.done', 'app.people', null, ['rows' => $count, 'format' => 'csv']);

        return ['csv' => $csv, 'rows' => $count];
    }

    /**
     * @param  resource  $out
     * @param  list<string|null>  $cells
     */
    private function line($out, array $cells): void
    {
        fputcsv($out, array_map(self::cell(...), $cells), ';', '"', '', "\r\n");
    }

    /** Una cella che Excel leggerebbe come formula si apre con un apostrofo (vedi Csv::cell). */
    public static function cell(?string $v): string
    {
        return Csv::cell($v);
    }
}
