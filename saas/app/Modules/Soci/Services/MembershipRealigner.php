<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Soci\Rules\SocialYear;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * La tessera segue l'anno fiscale (decisione del titolare, 18/11/2026). Le tessere già emesse con un altro anno
 * (per esempio «2025/2026» da settembre) passano all'anno fiscale che le contiene: etichetta, inizio e fine. Si toccano
 * solo quelle che non toccano anni fiscali chiusi («il chiuso è chiuso»). Se una persona si ritrova con due tessere con la
 * stessa etichetta resta valida la più recente e l'altra diventa «sostituita»: non si cancella, i pagamenti restano come
 * erano. Ogni cambio finisce nel registro delle azioni. Si può ripetere senza effetti.
 */
final readonly class MembershipRealigner
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private OrgFiscal $fiscal,
        private AuditLog $audit,
    ) {}

    /** @return array{moved: int, superseded: int} */
    public function realign(?string $today = null): array
    {
        $day = $today ?? now()->toDateString();
        $month = $this->fiscal->startMonth();
        $db = $this->connection();
        $closed = $db->table('app.fiscal_years')->where('status', '<>', 'open')->get(['starts_on', 'ends_on'])
            ->map(static fn (object $r): array => [(string) $r->starts_on, (string) $r->ends_on])->all();

        $rows = $db->table('app.valid_memberships')->where('source', '<>', 'founder')
            ->orderBy('person_id')->orderByDesc('created_at')->orderByDesc('valid_from')->orderByDesc('id')
            ->get(['id', 'person_id', 'social_year', 'valid_from', 'valid_to']);

        /** @var array<string, list<array{id: string, label: string, from: string, to: string, old: string, change: bool}>> $byPerson */
        $byPerson = [];
        foreach ($rows as $r) {
            $from = (string) $r->valid_from;
            $to = (string) $r->valid_to;
            $label = (string) $r->social_year;
            $entry = ['id' => (string) $r->id, 'label' => $label, 'from' => $from, 'to' => $to, 'old' => $label, 'change' => false];
            if (! self::touches($closed, $from, $to)) {
                $target = SocialYear::forDate($from <= $day && $to >= $day ? $day : $from, $month);
                $tFrom = $target->start()->format('Y-m-d');
                $tTo = $target->end()->format('Y-m-d');
                if (! self::touches($closed, $tFrom, $tTo) && ($target->label() !== $label || $tFrom !== $from || $tTo !== $to)) {
                    $entry = ['id' => $entry['id'], 'label' => $target->label(), 'from' => $tFrom, 'to' => $tTo, 'old' => $label, 'change' => true];
                }
            }
            $byPerson[(string) $r->person_id][] = $entry;
        }

        $moved = 0;
        $superseded = 0;
        $db->transaction(function () use ($byPerson, $db, &$moved, &$superseded): void {
            foreach ($byPerson as $entries) {
                $keep = [];
                // Le righe sono dalla più recente: la prima di ogni etichetta resta, le altre sono sostituite.
                foreach ($entries as $e) {
                    if (! isset($keep[$e['label']])) {
                        $keep[$e['label']] = $e;

                        continue;
                    }
                    if (! $e['change'] && ! $keep[$e['label']]['change']) {
                        continue; // già così prima: non è un effetto del riallineamento
                    }
                    $by = $keep[$e['label']]['id'];
                    $db->table('app.memberships')->where('id', $e['id'])->update(['superseded_at' => now(), 'superseded_by' => $by]);
                    $this->audit->record('membership.superseded', 'app.memberships', $e['id'], ['social_year' => $e['old'], 'replaced_by' => $by, 'label' => $e['label']]);
                    $superseded++;
                }
                foreach ($keep as $e) {
                    if (! $e['change']) {
                        continue;
                    }
                    $db->table('app.memberships')->where('id', $e['id'])->update(['social_year' => $e['label'], 'valid_from' => $e['from'], 'valid_to' => $e['to']]);
                    $this->audit->record('membership.realigned', 'app.memberships', $e['id'], ['from' => $e['old'], 'to' => $e['label']]);
                    $moved++;
                }
            }
        });

        return ['moved' => $moved, 'superseded' => $superseded];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $closed
     */
    private static function touches(array $closed, string $from, string $to): bool
    {
        foreach ($closed as [$start, $end]) {
            if ($start <= $to && $end >= $from) {
                return true;
            }
        }

        return false;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
