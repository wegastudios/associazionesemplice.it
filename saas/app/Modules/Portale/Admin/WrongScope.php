<?php

declare(strict_types=1);

namespace App\Modules\Portale\Admin;

use RuntimeException;

/** Il token non ha il permesso richiesto (scope). */
final class WrongScope extends RuntimeException {}
