<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Contracts\StaffCount;
use App\Modules\Soci\Models\MemberLevel;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\Age;

/**
 * Dati per il RUNTS alla data del 31 dicembre: soci con diritto di voto (persone ed enti), volontari, lavoratori.
 * Chi è socio alla data lo decide lo stesso criterio del libro soci ({@see MemberBookService::entersPeriod()});
 * il diritto di voto sta nel tipo di socio e i minorenni alla data si escludono.
 */
final readonly class RuntsCounts
{
    /** Minimi per ODV e APS: almeno 7 persone fisiche oppure 3 enti (della stessa sezione). */
    public const MIN_PERSONS = 7;

    public const MIN_ENTITIES = 3;

    /** Giorni dalla scadenza della tessera entro cui un socio si segnala come «da rinnovare». */
    public const EXPIRED_DAYS = 90;

    public function __construct(
        private MemberBookService $book,
        private StaffCount $staff,
        private OrgSettings $settings,
    ) {}

    /**
     * @return array{
     *     date: string, persons: int, entities: int, entities_same_section: int, volunteers: int, workers: int,
     *     kind: string|null, minimum_applies: bool,
     *     entity_list: list<array{id: string, name: string, same_section: bool|null}>
     * }
     */
    public function at(string $date): array
    {
        $persons = 0;
        $entities = 0;
        $same = 0;
        $volunteers = 0;
        $list = [];
        $noVote = MemberLevel::query()->where('votes', false)->pluck('id')->all();
        foreach ($this->book->peopleEntering([$date, $date]) as $p) {
            if (! $p->type->hasVotingRights() || ($p->level_id !== null && in_array($p->level_id, $noVote, true))) {
                continue;
            }
            if ($p->is_entity) {
                $entities++;
                $same += $p->entity_same_section === true ? 1 : 0;
                $list[] = ['id' => $p->id, 'name' => $p->last_name, 'same_section' => $p->entity_same_section];

                continue;
            }
            if (Age::isMinor($p->birth_date?->format('Y-m-d'), $date)) {
                continue;
            }
            $persons++;
            $volunteers += $p->type->value === 'volunteer' ? 1 : 0;
        }

        $kind = $this->settings->get('ente.kind');
        $kind = is_string($kind) && $kind !== '' ? $kind : null;
        $applies = in_array($kind, ['odv', 'aps'], true);

        return [
            'date' => $date, 'persons' => $persons, 'entities' => $entities, 'entities_same_section' => $same,
            'volunteers' => $volunteers, 'workers' => $this->staff->employees(),
            'kind' => $kind, 'minimum_applies' => $applies,
            'entity_list' => $list,
        ];
    }

    /**
     * L'avviso «sotto il minimo», come nel plugin: guarda i soci con tessera valida OGGI (chi non ha rinnovato è
     * cessato di fatto fino al rinnovo), non il 31 dicembre. Mostra anche chi ha la tessera scaduta da poco.
     *
     * @return array{
     *     date: string, persons: int, entities_same_section: int, minimum_applies: bool, below_minimum: bool,
     *     recently_expired: list<array{id: string, name: string}>, expired_days: int
     * }
     */
    public function today(string $today): array
    {
        $now = $this->at($today);
        $below = $now['minimum_applies'] && $now['persons'] < self::MIN_PERSONS && $now['entities_same_section'] < self::MIN_ENTITIES;
        $expired = array_map(
            static fn (Person $p): array => ['id' => $p->id, 'name' => trim($p->last_name.' '.$p->first_name)],
            $this->book->recentlyExpired($today, self::EXPIRED_DAYS),
        );

        return [
            'date' => $today, 'persons' => $now['persons'], 'entities_same_section' => $now['entities_same_section'],
            'minimum_applies' => $now['minimum_applies'], 'below_minimum' => $below,
            'recently_expired' => $below ? $expired : [], 'expired_days' => self::EXPIRED_DAYS,
        ];
    }
}
