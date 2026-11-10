<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Contracts;

/** Limiti di un'associazione ricavati dalla versione del piano e dagli addon (CONTRATTO_PORTALE.md §3.2). */
interface PlanLimits
{
    /**
     * @param  list<array{code: string, qty: int}>  $addons
     * @return array{operators: int, storage_gb: int, packages: array<string, array<string, mixed>>}
     *
     * @throws UnknownPlan
     * @throws UnknownAddon
     */
    public function resolve(string $plan, int $version, array $addons, int $members): array;
}
