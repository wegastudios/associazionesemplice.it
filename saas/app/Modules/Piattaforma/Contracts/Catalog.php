<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Contracts;

/** Piani, prezzi e promozioni del SaaS: l'unica fonte è l'amministrazione della piattaforma (/saas/admin). */
interface Catalog
{
    /** Il catalogo oggi in vigore. */
    public function published(): PublishedCatalog;
}
