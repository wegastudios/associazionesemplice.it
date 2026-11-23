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

    /** @var array<string, bool> */
    private array $readOnly = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /** Dimentica la copia letta (dopo una scrittura dei diritti). */
    public function forget(string $orgId): void
    {
        unset($this->loaded[$orgId], $this->readOnly[$orgId]);
    }

    /**
     * Prova finita senza un piano, nei giorni prima della cancellazione: l'associazione è sospesa ma i dati restano
     * consultabili. Si calcola una volta sola per richiesta, come i diritti, e si dimentica con loro.
     */
    public function readOnly(string $orgId): bool
    {
        if (! isset($this->readOnly[$orgId])) {
            $this->load($orgId);
        }

        return $this->readOnly[$orgId] ?? false;
    }

    public function for(string $orgId): OrgEntitlements
    {
        return $this->loaded[$orgId] ?? $this->load($orgId);
    }

    /**
     * Diritti e sola lettura con una sola istruzione (H17): ogni pagina del gestionale chiede entrambi. Senza diritti
     * salvati la risposta vuota non si ricorda, come prima: chi li scrive subito dopo li ritrova.
     */
    private function load(string $orgId): OrgEntitlements
    {
        $row = $this->db->connection('pgsql')->table('core.org_entitlements as e')
            ->leftJoin('core.orgs as o', 'o.id', '=', 'e.org_id')
            ->where('e.org_id', $orgId)
            ->first(['e.claims', 'e.status', 'o.status as org_status']);

        $this->readOnly[$orgId] = $row !== null && $row->org_status === 'suspended' && $row->status === 'trial';
        $parsed = $row !== null && is_string($row->claims) ? self::parse($row->claims) : null;

        return $parsed === null ? OrgEntitlements::none() : $this->loaded[$orgId] = $parsed;
    }

    private static function parse(string $claims): ?OrgEntitlements
    {
        $decoded = json_decode($claims, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $decoded */
        $c = ClaimsValidator::entitlements($decoded);
        $subscription = is_array($decoded['subscription'] ?? null) ? $decoded['subscription'] : [];
        $plan = is_string($subscription['plan'] ?? null) ? $subscription['plan'] : null;
        $planVersion = is_int($subscription['plan_version'] ?? null) ? $subscription['plan_version'] : null;

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
            is_int($decoded['members_limit'] ?? null) ? $decoded['members_limit'] : null,
            $plan,
            $planVersion,
        );
    }
}
