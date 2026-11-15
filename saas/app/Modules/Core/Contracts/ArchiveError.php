<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use RuntimeException;

/** Un'archiviazione non è riuscita: il messaggio è per chi l'ha chiesta e dice che non è cambiato nulla. */
final class ArchiveError extends RuntimeException {}
