<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Models;

use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;

/**
 * Prenotazione di una persona a una data. Il dovuto si fissa alla prenotazione: contributo (soci o ospiti) o listino;
 * un listino a pacchetto copre più ingressi, che si usano con le prenotazioni successive ($package_of).
 *
 * @property string $id
 * @property string $session_id
 * @property string $person_id
 * @property string $status booked|cancelled
 * @property int $fee_due_cents
 * @property string|null $price_list
 * @property int $package_entries
 * @property string|null $package_of
 * @property string|null $created_by
 * @property CarbonImmutable|null $cancelled_at
 */
final class SessionBooking extends OrgModel
{
    protected $table = 'app.session_bookings';

    /** @var list<string> */
    protected $fillable = ['session_id', 'person_id', 'status', 'fee_due_cents', 'price_list', 'package_entries', 'package_of', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fee_due_cents' => 'integer', 'package_entries' => 'integer', 'cancelled_at' => 'immutable_datetime'];
    }
}
