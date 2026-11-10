<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string $type
 * @property int $opening_cents
 * @property int $sort_order
 * @property int $fee_fixed_cents
 * @property int $fee_bp
 * @property CarbonImmutable|null $closed_at
 */
final class Account extends OrgModel
{
    use Audited;

    protected $table = 'app.accounts';

    /** @var list<string> */
    protected $fillable = ['name', 'type', 'opening_cents', 'sort_order', 'fee_fixed_cents', 'fee_bp'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['opening_cents' => 'integer', 'sort_order' => 'integer', 'fee_fixed_cents' => 'integer', 'fee_bp' => 'integer', 'closed_at' => 'immutable_datetime'];
    }
}
