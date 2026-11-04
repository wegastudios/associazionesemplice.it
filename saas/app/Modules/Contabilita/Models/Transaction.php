<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Models;

use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Contracts\TxType;
use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;

/**
 * Riga della prima nota. Si crea solo da LedgerService e non si modifica mai (il database lo impedisce): si storna.
 *
 * @property string $id
 * @property CarbonImmutable $tx_date
 * @property int $fiscal_year
 * @property TxType $type
 * @property int $amount_cents
 * @property string $account_id
 * @property PaymentMethod $method
 * @property string $category_id
 * @property string|null $person_id
 * @property string|null $receipt_id
 * @property string|null $transfer_id
 * @property string|null $competence_month
 * @property string $description
 * @property string|null $document_ref
 * @property int|null $vat_rate
 * @property int $vat_cents
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property CarbonImmutable|null $created_at
 */
final class Transaction extends OrgModel
{
    public const UPDATED_AT = null;

    protected $table = 'app.transactions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tx_date' => 'immutable_date',
            'fiscal_year' => 'integer',
            'type' => TxType::class,
            'amount_cents' => 'integer',
            'method' => PaymentMethod::class,
            'vat_rate' => 'integer',
            'vat_cents' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
