<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use InvalidArgumentException;

/** L'associazione non esiste o non è legata a quell'abbonamento del portale. */
final class UnknownOrg extends InvalidArgumentException {}
