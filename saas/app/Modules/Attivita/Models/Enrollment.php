<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Core\Contracts\OrgModel;

/**
 * Iscrizione di una persona a un'attività: per un corso le mensilità sono dovute da start_month fino a end_month
 * (o finché è attiva). Non si cancella: si chiude con end_month, i pagamenti restano.
 *
 * @property string $id
 * @property string $activity_id
 * @property string $person_id
 * @property string $start_month
 * @property string|null $end_month
 * @property string|null $fee_note
 */
final class Enrollment extends OrgModel
{
    use Audited;

    protected $table = 'app.enrollments';

    /** @var list<string> */
    protected $fillable = ['activity_id', 'person_id', 'start_month', 'end_month', 'fee_note'];
}
