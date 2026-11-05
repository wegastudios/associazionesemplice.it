<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Models;

use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;

/**
 * Prenotazione (a fasce, a giornate o per una stagione) o blocco di una risorsa. Gli orari sono quelli
 * dell'associazione, come testi «Y-m-d H:i:s». Il registro delle azioni lo scrive il servizio.
 *
 * @property string $id
 * @property string $resource_id
 * @property string $kind booking|block
 * @property string|null $person_id
 * @property string|null $holder_name chi prenota senza essere in anagrafica (un esterno)
 * @property bool $member
 * @property string $starts_at
 * @property string|null $ends_at
 * @property int $units
 * @property list<string> $guests
 * @property list<array{name: string, fee_cents: int}> $services
 * @property int $fee_due_cents
 * @property string $status booked|cancelled
 * @property string|null $note
 * @property string|null $series_id
 * @property string|null $created_by
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 */
final class Reservation extends OrgModel
{
    protected $table = 'app.reservations';

    /** @var list<string> */
    protected $fillable = [
        'resource_id', 'kind', 'person_id', 'holder_name', 'member', 'starts_at', 'ends_at', 'units', 'guests', 'services',
        'fee_due_cents', 'status', 'note', 'series_id', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'member' => 'boolean',
            'units' => 'integer',
            'guests' => 'array',
            'services' => 'array',
            'fee_due_cents' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
