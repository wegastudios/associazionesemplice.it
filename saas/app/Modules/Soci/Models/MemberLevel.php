<?php

declare(strict_types=1);

namespace App\Modules\Soci\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Core\Contracts\OrgModel;
use App\Modules\Soci\Contracts\MemberType;

/**
 * Livello di socio (per esempio «Socio junior» con quota ridotta): una base tra fondatore, ordinario e volontario e,
 * se c'è, una quota propria.
 *
 * @property string $id
 * @property string $name
 * @property MemberType $base_type
 * @property int|null $fee_cents
 * @property int $sort_order
 * @property bool $active
 */
final class MemberLevel extends OrgModel
{
    use Audited;

    protected $table = 'app.member_levels';

    /** @var list<string> */
    protected $fillable = ['name', 'base_type', 'fee_cents', 'sort_order', 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['base_type' => MemberType::class, 'fee_cents' => 'integer', 'sort_order' => 'integer', 'active' => 'boolean'];
    }
}
