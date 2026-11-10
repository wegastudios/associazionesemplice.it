<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Contracts;

use RuntimeException;

/** Addon che il catalogo non conosce (contratto: 409 unknown_addon). */
final class UnknownAddon extends RuntimeException {}
