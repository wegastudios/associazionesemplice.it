<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use RuntimeException;

/** Dato cifrato alterato, di un'altra associazione o con la chiave distrutta. */
final class DecryptionFailed extends RuntimeException {}
