<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

/** Soci attivi dell'associazione corrente: con tessera valida a quella data, o fondatori. */
interface Members
{
    public function activeCount(?string $date = null): int;

    public function isActive(string $personId, ?string $date = null): bool;
}
