<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Contracts;

use RuntimeException;

/** Addon che il catalogo non ammette sul piano scelto (contratto: 409 addon_not_allowed). */
final class AddonNotAllowed extends RuntimeException
{
    public function __construct(public readonly string $addon, public readonly string $plan)
    {
        parent::__construct("Il pacchetto {$addon} non si vende sul piano {$plan}");
    }
}
