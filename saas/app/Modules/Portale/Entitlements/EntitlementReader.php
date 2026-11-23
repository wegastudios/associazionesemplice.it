<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Core\Contracts\SharedCache;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Legge i diritti già verificati e salvati (core.org_entitlements). Dentro la stessa richiesta (o lo stesso lavoro in
 * coda) la lettura di un'associazione si fa una volta sola; ogni scrittura dei diritti la dimentica subito.
 *
 * Tra una richiesta e l'altra (H20) i diritti stanno nella cache generale con un «giro» per associazione: ogni scrittura
 * dei diritti o dello stato (sospensione) cambia il giro, subito e di nuovo a transazione chiusa, e la copia con il giro
 * vecchio non vale più. Giro e copia si leggono insieme, con un solo accesso alla cache.
 */
final class EntitlementReader implements Entitlements
{
    private const ROUND = 'saas:entitlements:round:';

    private const COPY = 'saas:entitlements:copy:';

    /** Un giro scaduto ne fa nascere uno nuovo: la copia si rilegge una volta, niente di più. */
    private const ROUND_SECONDS = 30 * 86400;

    /** I campi dei diritti che servono: niente nomi né email del titolare, che il portale manda con l'attivazione. */
    private const FIELDS = ['sub', 'ref', 'rev', 'status', 'until', 'trial_ends', 'members', 'operators', 'storage_gb', 'packages', 'partner', 'members_limit'];

    /** @var array<string, OrgEntitlements> */
    private array $loaded = [];

    /** @var array<string, bool> */
    private array $readOnly = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /** Dimentica la copia letta (dopo una scrittura dei diritti o dello stato), anche quella tra le richieste. */
    public function forget(string $orgId): void
    {
        unset($this->loaded[$orgId], $this->readOnly[$orgId]);

        $newRound = static function () use ($orgId): void {
            try {
                SharedCache::store('entitlements')?->put(self::ROUND.$orgId, bin2hex(random_bytes(8)), self::ROUND_SECONDS);
            } catch (Throwable) {
                // Cache che non risponde: la copia scade da sola (saas.shared_cache.entitlements).
            }
        };
        $newRound();
        // Una richiesta che ha letto la tabella prima del commit può aver salvato la copia vecchia con il giro nuovo.
        $this->db->connection('pgsql')->afterCommit($newRound);
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
        $row = $this->stored($orgId);
        $this->readOnly[$orgId] = $row !== null && $row['read_only'];
        $parsed = $row === null || $row['claims'] === null ? null : self::parse($row['claims']);

        return $parsed === null ? OrgEntitlements::none() : $this->loaded[$orgId] = $parsed;
    }

    /** @return array{claims: array<string, mixed>|null, read_only: bool}|null */
    private function stored(string $orgId): ?array
    {
        $cache = SharedCache::store('entitlements');
        $round = null;
        if ($cache !== null) {
            try {
                [$round, $copy] = self::cached($cache, $orgId);
                if ($copy !== null) {
                    return $copy;
                }
            } catch (Throwable) {
                $cache = null;
            }
        }

        $row = $this->db->connection('pgsql')->table('core.org_entitlements as e')
            ->leftJoin('core.orgs as o', 'o.id', '=', 'e.org_id')
            ->where('e.org_id', $orgId)
            ->first(['e.claims', 'e.status', 'o.status as org_status']);
        if ($row === null) {
            return null;
        }

        $decoded = is_string($row->claims) ? json_decode($row->claims, true, 16, JSON_THROW_ON_ERROR) : null;
        $value = [
            'claims' => is_array($decoded) ? self::onlyNeeded($decoded) : null,
            'read_only' => $row->org_status === 'suspended' && $row->status === 'trial',
        ];
        if ($cache !== null && $round !== null) {
            try {
                $cache->put(self::COPY.$orgId, $value + ['round' => $round], SharedCache::seconds('entitlements'));
            } catch (Throwable) {
                // Cache piena o ferma: si rilegge la tabella la volta dopo.
            }
        }

        return $value;
    }

    /**
     * Giro attuale e copia valida, se c'è. Senza giro (primo uso, cache svuotata) se ne crea uno.
     *
     * @return array{0: string|null, 1: array{claims: array<string, mixed>|null, read_only: bool}|null}
     */
    private static function cached(Repository $cache, string $orgId): array
    {
        $found = $cache->many([self::ROUND.$orgId, self::COPY.$orgId]);
        $round = $found[self::ROUND.$orgId] ?? null;
        if (! is_string($round)) {
            $cache->add(self::ROUND.$orgId, bin2hex(random_bytes(8)), self::ROUND_SECONDS);
            $round = $cache->get(self::ROUND.$orgId);

            return [is_string($round) ? $round : null, null];
        }

        $copy = $found[self::COPY.$orgId] ?? null;
        if (! is_array($copy) || ($copy['round'] ?? null) !== $round || ! is_bool($copy['read_only'] ?? null)) {
            return [$round, null];
        }
        $claims = $copy['claims'] ?? null;
        /** @var array<string, mixed>|null $claims */
        $claims = is_array($claims) ? $claims : null;

        return [$round, ['claims' => $claims, 'read_only' => $copy['read_only']]];
    }

    /**
     * @param  array<mixed>  $decoded
     * @return array<string, mixed>
     */
    private static function onlyNeeded(array $decoded): array
    {
        /** @var array<string, mixed> $kept */
        $kept = array_intersect_key($decoded, array_flip(self::FIELDS));
        $subscription = is_array($decoded['subscription'] ?? null) ? $decoded['subscription'] : [];
        $kept['subscription'] = array_intersect_key($subscription, array_flip(['plan', 'plan_version']));

        return $kept;
    }

    /** @param  array<string, mixed>  $decoded */
    private static function parse(array $decoded): OrgEntitlements
    {
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