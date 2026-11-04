<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** Operazioni sulle associazioni per chi sta fuori da Core (il portale, tramite il modulo Portale). */
interface OrgDirectory
{
    /** Crea l'associazione nel database condiviso, con la sua chiave, e invita il titolare. Restituisce l'id. */
    public function createWithOwner(string $name, string $slug, string $ownerEmail, string $ownerName, string $portalRef): string;

    public function findByPortalRef(string $portalRef): ?string;

    public function portalRef(string $orgId): ?string;

    public function slugTaken(string $slug): bool;

    /** @param  'active'|'suspended'|'closed'  $status */
    public function setStatus(string $orgId, string $status): void;
}
