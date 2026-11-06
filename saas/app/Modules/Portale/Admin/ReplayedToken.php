<?php

declare(strict_types=1);

namespace App\Modules\Portale\Admin;

use RuntimeException;

/** Token di scrittura già usato. */
final class ReplayedToken extends RuntimeException {}
