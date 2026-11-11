<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

use RuntimeException;

/** Token staff non valido o portale non raggiungibile: l'accesso non si apre. Il messaggio non va mostrato com'è. */
final class StaffAccessDenied extends RuntimeException {}
