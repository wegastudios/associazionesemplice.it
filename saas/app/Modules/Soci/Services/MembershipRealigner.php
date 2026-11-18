<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Contracts\MembershipRealignment;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\SocialYear;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * La tessera segue l'anno fiscale (decisione del titolare, 18/11/2026). Se il mese di inizio dell'anno fiscale cambia,
 * le tessere degli anni fiscali aperti passano all'anno fiscale che le contiene (etichetta, inizio e fine): prima si
 * mostra l'anteprima, poi la persona conferma. Le tessere che toccano un anno fiscale chiuso non si toccano. Se una
 * persona si ritrova con due tessere con la stessa etichetta resta valida la più recente e l'altra diventa «sostituita»:
 * non si cancella, i pagamenti restano com'erano, fuori dal libro soci. Ogni cambio finisce nel registro con etichetta e
 * date di prima e di dopo, così si può ricostruire. Il riallineamento ripetuto non cambia più nulla.
 *
 * @phpstan-type Move array{id: string, person_id: string, from: string, to: string, from_from: string, from_to: string, to_from: string, to_to: string}
 * @phpstan-type Sub array{id: string, person_id: string, label: string, by: string, from: string, from_from: string, from_to: string}
 */
final readonly class MembershipRealigner implements MembershipRealignment
{
    private const SHOWN = 10;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private AuditLog $audit,
    ) {}

    public function preview(int $month): array
    {
        $plan = $this->plan($month, now()->toDateString());
        $ids = array_values(array_unique(array_merge(array_column($plan['moves'], 'person_id'), array_column($plan['subs'], 'person_id'))));
        $names = [];
        foreach (Person::query()->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $p) {
            $names[$p->id] = trim($p->last_name.' '.$p->first_name);
        }
        $lines = [];
        foreach ($plan['moves'] as $m) {
            $lines[] = ($names[$m['person_id']] ?? '').': '.$m['from'].' → '.$m['to'];
        }
        foreach ($plan['subs'] as $s) {
            $lines[] = ($names[$s['person_id']] ?? '').': '.$s['from'].' sostituita dalla '.$s['label'];
        }

        return [
            'moved' => count($plan['moves']),
            'superseded' => count($plan['subs']),
            'lines' => array_slice($lines, 0, self::SHOWN),
            'more' => max(0, count($lines) - self::SHOWN),
        ];
    }

    public function apply(int $month): array
    {
        $plan = $this->plan($month, now()->toDateString());
        $db = $this->connection();
        $db->transaction(function () use ($plan, $db): void {
            // Prima le sostituite: così l'indice di unicità non trova due tessere valide con la stessa etichetta.
            foreach ($plan['subs'] as $s) {
                $db->table('app.memberships')->where('id', $s['id'])->update(['superseded_at' => now(), 'superseded_by' => $s['by']]);
                $this->audit->record('membership.superseded', 'app.memberships', $s['id'], [
                    'social_year' => $s['from'], 'valid_from' => $s['from_from'], 'valid_to' => $s['from_to'], 'replaced_by' => $s['by'], 'replaced_by_year' => $s['label'],
                ]);
            }
            foreach ($plan['moves'] as $m) {
                $db->table('app.memberships')->where('id', $m['id'])->update(['social_year' => $m['to'], 'valid_from' => $m['to_from'], 'valid_to' => $m['to_to']]);
                $this->audit->record('membership.realigned', 'app.memberships', $m['id'], [
                    'from_year' => $m['from'], 'from_valid_from' => $m['from_from'], 'from_valid_to' => $m['from_to'],
                    'to_year' => $m['to'], 'to_valid_from' => $m['to_from'], 'to_valid_to' => $m['to_to'],
                ]);
            }
        });

        return ['moved' => count($plan['moves']), 'superseded' => count($plan['subs'])];
    }

    /**
     * @return array{moves: list<Move>, subs: list<Sub>}
     */
    private function plan(int $month, string $day): array
    {
        $db = $this->connection();
        /** @var list<array{string, string}> $closed */
        $closed = [];
        foreach ($db->table('app.fiscal_years')->where('status', '<>', 'open')->get(['starts_on', 'ends_on']) as $r) {
            $closed[] = [self::text($r->starts_on), self::text($r->ends_on)];
        }

        $rows = $db->table('app.valid_memberships')->where('source', '<>', 'founder')
            ->orderBy('person_id')->orderByDesc('created_at')->orderByDesc('valid_from')->orderByDesc('id')
            ->get(['id', 'person_id', 'social_year', 'valid_from', 'valid_to']);

        /** @var array<string, list<array{id: string, label: string, from: string, to: string, old: string, old_from: string, old_to: string, change: bool}>> $byPerson */
        $byPerson = [];
        foreach ($rows as $r) {
            $from = self::text($r->valid_from);
            $to = self::text($r->valid_to);
            $label = self::text($r->social_year);
            $entry = ['id' => self::text($r->id), 'label' => $label, 'from' => $from, 'to' => $to, 'old' => $label, 'old_from' => $from, 'old_to' => $to, 'change' => false];
            if (! self::touches($closed, $from, $to)) {
                $target = SocialYear::forDate($from <= $day && $to >= $day ? $day : $from, $month);
                $tFrom = $target->start()->format('Y-m-d');
                $tTo = $target->end()->format('Y-m-d');
                if (! self::touches($closed, $tFrom, $tTo) && ($target->label() !== $label || $tFrom !== $from || $tTo !== $to)) {
                    $entry['label'] = $target->label();
                    $entry['from'] = $tFrom;
                    $entry['to'] = $tTo;
                    $entry['change'] = true;
                }
            }
            $byPerson[self::text($r->person_id)][] = $entry;
        }

        $moves = [];
        $subs = [];
        foreach ($byPerson as $personId => $entries) {
            $keep = [];
            // Le righe sono dalla più recente: la prima di ogni etichetta resta, le altre sono sostituite.
            foreach ($entries as $e) {
                if (! isset($keep[$e['label']])) {
                    $keep[$e['label']] = $e;

                    continue;
                }
                if ($e['change'] || $keep[$e['label']]['change']) {
                    $subs[] = ['id' => $e['id'], 'person_id' => (string) $personId, 'label' => $e['label'], 'by' => $keep[$e['label']]['id'], 'from' => $e['old'], 'from_from' => $e['old_from'], 'from_to' => $e['old_to']];
                }
            }
            foreach ($keep as $e) {
                if ($e['change']) {
                    $moves[] = [
                        'id' => $e['id'], 'person_id' => (string) $personId, 'from' => $e['old'], 'to' => $e['label'],
                        'from_from' => $e['old_from'], 'from_to' => $e['old_to'], 'to_from' => $e['from'], 'to_to' => $e['to'],
                    ];
                }
            }
        }

        return ['moves' => $moves, 'subs' => $subs];
    }

    /** @param  list<array{string, string}>  $closed */
    private static function touches(array $closed, string $from, string $to): bool
    {
        foreach ($closed as [$start, $end]) {
            if ($start <= $to && $end >= $from) {
                return true;
            }
        }

        return false;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
