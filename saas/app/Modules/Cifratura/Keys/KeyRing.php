<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Contracts\OrgKeyMissing;
use App\Modules\Cifratura\Contracts\OrgKeys;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chiavi dati delle associazioni. Nel database (core.org_keys) stanno solo cifrate con la chiave principale; in chiaro
 * esistono solo in memoria (KeyCache). Cancellare le righe di un'associazione rende illeggibili i suoi dati cifrati.
 */
final class KeyRing implements OrgKeys
{
    /** Versione più recente per associazione, tenuta in memoria per un minuto: salvare una scheda non la chiede ogni volta al database. */
    private const LATEST_TTL = 60;

    /** @var array<string, array{0: int, 1: int}> versione e istante in cui è stata letta */
    private array $latest = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly KeyVault $vault,
        private readonly KeyCache $cache,
    ) {}

    public function create(string $orgId): void
    {
        if ($this->readLatest($orgId) !== null) {
            return;
        }

        $this->connection()->table('core.org_keys')->insertOrIgnore([
            'org_id' => $orgId,
            'version' => 1,
            'wrapped_key' => Bytea::encode($this->vault->wrap($orgId, 1, random_bytes(32))),
            'created_at' => now(),
        ]);
    }

    /** Chiave per cifrare: la versione più recente. */
    public function current(string $orgId): OrgKey
    {
        $version = $this->latestVersion($orgId);
        if ($version === null) {
            // La chiave nasce solo con l'associazione (create). Se manca, ricrearla renderebbe illeggibili i dati
            // già cifrati: si segnala e non si scrive.
            $this->reportMissing($orgId);
            throw new OrgKeyMissing('Chiave dati dell\'associazione mancante: scrittura rifiutata.');
        }

        try {
            return $this->version($orgId, $version);
        } catch (DecryptionFailed) {
            // La versione in memoria può essere vecchia (chiavi cambiate da un altro processo): si rilegge dal database.
            unset($this->latest[$orgId]);
            $fresh = $this->readLatest($orgId) ?? throw new DecryptionFailed('Chiave dell\'associazione inesistente o distrutta.');
            $this->latest[$orgId] = [$fresh, time()];

            return $this->version($orgId, $fresh);
        }
    }

    /** Chiave di una versione precisa, per decifrare. */
    public function version(string $orgId, int $version): OrgKey
    {
        $root = $this->cache->get($orgId, $version);

        if ($root === null) {
            $wrapped = $this->connection()->table('core.org_keys')
                ->where('org_id', $orgId)->where('version', $version)
                ->value('wrapped_key');
            if ($wrapped === null) {
                throw new DecryptionFailed('Chiave dell\'associazione inesistente o distrutta.');
            }
            $root = $this->vault->unwrap($orgId, $version, Bytea::decode($wrapped));
            $this->cache->put($orgId, $version, $root);
        }

        return new OrgKey($version, $root);
    }

    /** Dimentica le chiavi in memoria (dopo una distruzione). */
    public function forget(string $orgId): void
    {
        unset($this->latest[$orgId]);
        $this->cache->forget($orgId);
    }

    /** Allarme nel log di sistema e riga nel registro della piattaforma (solo l'identificativo, mai chiavi né dati). */
    private function reportMissing(string $orgId): void
    {
        Log::critical('Chiave dati di un\'associazione mancante', ['org' => $orgId]);

        try {
            (new KeyAudit($this->db))->record(KeyAudit::ORG_KEY_MISSING, ['org' => $orgId], $orgId);
        } catch (Throwable) {
            // Il log di sistema resta: il registro non deve nascondere l'errore vero.
        }
    }

    private function latestVersion(string $orgId): ?int
    {
        $hit = $this->latest[$orgId] ?? null;
        if ($hit !== null && time() - $hit[1] < self::LATEST_TTL) {
            return $hit[0];
        }
        $version = $this->readLatest($orgId);
        if ($version !== null) {
            $this->latest[$orgId] = [$version, time()];
        }

        return $version;
    }

    private function readLatest(string $orgId): ?int
    {
        $version = $this->connection()->table('core.org_keys')->where('org_id', $orgId)->max('version');

        return is_numeric($version) ? (int) $version : null;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection('pgsql');
    }
}
