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

    /**
     * Associazione attiva con questo indirizzo (pagine pubbliche, come la domanda di adesione); null se non c'è o è
     * sospesa o chiusa.
     *
     * @return array{id: string, name: string}|null
     */
    public function activeBySlug(string $slug): ?array;

    public function slug(string $orgId): ?string;

    /** @return list<string> gli id delle associazioni attive (lavori notturni) */
    public function activeIds(): array;

    /** @param  'active'|'suspended'|'closed'  $status */
    public function setStatus(string $orgId, string $status): void;
}
