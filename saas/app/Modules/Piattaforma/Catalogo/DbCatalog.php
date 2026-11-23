<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Catalogo;

use App\Modules\Core\Contracts\SharedCache;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Contracts\PublishedCatalog;
use App\Modules\Portale\Contracts\PortalNotifier;
use Illuminate\Database\DatabaseManager;
use stdClass;

/**
 * Catalogo in vigore: l'ultima revisione pubblicata dall'amministrazione (core.catalog_revisions). Finché nessuno ha
 * pubblicato niente vale il contenuto iniziale di config/catalogo.php. Le versioni dei piani già pubblicate si
 * ritrovano in tutte le revisioni, anche quando non sono più in vendita.
 */
final class DbCatalog implements Catalog
{
    private const SHARED = 'saas:catalog:published';

    private ?PublishedCatalog $cache = null;

    public function __construct(private readonly DatabaseManager $db, private readonly ConfigCatalog $initial, private readonly PortalNotifier $notifier) {}

    /** Solo lettura: non scrive e non chiama il portale. Le revisioni nuove nascono in `align()` e alla pubblicazione. */
    public function published(): PublishedCatalog
    {
        return $this->cache ??= $this->shared() ?? $this->readAndShare();
    }

    /** La copia tra le richieste (H20), se c'è: il catalogo cambia solo con una pubblicazione, che la dimentica. */
    private function shared(): ?PublishedCatalog
    {
        $hit = SharedCache::get(SharedCache::store('catalog'), self::SHARED);
        if (! is_array($hit) || ! is_int($hit['rev'] ?? null) || ! is_array($hit['document'] ?? null)) {
            return null;
        }
        /** @var array<string, mixed> $document */
        $document = $hit['document'];

        return new PublishedCatalog($hit['rev'], $document);
    }

    private function readAndShare(): PublishedCatalog
    {
        $row = $this->db->connection('pgsql')->table('core.catalog_revisions')->orderByDesc('rev')->first(['rev', 'document']);
        $catalog = $row === null ? $this->initial->published() : self::fromRow($row->rev, $row->document);
        SharedCache::put(SharedCache::store('catalog'), self::SHARED, ['rev' => $catalog->rev, 'document' => $catalog->document], SharedCache::seconds('catalog'));

        return $catalog;
    }

    private static function forgetShared(): void
    {
        SharedCache::forget(SharedCache::store('catalog'), self::SHARED);
    }

    /**
     * Allinea il catalogo in vigore alla configurazione (giorni di conservazione), scrivendo una revisione nuova se
     * serve e avvisando il portale. Si chiama al rilascio (`saas:catalog:align`) e prima di una pubblicazione, mai da
     * una lettura.
     */
    public function align(): PublishedCatalog
    {
        $this->cache = null;
        $row = $this->db->connection('pgsql')->table('core.catalog_revisions')->orderByDesc('rev')->first(['rev', 'document']);
        $current = $row === null ? $this->initial->published() : self::fromRow($row->rev, $row->document);

        return $this->cache = $this->withLifecycle($current, $row !== null);
    }

    /**
     * I giorni di conservazione della configurazione: gli stessi che una pubblicazione scrive in `terms.lifecycle`.
     *
     * @return array{retention_days: int, trial_purge_days: int}
     */
    public static function lifecycle(): array
    {
        $retention = config('saas.portal.retention_days', 180);
        $trial = config('saas.portal.trial_purge_days', 7);

        return [
            'retention_days' => is_int($retention) && $retention > 0 ? $retention : 180,
            'trial_purge_days' => is_int($trial) && $trial > 0 ? $trial : 7,
        ];
    }

