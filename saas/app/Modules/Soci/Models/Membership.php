<?php

declare(strict_types=1);

namespace App\Modules\Soci\Models;

use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;

/**
 * Tessera di un anno sociale. Nasce da un incasso della quota (source payment), a mano o dall'import. Per sapere se
 * vale si usa la vista app.valid_memberships, che esclude anche le tessere con la quota stornata.
 *
 * @property string $id
 * @property string $person_id
 * @property string $social_year
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable $valid_to
 * @property string $source
 * @property string|null $transaction_id
 * @property CarbonImmutable|null $voided_at
 */
final class Membership extends OrgModel
{
    public const UPDATED_AT = null;

    protected $table = 'app.memberships';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_to' => 'immutable_date', 'voided_at' => 'immutable_datetime'];
    }
}
