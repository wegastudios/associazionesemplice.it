<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use RuntimeException;

/** Lo spazio per i file previsto dal piano è esaurito: il messaggio si può mostrare così com'è. */
final class StorageFull extends RuntimeException {}
