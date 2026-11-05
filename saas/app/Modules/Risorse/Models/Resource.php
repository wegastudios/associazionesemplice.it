<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Core\Contracts\OrgModel;
use App\Modules\Risorse\Contracts\Typology;
use Carbon\CarbonImmutable;

/**
 * Impianto, sala, posto barca o postazione prenotabile. La tipologia è il modo in cui si vende: a ore, a giornata o a
 * stagione, e decide i limiti del pacchetto.
 *
 * @property string $id
 * @property string $name
 * @property string $category
 * @property Typology $typology
 * @property string|null $description
 * @property string|null $open_time
 * @property string|null $close_time
 * @property int $slot_minutes
 * @property int $min_slots
 * @property int $max_slots
 * @property int $advance_days
 * @property int $fee_cents
 * @property int|null $nonmember_fee_cents
 * @property int $guests_max
 * @property int $guest_fee_cents
 * @property list<array{days: string, from: string, to: string, fee_cents: int}> $rates
 * @property list<array{name: string, fee_cents: int}> $services
 * @property string|null $category_id
 * @property CarbonImmutable|null $archived_at
 */
final class Resource extends OrgModel
{
    use Audited;

    protected $table = 'app.resources';

    /** @var list<string> */
    protected $fillable = [
        'name', 'category', 'typology', 'description', 'open_time', 'close_time', 'slot_minutes', 'min_slots', 'max_slots',
        'advance_days', 'fee_cents', 'nonmember_fee_cents', 'guests_max', 'guest_fee_cents', 'rates', 'services', 'category_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'typology' => Typology::class,
            'slot_minutes' => 'integer',
            'min_slots' => 'integer',
            'max_slots' => 'integer',
            'advance_days' => 'integer',
            'fee_cents' => 'integer',
            'nonmember_fee_cents' => 'integer',
            'guests_max' => 'integer',
            'guest_fee_cents' => 'integer',
            'rates' => 'array',
            'services' => 'array',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return array<string, mixed> i dati che servono alle regole */
    public function rules(): array
    {
        return [
            'typology' => $this->typology->value, 'open_time' => $this->open_time, 'close_time' => $this->close_time,
            'slot_minutes' => $this->slot_minutes, 'min_slots' => $this->min_slots, 'max_slots' => $this->max_slots,
            'advance_days' => $this->advance_days, 'fee_cents' => $this->fee_cents, 'nonmember_fee_cents' => $this->nonmember_fee_cents,
            'guest_fee_cents' => $this->guest_fee_cents, 'rates' => $this->rates,
        ];
    }
}
