<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use RuntimeException;

final class OperatorLimitReached extends RuntimeException {}
