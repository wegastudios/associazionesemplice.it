<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Contracts\OrgKeys;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Chiavi dati delle associazioni. Nel database (core.org_keys) stanno solo cifrate con la chiave principale; in chiaro
 * esistono solo in memoria (KeyCache). Cancellare le righe di un'associazione rende illeggibili i suoi dati cifrati.
 */
final readonly class KeyRing implements OrgKeys
{
    public function __construct(
        private DatabaseManager $db,
        private KeyVault $vault,
        private KeyCache $cache,
    ) {}

    public function create(string $orgId): void
    {
        if ($this->latestVersion($orgId) !== null) {
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
            $this->create($orgId);
            $version = $this->latestVersion($orgId) ?? throw new RuntimeException('Chiave dell\'associazione non creata.');
        }

        return $this->version($orgId, $version);
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
        $this->cache->forget($orgId);
    }

    private function latestVersion(string $orgId): ?int
    {
        $version = $this->connection()->table('core.org_keys')->where('org_id', $orgId)->max('version');

        return is_numeric($version) ? (int) $version : null;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection('pgsql');
    }
}
