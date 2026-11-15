<?php

declare(strict_types=1);

namespace App\Modules\Core\Export;

use RuntimeException;

/** La copia di tutti gli anni insieme avrebbe troppi file per finire in una sola richiesta: si scarica un anno alla volta. */
final class ExportTooLarge extends RuntimeException {}
