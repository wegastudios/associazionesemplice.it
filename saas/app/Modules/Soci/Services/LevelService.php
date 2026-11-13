<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\MemberLevel;
use App\Modules\Soci\Models\Person;

/** Livelli di socio (dal Levels del plugin): una base tra fondatore, ordinario e volontario e una quota propria. */
final readonly class LevelService
{
    public function create(string $name, MemberType $base, ?int $feeCents, bool $votes = true): MemberLevel
    {
        $level = new MemberLevel([
            'votes' => $votes,
            'name' => self::name($name),
            'base_type' => self::base($base)->value,
            'fee_cents' => self::fee($feeCents),
            'sort_order' => MemberLevel::query()->count(),
            'active' => true,
        ]);
        $level->save();

        return $level;
    }

    public function update(string $id, string $name, MemberType $base, ?int $feeCents, bool $active, bool $votes = true): MemberLevel
    {
        $level = MemberLevel::query()->findOrFail($id);
        $level->fill(['name' => self::name($name), 'base_type' => self::base($base)->value, 'fee_cents' => self::fee($feeCents), 'active' => $active, 'votes' => $votes])->save();

        return $level;
    }

    /** @return list<array{id: string, name: string, base: string, base_label: string, fee_cents: int|null, active: bool, votes: bool, people: int}> */
    public function all(): array
    {
        $counts = Person::query()->whereNotNull('level_id')->whereNull('left_on')->selectRaw('level_id, count(*) AS n')->groupBy('level_id')
            ->toBase()->pluck('n', 'level_id');

        return array_values(MemberLevel::query()->orderBy('sort_order')->get()->map(static fn (MemberLevel $l): array => [
            'id' => $l->id,
            'name' => $l->name,
            'base' => $l->base_type->value,
            'base_label' => $l->base_type->label(),
            'fee_cents' => $l->fee_cents,
            'active' => $l->active,
            'votes' => $l->votes,
            'people' => is_numeric($counts->get($l->id)) ? (int) $counts->get($l->id) : 0,
        ])->all());
    }

    private static function name(string $name): string
    {
        $name = trim($name);

        return $name !== '' && mb_strlen($name) <= 80 ? $name : throw new PeopleError(['Il nome del livello è obbligatorio (al massimo 80 caratteri).']);
    }

    private static function base(MemberType $base): MemberType
    {
        return $base->isMember() ? $base : throw new PeopleError(['Un livello si basa su un tipo di socio: fondatore, ordinario o volontario.']);
    }

    private static function fee(?int $fee): ?int
    {
        return $fee === null || $fee >= 0 ? $fee : throw new PeopleError(['La quota non può essere negativa.']);
    }
}
