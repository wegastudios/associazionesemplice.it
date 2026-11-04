<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Associazione cliente, nel database centrale.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string|null $database null = database condiviso
 * @property string $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Org extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'pgsql';

    protected $table = 'core.orgs';

    /** @var list<string> */
    protected $fillable = ['slug', 'name'];
}
