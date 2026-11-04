<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Core\Contracts\OrgModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $name
 * @property CategoryKind $kind
 * @property string|null $fiscal_group
 * @property int $sort_order
 */
final class Category extends OrgModel
{
    use Audited;
    use SoftDeletes;

    protected $table = 'app.categories';

    /** @var list<string> */
    protected $fillable = ['name', 'kind', 'fiscal_group', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => CategoryKind::class, 'sort_order' => 'integer'];
    }
}
