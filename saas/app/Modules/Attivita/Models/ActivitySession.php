<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Models;

use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;

/**
 * Una data di un evento con prenotazioni: giorno, orario, luogo e posti (null = senza limite).
 *
 * @property string $id
 * @property string $activity_id
 * @property CarbonImmutable $session_date
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string|null $location
 * @property int|null $capacity
 * @property CarbonImmutable|null $cancelled_at
 */
final class ActivitySession extends OrgModel
{
    protected $table = 'app.activity_sessions';

    /** @var list<string> */
    protected $fillable = ['activity_id', 'session_date', 'start_time', 'end_time', 'location', 'capacity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['session_date' => 'immutable_date', 'capacity' => 'integer', 'cancelled_at' => 'immutable_datetime'];
    }
}
