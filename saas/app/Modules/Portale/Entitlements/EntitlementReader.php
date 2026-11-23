<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Core\Contracts\SharedCache;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Legge i diritti già verificati e salvati (core.org_entitlements). Dentro la stessa richiesta (o lo stesso lavoro in
 * coda) la lettura di un'associazione si fa una volta sola; ogni scrittura dei diritti la dimentica subito.
 *
 * Tra una richiesta e l'altra (H20) i diritti stanno nella cache generale con un «giro» per associazione: ogni scrittura
 * dei diritti o dello stato (sospensione) cambia il giro a transazione chiusa, e la copia con il giro
 * vecchio non vale più. Giro, diritti (fino a 5 minuti) e stato con sola lettura (fino a 60 secondi) si leggono insieme,
 * con un solo accesso alla cache.
 */
final class EntitlementReader implements Entitlements
{
    private const ROUND = 'saas:entitlements:round:';

    private const COPY = 'saas:entitlements:copy:';

    private const STATE = 'saas:entitlements:state:';

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

        $cache = SharedCache::store('entitlements');
        if ($cache === null) {
            return;
        }
        // A transazione chiusa (subito, se non ce n'è una): prima, chi rilegge la tabella vedrebbe ancora i diritti vecchi.
        $this->db->connection('pgsql')->afterCommit(static function () use ($cache, $orgId): void {
            SharedCache::put($cache, self::ROUND.$orgId, bin2hex(random_bytes(8)), self::ROUND_SECONDS);
        });
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
        $this->readOnly[$orgId] = $row['read_only'];

        return $row['claims'] === null ? OrgEntitlements::none() : $this->loaded[$orgId] = self::parse($row['claims']);
    }

    /**
     * Dalla copia tra le richieste, se il suo giro è quello attuale, altrimenti dalla tabella.
     *
     * @return array{claims: array<string, mixed>|null, read_only: bool}
     */
    private function stored(string $orgId): array
    {
        $cache = SharedCache::store('entitlements');
        $found = SharedCache::many($cache, [self::ROUND.$orgId, self::COPY.$orgId, self::STATE.$orgId]);
        $round = $found[self::ROUND.$orgId] ?? null;
        $copy = $found[self::COPY.$orgId] ?? null;
        $state = $found[self::STATE.$orgId] ?? null;
        if (is_string($round) && is_array($copy) && ($copy['round'] ?? null) === $round && is_array($state) && ($state['round'] ?? null) === $round) {
            /** @var array{claims: array<string, mixed>|null, read_only: bool} */
            return ['claims' => $copy['claims'] ?? null, 'read_only' => ($state['read_only'] ?? false) === true];
        }

        $row = $this->db->connection('pgsql')->table('core.org_entitlements as e')
            ->leftJoin('core.orgs as o', 'o.id', '=', 'e.org_id')
            ->where('e.org_id', $orgId)
            ->first(['e.claims', 'e.status', 'o.status as org_status']);
        $claims = $row?->claims;
        $decoded = is_string($claims) ? json_decode($claims, true, 16, JSON_THROW_ON_ERROR) : null;
        // Anche «nessun diritto salvato» si ricorda: la scrittura dei diritti cambia il giro.
        $value = [
            'claims' => is_array($decoded) ? self::onlyNeeded($decoded) : null,
            'read_only' => $row !== null && $row->org_status === 'suspended' && $row->status === 'trial',
        ];

        if ($cache !== null && ! is_string($round)) {
            // Primo uso o giro scaduto: se ne crea uno. Se nel frattempo ne ha scritto uno qualcun altro (anche una
            // scrittura dei diritti), la copia appena letta potrebbe essere vecchia: non si salva.
            $mine = bin2hex(random_bytes(8));
            $round = SharedCache::add($cache, self::ROUND.$orgId, $mine, self::ROUND_SECONDS) ? $mine : null;
        }
        if (is_string($round)) {
            // Due copie con durate diverse (Audit): i diritti fino a 5 minuti, stato e sola lettura fino a 60 secondi.
            SharedCache::put($cache, self::COPY.$orgId, ['claims' => $value['claims'], 'round' => $round], SharedCache::seconds('entitlements'));
            SharedCache::put($cache, self::STATE.$orgId, ['read_only' => $value['read_only'], 'round' => $round], SharedCache::seconds('status'));
        }

        return $value;
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
