<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Contracts;

/** Piani, prezzi e promozioni del SaaS: l'unica fonte è l'amministrazione della piattaforma (/saas/admin). */
interface Catalog
{
    /** Il catalogo oggi in vigore. */
    public function published(): PublishedCatalog;

    /**
     * Una versione di un piano tra tutte quelle mai pubblicate (anche non più in vendita), o null se non è mai esistita.
     *
     * @return array<string, mixed>|null
     */
    public function plan(string $code, int $version): ?array;
}
