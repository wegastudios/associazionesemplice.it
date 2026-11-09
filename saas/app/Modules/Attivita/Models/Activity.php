<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Models;

use App\Modules\Attivita\Contracts\ActivityKind;
use App\Modules\Attivita\Contracts\Billing;
use App\Modules\Audit\Contracts\Audited;
use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Corso o evento di un anno sociale: è anche il centro di costo dei movimenti della prima nota.
 *
 * @property string $id
 * @property string $name
 * @property ActivityKind $kind
 * @property string $social_year
 * @property Billing $billing
 * @property int $fee_cents
 * @property int|null $guest_fee_cents
 * @property string|null $instructor_person_id
 * @property list<int> $weekdays
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $location
 * @property string|null $notes
 * @property CarbonImmutable|null $archived_at
 * @property list<array{key: string, label: string, cents: int, entries: int}> $price_lists
 * @property string|null $resource_id
 * @property bool $no_resource
 * @property bool $occupy_on_booking
 */
final class Activity extends OrgModel
{
    use Audited;

    protected $table = 'app.activities';

    /** @var list<string> */
    protected $fillable = [
        'name', 'kind', 'social_year', 'billing', 'fee_cents', 'guest_fee_cents', 'instructor_person_id', 'weekdays',
        'starts_on', 'ends_on', 'location', 'notes', 'price_lists', 'resource_id', 'no_resource', 'occupy_on_booking',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ActivityKind::class,
            'billing' => Billing::class,
            'fee_cents' => 'integer',
            'guest_fee_cents' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
            'price_lists' => 'array',
            'no_resource' => 'boolean',
            'occupy_on_booking' => 'boolean',
        ];
    }

    /**
     * I giorni delle lezioni (1 = lunedì … 7 = domenica), in PostgreSQL come smallint[].
     *
     * @return Attribute<list<int>, list<int>>
     */
    protected function weekdays(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $v): array => is_string($v) && trim($v, '{}') !== ''
                ? array_values(array_map(static fn (string $d): int => (int) $d, explode(',', trim($v, '{}'))))
                : [],
            set: static function (mixed $v): string {
                $days = [];
                foreach (is_array($v) ? $v : [] as $d) {
                    if (is_int($d) && $d >= 1 && $d <= 7) {
                        $days[$d] = $d;
                    }
                }
                ksort($days);

                return '{'.implode(',', $days).'}';
            },
        );
    }

    /** Il mese da cui è dovuto il contributo: l'inizio dell'attività, se c'è. */
    public function firstMonth(): ?string
    {
        return $this->starts_on?->format('Y-m');
    }
}
