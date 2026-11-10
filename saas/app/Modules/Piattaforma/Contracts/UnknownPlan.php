<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Contracts;

use RuntimeException;

/** Piano o versione del piano che il catalogo non ha mai pubblicato (contratto: 409 unknown_plan). */
final class UnknownPlan extends RuntimeException {}
