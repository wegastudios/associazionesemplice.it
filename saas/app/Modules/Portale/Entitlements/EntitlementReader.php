<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/** Legge i diritti già verificati e salvati (core.org_entitlements). */
final readonly class EntitlementReader implements Entitlements
{
    public function __construct(private DatabaseManager $db) {}

    public function for(string $orgId): OrgEntitlements
    {
        $row = $this->db->connection('pgsql')->table('core.org_entitlements')->where('org_id', $orgId)->first(['claims']);
        if ($row === null || ! is_string($row->claims)) {
            return OrgEntitlements::none();
        }

        $decoded = json_decode($row->claims, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return OrgEntitlements::none();
        }
        /** @var array<string, mixed> $decoded */
        $c = ClaimsValidator::entitlements($decoded);

        return new OrgEntitlements(
            $c['rev'],
            $c['status'],
            CarbonImmutable::parse($c['until']),
            $c['trial_ends'] === null ? null : CarbonImmutable::parse($c['trial_ends']),
            $c['members'],
            $c['operators'],
            $c['storage_gb'],
            $c['packages'],
            $c['partner'],
        );
    }
}
