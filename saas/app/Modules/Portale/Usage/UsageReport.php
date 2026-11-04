<?php

declare(strict_types=1);

namespace App\Modules\Portale\Usage;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Consumi per la fatturazione del portale (fascia di soci, operatori, fatture elettroniche), una volta al giorno.
 * Solo numeri, nessun dato personale.
 *
 * «Soci» oggi sono le schede attive in anagrafica: con il modulo Soci diventeranno i soci con tessera valida.
 */
final readonly class UsageReport
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private PortalConfig $config,
        private Factory $http,
    ) {}

    /** @return list<array{org_id: string, ref: string, members: int, operators: int, einvoices_year: int}> */
    public function collect(): array
    {
        $orgs = $this->db->connection('pgsql')->table('core.orgs')
            ->whereNotNull('portal_ref')->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'portal_ref']);

        $rows = [];
        foreach ($orgs as $org) {
            if (! is_string($org->id) || ! is_string($org->portal_ref)) {
                continue;
            }
            $members = $this->context->run($org->id, fn () => $this->db->connection($this->context->require()->connection)
                ->table('app.people')->whereNull('deleted_at')->count());
            $rows[] = [
                'org_id' => $org->id,
                'ref' => $org->portal_ref,
                'members' => $members,
                'operators' => $this->db->connection('pgsql')->table('core.org_users')->where('org_id', $org->id)->count(),
                'einvoices_year' => 0,
            ];
        }

        return $rows;
    }

    /** @return int associazioni comunicate */
    public function send(): int
    {
        $rows = $this->collect();
        $now = time();
        $token = EdDsaJwt::sign([
            'iss' => $this->config->saasUrl(),
            'aud' => $this->config->portalUrl(),
            'typ' => 'usage',
            'iat' => $now,
            'exp' => $now + 600,
            'jti' => Str::uuid7()->toString(),
            'date' => date('Y-m-d', $now),
            'orgs' => $rows,
        ], $this->config->signingSeed());

        $response = $this->http->timeout(10)->acceptJson()->post($this->config->portalUrl().'/api/saas/v1/usage', ['token' => $token]);
        if (! $response->successful()) {
            throw new RuntimeException("Il portale non ha accettato i consumi (HTTP {$response->status()}).");
        }

        return count($rows);
    }
}