    /**
     * I giorni di conservazione hanno una sola fonte, la configurazione del gestionale, e il catalogo li espone in
     * `terms.lifecycle`. Il corpo servito non deve mai cambiare con la stessa `rev` (il portale lo rifiuta): se i numeri
     * della configurazione (o la vecchia chiave `terms.trial.purge_days`) non coincidono con l'ultima revisione, se ne
     * pubblica una nuova, con `rev` + 1, e quella è la sola che si serve. Ogni revisione nuova avvisa il portale, come
     * una pubblicazione dal pannello: il portale ricarica il catalogo solo da quell'avviso.
     */
    private function withLifecycle(PublishedCatalog $current, bool $stored): PublishedCatalog
    {
        $lifecycle = self::lifecycle();
        if (self::hasLifecycle($current, $lifecycle)) {
            return $current;
        }

        $connection = $this->db->connection('pgsql');
        $created = false;

        $published = $connection->transaction(function () use ($connection, $current, $stored, $lifecycle, &$created): PublishedCatalog {
            // Una sola scrittura alla volta: chi arriva dopo rilegge e trova la revisione già pubblicata.
            $connection->statement('SELECT pg_advisory_xact_lock(?)', [7_301_024]);
            $row = $connection->table('core.catalog_revisions')->orderByDesc('rev')->first(['rev', 'document']);
            if ($row !== null) {
                $latest = self::fromRow($row->rev, $row->document);
                if (self::hasLifecycle($latest, $lifecycle)) {
                    return $latest;
                }
                $current = $latest;
            } elseif (! $stored) {
                $connection->table('core.catalog_revisions')->insert([
                    'rev' => $current->rev,
                    'document' => $current->json(),
                    'note' => 'Contenuto iniziale',
                    'published_at' => now(),
                ]);
            }

            $document = $current->document;
            $terms = is_array($document['terms'] ?? null) ? $document['terms'] : [];
            if (is_array($terms['trial'] ?? null)) {
                unset($terms['trial']['purge_days']);
            }
            $terms['lifecycle'] = $lifecycle;
            $document['terms'] = $terms;
            $document['rev'] = $current->rev + 1;
            $next = new PublishedCatalog($current->rev + 1, $document);
            $connection->table('core.catalog_revisions')->insert([
                'rev' => $next->rev,
                'document' => $next->json(),
                'note' => 'Giorni di conservazione dalla configurazione',
                'published_at' => now(),
            ]);

            // Si serve ciò che è salvato (jsonb ordina le chiavi): i byte non cambiano tra la prima richiesta e le successive.
            $saved = $connection->table('core.catalog_revisions')->where('rev', $next->rev)->first(['rev', 'document']);
            $created = true;

            return $saved === null ? $next : self::fromRow($saved->rev, $saved->document);
        });

        if ($created) {
            self::forgetShared();
            // Se il portale non risponde ricarica comunque da sé quando si apre il listino o si compra.
            $this->notifier->catalogChanged($published->rev);
        }

        return $published;
    }

    /** @param  array{retention_days: int, trial_purge_days: int}  $lifecycle */
    private static function hasLifecycle(PublishedCatalog $catalog, array $lifecycle): bool
    {
        $terms = is_array($catalog->document['terms'] ?? null) ? $catalog->document['terms'] : [];
        $legacy = is_array($terms['trial'] ?? null) && array_key_exists('purge_days', $terms['trial']);

        return ! $legacy && ($terms['lifecycle'] ?? null) === $lifecycle;
    }

    public function plan(string $code, int $version): ?array
    {
        $current = $this->published()->plan($code, $version);
        if ($current !== null) {
            return $current;
        }

        $found = $this->db->connection('pgsql')->selectOne(
            'SELECT p.value AS plan FROM core.catalog_revisions r, jsonb_array_elements(r.document->\'plans\') AS p(value)
             WHERE p.value->>\'code\' = ? AND (p.value->>\'plan_version\')::int = ? ORDER BY r.rev DESC LIMIT 1',
            [$code, $version],
        );
        $decoded = $found instanceof stdClass && is_string($found->plan) ? json_decode($found->plan, true) : null;

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    /** Dimentica la copia in memoria e quella tra le richieste (dopo una pubblicazione). */
    public function refresh(): void
    {
        $this->cache = null;
        self::forgetShared();
    }

    private static function fromRow(mixed $rev, mixed $document): PublishedCatalog
    {
        $decoded = is_string($document) ? json_decode($document, true, 64) : null;
        /** @var array<string, mixed> $doc */
        $doc = is_array($decoded) ? $decoded : [];

        return new PublishedCatalog(is_numeric($rev) ? (int) $rev : 0, $doc);
    }
}
