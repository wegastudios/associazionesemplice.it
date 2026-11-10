<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

/** Avvisi firmati del SaaS al portale (CONTRATTO_PORTALE.md §4). Non lanciano mai: un portale giù non blocca niente. */
interface PortalNotifier
{
    /** Avvisa che il catalogo ha una nuova revisione. Restituisce se il portale ha risposto 200. */
    public function catalogChanged(int $rev): bool;
}
