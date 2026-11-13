<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\LifecyclePolicy;

/** Legge terms.trial dal catalogo pubblicato; se manca, in prova non si esporta e non si collega il commercialista. */
final readonly class CatalogLifecyclePolicy implements LifecyclePolicy
{
    public function __construct(
        private Entitlements $entitlements,
        private Catalog $catalog,
    ) {}

    public function exportsAllowed(string $orgId): bool
    {
        return $this->allowed($orgId, 'exports');
    }

    public function accountantLinkAllowed(string $orgId): bool
    {
        return $this->allowed($orgId, 'accountant_access');
    }

    private function allowed(string $orgId, string $key): bool
    {
        if ($this->entitlements->for($orgId)->status !== 'trial') {
            return true;
        }

        $terms = $this->catalog->published()->document['terms'] ?? null;
        $trial = is_array($terms) ? ($terms['trial'] ?? null) : null;

        return is_array($trial) && ($trial[$key] ?? false) === true;
    }
}
