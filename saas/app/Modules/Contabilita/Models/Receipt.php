<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Models;

use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;

/**
 * Ricevuta: numero progressivo per esercizio, senza buchi (app.counters). Una ricevuta stornata resta con il suo numero.
 *
 * @property string $id
 * @property int $year
 * @property int $number
 * @property CarbonImmutable $issued_on
 * @property string|null $payer_person_id
 * @property int $total_cents
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 */
final class Receipt extends OrgModel
{
    public const UPDATED_AT = null;

    protected $table = 'app.receipts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
            'issued_on' => 'immutable_date',
            'total_cents' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /** «12/2026» */
    public function label(): string
    {
        return $this->number.'/'.$this->year;
    }
}
