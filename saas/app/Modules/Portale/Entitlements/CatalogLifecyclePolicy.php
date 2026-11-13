<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\LifecyclePolicy;
use Illuminate\Database\DatabaseManager;

/** Legge terms.trial dal catalogo pubblicato; se manca, in prova non si esporta e non si collega il commercialista. */
final readonly class CatalogLifecyclePolicy implements LifecyclePolicy
{
    public function __construct(
        private Entitlements $entitlements,
        private Catalog $catalog,
        private DatabaseManager $db,
    ) {}

    public function exportsAllowed(string $orgId): bool
    {
        return ! $this->readOnly($orgId) && $this->allowed($orgId, 'exports');
    }

    public function accountantLinkAllowed(string $orgId): bool
    {
        return ! $this->readOnly($orgId) && $this->allowed($orgId, 'accountant_access');
    }

    /** Prova finita senza un piano: l'associazione è sospesa ma i dati restano consultabili fino alla cancellazione. */
    public function readOnly(string $orgId): bool
    {
        return $this->db->connection('pgsql')->table('core.orgs as o')
            ->join('core.org_entitlements as e', 'e.org_id', '=', 'o.id')
            ->where('o.id', $orgId)->where('o.status', 'suspended')->where('e.status', 'trial')
            ->exists();
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
