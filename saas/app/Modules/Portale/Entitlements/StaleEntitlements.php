<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use RuntimeException;

/** Arrivano diritti con una revisione non più recente di quella salvata: si tengono quelli che ci sono. */
final class StaleEntitlements extends RuntimeException
{
    public function __construct(public readonly int $current)
    {
        parent::__construct('Revisione non più recente di quella salvata.');
    }
}
