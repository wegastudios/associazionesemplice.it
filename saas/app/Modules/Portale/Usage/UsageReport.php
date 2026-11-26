<?php

declare(strict_types=1);

namespace App\Modules\Portale\Usage;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use App\Modules\Soci\Contracts\Members;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Consumi per la fatturazione del portale (fascia di soci, operatori, fatture elettroniche), una volta al giorno.
 * Solo numeri, nessun dato personale.
 *
 * «Soci» sono i soci attivi: con la tessera valida oggi, o fondatori (Soci\Contracts\Members).
 */
final readonly class UsageReport
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private PortalConfig $config,
        private Factory $http,
        private Members $members,
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
            $members = $this->context->run($org->id, fn (): int => $this->members->activeCount());
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

    /**
     * Associazioni comunicate e, se il portale li dice, quante ha salvato e quante (e quali ref) non ha riconosciuto.
     * Mai i numeri dei soci.
     *
     * @return array{sent: int, saved: int|null, unknown: int|null, unknown_refs: list<string>}
     */
    public function send(): array
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

        $saved = $response->json('saved');
        $unknown = $response->json('unknown');
        // I ref che il portale non ha riconosciuto, se li manda: solo identificativi tecnici col formato dei nostri (al massimo 50).
        $refs = $response->json('unknown_refs');
        $unknownRefs = [];
        foreach (is_array($refs) ? $refs : [] as $ref) {
            if (count($unknownRefs) < 50 && is_string($ref) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref) === 1) {
                $unknownRefs[] = $ref;
            }
        }

        return [
            'sent' => count($rows),
            'saved' => is_int($saved) ? $saved : null,
            'unknown' => is_int($unknown) ? $unknown : null,
            'unknown_refs' => $unknownRefs,
        ];
    }
}
