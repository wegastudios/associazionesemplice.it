<?php

declare(strict_types=1);

namespace App\Modules\Core\Export;

use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Models\Org;
use App\Modules\Documenti\Contracts\Csv;
use Illuminate\Database\DatabaseManager;

/** Operatori, ruoli e impostazioni dell'associazione. */
final readonly class CoreDataExport implements DataExport
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private OrgMembers $members,
    ) {}

    public function files(Operator $operator): array
    {
        $org = Org::query()->findOrFail($operator->orgId);
        $files = [];
        if ($operator->can(Ability::ManageOperators)) {
            $files['operatori.csv'] = Csv::build(['Nome', 'Email', 'Ruolo', 'Accesso attivato'], array_map(
                static fn (array $o): array => [$o['name'], $o['email'], $o['role_label'], $o['two_factor'] ? 'sì' : 'no'],
                $this->members->operators($org),
            ));
            $files['ruoli.csv'] = Csv::build(['Ruolo', 'Permessi', 'Operatori'], array_map(
                static fn (array $r): array => [$r['name'], implode(', ', array_map(static fn (Ability $a): string => $a->label(), $r['abilities'])), $r['members']],
                $this->members->roles($org),
            ));
        }
        $rows = [];
        foreach ($this->db->connection($this->context->require()->connection)->table('app.settings')->where('key', 'not like', 'ente.%')->orderBy('key')->get(['key', 'value']) as $s) {
            $rows[] = [is_string($s->key ?? null) ? $s->key : '', is_string($s->value ?? null) ? $s->value : ''];
        }
        $files['impostazioni.csv'] = Csv::build(['Impostazione', 'Valore'], $rows);

        return $files;
    }
}
