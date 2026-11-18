<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Legge i diritti già verificati e salvati (core.org_entitlements). Dentro la stessa richiesta (o lo stesso lavoro in
 * coda) la lettura di un'associazione si fa una volta sola; ogni scrittura dei diritti la dimentica subito.
 */
final class EntitlementReader implements Entitlements
{
    /** @var array<string, OrgEntitlements> */
    private array $loaded = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /** Dimentica la copia letta (dopo una scrittura dei diritti). */
    public function forget(string $orgId): void
    {
        unset($this->loaded[$orgId]);
    }

    public function for(string $orgId): OrgEntitlements
    {
        if (isset($this->loaded[$orgId])) {
            return $this->loaded[$orgId];
        }

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
        $subscription = is_array($decoded['subscription'] ?? null) ? $decoded['subscription'] : [];
        $plan = is_string($subscription['plan'] ?? null) ? $subscription['plan'] : null;
        $planVersion = is_int($subscription['plan_version'] ?? null) ? $subscription['plan_version'] : null;

        return $this->loaded[$orgId] = new OrgEntitlements(
            $c['rev'],
            $c['status'],
            CarbonImmutable::parse($c['until']),
            $c['trial_ends'] === null ? null : CarbonImmutable::parse($c['trial_ends']),
            $c['members'],
            $c['operators'],
            $c['storage_gb'],
            $c['packages'],
            $c['partner'],
            is_int($decoded['members_limit'] ?? null) ? $decoded['members_limit'] : null,
            $plan,
            $planVersion,
        );
    }
}
