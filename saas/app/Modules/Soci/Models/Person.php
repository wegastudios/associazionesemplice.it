<?php

declare(strict_types=1);

namespace App\Modules\Soci\Models;

use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $org_id
 * @property string $id
 * @property string|null $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $card_number
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Person extends OrgModel
{
    use SoftDeletes;

    protected $table = 'app.people';

    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'email', 'card_number', 'user_id'];
}
