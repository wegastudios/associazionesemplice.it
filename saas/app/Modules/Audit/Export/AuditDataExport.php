<?php

declare(strict_types=1);

namespace App\Modules\Audit\Export;

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\Csv;
use Illuminate\Database\DatabaseManager;

/** Il registro delle azioni dell'associazione, con il sigillo di ogni riga. */
final readonly class AuditDataExport implements DataExport
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function files(Operator $operator): array
    {
        if (! $operator->can(Ability::ViewAudit)) {
            return [];
        }
        $connection = $this->context->require()->connection;

        // Il registro è la parte più grossa e cresce con ogni azione: si legge solo quando tocca a lui, non insieme alle altre.
        return ['registro-delle-azioni.csv' => function () use ($connection): string {
            $s = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
            $rows = [];
            foreach ($this->db->connection($connection)->table('audit.log')->orderBy('seq')->lazyById(1000, 'seq') as $r) {
                $rows[] = [$s($r->seq ?? ''), $s($r->occurred_at ?? ''), $s($r->action ?? ''), $s($r->subject_type ?? ''), $s($r->subject_id ?? ''),
                    $s($r->actor_type ?? ''), $s($r->actor_id ?? ''), $s($r->details ?? ''), bin2hex(Bytea::decode($r->hash ?? null))];
            }

            return Csv::build(['N.', 'Quando', 'Azione', 'Oggetto', 'Id', 'Chi', 'Id di chi', 'Dettagli', 'Sigillo'], $rows);
        }];
    }
}
