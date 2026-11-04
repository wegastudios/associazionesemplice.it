<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** L'associazione su cui sta lavorando la richiesta o il lavoro in coda. */
final readonly class CurrentOrg
{
    public function __construct(
        public string $id,
        public string $connection,
    ) {}
}
