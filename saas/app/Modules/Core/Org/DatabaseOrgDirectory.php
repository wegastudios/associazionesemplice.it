<?php

declare(strict_types=1);

namespace App\Modules\Core\Org;

use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\Org;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseOrgDirectory implements OrgDirectory
{
    public function __construct(
        private DatabaseManager $db,
        private OrgProvisioner $provisioner,
        private OrgMembers $members,
    ) {}

    public function createWithOwner(string $name, string $slug, string $ownerEmail, string $ownerName, string $portalRef): string
    {
        return $this->db->connection('pgsql')->transaction(function () use ($name, $slug, $ownerEmail, $ownerName, $portalRef): string {
            $org = $this->provisioner->create($name, $slug);
            $this->db->connection('pgsql')->table('core.orgs')->where('id', $org->id)->update(['portal_ref' => $portalRef]);
            $this->members->invite($org, $ownerEmail, $ownerName, OrgRole::Owner, null);

            return $org->id;
        });
    }

    public function findByPortalRef(string $portalRef): ?string
    {
        $id = $this->db->connection('pgsql')->table('core.orgs')->where('portal_ref', $portalRef)->value('id');

        return is_string($id) ? $id : null;
    }

    public function portalRef(string $orgId): ?string
    {
        $ref = $this->db->connection('pgsql')->table('core.orgs')->where('id', $orgId)->value('portal_ref');

        return is_string($ref) ? $ref : null;
    }

    public function slugTaken(string $slug): bool
    {
        return Org::withTrashed()->where('slug', $slug)->exists();
    }

    public function activeBySlug(string $slug): ?array
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug) !== 1) {
            return null;
        }
        $org = $this->db->connection('pgsql')->table('core.orgs')->where('slug', $slug)->where('status', 'active')
            ->whereNull('deleted_at')->first(['id', 'name']);

        return $org !== null && is_string($org->id ?? null) && is_string($org->name ?? null) ? ['id' => $org->id, 'name' => $org->name] : null;
    }

    public function slug(string $orgId): ?string
    {
        $slug = $this->db->connection('pgsql')->table('core.orgs')->where('id', $orgId)->value('slug');

        return is_string($slug) ? $slug : null;
    }

    public function activeIds(): array
    {
        return array_values(array_filter(
            $this->db->connection('pgsql')->table('core.orgs')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all(),
            is_string(...),
        ));
    }

    public function setStatus(string $orgId, string $status): void
    {
        $this->db->connection('pgsql')->table('core.orgs')->where('id', $orgId)->update(['status' => $status, 'updated_at' => now()]);
    }
}
