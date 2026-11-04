<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

use InvalidArgumentException;

/** Operazione contabile non ammessa; il messaggio è per l'operatore. */
final class LedgerError extends InvalidArgumentException {}
