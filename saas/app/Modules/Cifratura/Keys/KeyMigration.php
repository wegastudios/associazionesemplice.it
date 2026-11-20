<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\KeyVault;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * Passaggio delle chiavi dati da un custode della chiave principale all'altro (locale ⇄ servizio chiavi).
 *
 * Le chiavi dati non cambiano: cambia solo come sono avvolte. Per ogni associazione, in una transazione con le righe
 * bloccate: si apre ogni versione con il custode di partenza, la si riavvolge con quello di arrivo, si controlla che
 * si riapra uguale e solo allora si sostituisce. Una riga che il custode di arrivo apre già si lascia com'è: il
 * passaggio si può ripetere quante volte serve. Le modifiche passano dal ruolo proprietario (l'applicazione non può
 * modificare core.org_keys).
 */
final readonly class KeyMigration
{
    /** Associazione fittizia per le prove di avvolgimento (stesso formato degli identificativi veri). */
    public const PROBE_ORG = '00000000-0000-7000-8000-000000000000';

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return array{orgs: int, rewrapped: int, already: int, failed: list<string>}
     */
    public function rewrap(KeyVault $from, KeyVault $to, bool $dryRun): array
    {
        $owner = $this->db->connection('pgsql_owner');
        $result = ['orgs' => 0, 'rewrapped' => 0, 'already' => 0, 'failed' => []];

        foreach ($this->orgIds($owner) as $orgId) {
            $result['orgs']++;
            $counts = [0, 0];
            try {
                $owner->transaction(function () use ($owner, $orgId, $from, $to, $dryRun, &$counts): void {
                    $counts = $this->rewrapOrg($owner, $orgId, $from, $to, $dryRun);
                });
                $result['rewrapped'] += $counts[0];
                $result['already'] += $counts[1];
            } catch (Throwable) {
                // Nessun dettaglio: l'errore può venire dal servizio chiavi. La transazione dell'associazione è annullata.
                $result['failed'][] = $orgId;
            }
        }

        return $result;
    }

    /**
     * Quante chiavi dati si aprono con almeno uno dei custodi, su tutte quelle salvate (o le prime $limit).
     *
     * @param  list<KeyVault>  $vaults
     * @return array{total: int, open: int, failed: list<string>}
     */
    public function verify(array $vaults, ?int $limit = null): array
    {
        $query = $this->db->connection('pgsql')->table('core.org_keys')->orderBy('org_id')->orderBy('version');
        if ($limit !== null) {
            $query->limit($limit);
        }

        $result = ['total' => 0, 'open' => 0, 'failed' => []];
        foreach ($query->get(['org_id', 'version', 'wrapped_key']) as $row) {
            $result['total']++;
            [$orgId, $version, $wrapped] = self::row($row);
            $open = false;
            foreach ($vaults as $vault) {
                if (self::opens($vault, $orgId, $version, $wrapped) !== null) {
                    $open = true;

                    break;
                }
            }
            if ($open) {
                $result['open']++;
            } elseif (! in_array($orgId, $result['failed'], true)) {
                $result['failed'][] = $orgId;
            }
        }

        return $result;
    }

    /** Prova di avvolgimento e svolgimento con una chiave casuale su un'associazione fittizia. */
    public static function probe(KeyVault $vault): void
    {
        $key = random_bytes(32);
        if (! hash_equals($key, $vault->unwrap(self::PROBE_ORG, 1, $vault->wrap(self::PROBE_ORG, 1, $key)))) {
            throw new RuntimeException('La prova di avvolgimento ha dato una chiave diversa.');
        }
    }

    /** @return array{0: int, 1: int} riavvolte, già a posto */
    private function rewrapOrg(ConnectionInterface $owner, string $orgId, KeyVault $from, KeyVault $to, bool $dryRun): array
    {
        $rows = $owner->table('core.org_keys')->where('org_id', $orgId)->orderBy('version')->lockForUpdate()
            ->get(['org_id', 'version', 'wrapped_key']);

        $rewrapped = 0;
        $already = 0;
        foreach ($rows as $row) {
            [, $version, $wrapped] = self::row($row);

            if (self::opens($to, $orgId, $version, $wrapped) !== null) {
                $already++;

                continue;
            }

            $key = $from->unwrap($orgId, $version, $wrapped);
            $new = $to->wrap($orgId, $version, $key);
            if (! hash_equals($key, $to->unwrap($orgId, $version, $new))) {
                throw new RuntimeException('Chiave riavvolta diversa dall\'originale.');
            }
            sodium_memzero($key);

            if (! $dryRun) {
                $updated = $owner->table('core.org_keys')
                    ->where('org_id', $orgId)->where('version', $version)
                    ->update(['wrapped_key' => Bytea::encode($new)]);
                if ($updated !== 1) {
                    throw new RuntimeException('Riga della chiave non aggiornata.');
                }
            }
            $rewrapped++;
        }

        return [$rewrapped, $already];
    }

    /** @return list<string> */
    private function orgIds(ConnectionInterface $owner): array
    {
        $ids = [];
        foreach ($owner->table('core.org_keys')->distinct()->orderBy('org_id')->pluck('org_id') as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return array{0: string, 1: int, 2: string} */
    private static function row(mixed $row): array
    {
        $orgId = is_object($row) && isset($row->org_id) && is_string($row->org_id) ? $row->org_id : null;
        $version = is_object($row) && isset($row->version) && is_numeric($row->version) ? (int) $row->version : null;
        if ($orgId === null || $version === null || ! is_object($row) || ! isset($row->wrapped_key)) {
            throw new RuntimeException('Riga di core.org_keys non valida.');
        }

        return [$orgId, $version, Bytea::decode($row->wrapped_key)];
    }

    private static function opens(KeyVault $vault, string $orgId, int $version, string $wrapped): ?string
    {
        try {
            return $vault->unwrap($orgId, $version, $wrapped);
        } catch (Throwable) {
            return null;
        }
    }
}
