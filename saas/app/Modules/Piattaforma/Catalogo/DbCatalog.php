<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Catalogo;

use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Contracts\PublishedCatalog;
use Illuminate\Database\DatabaseManager;
use stdClass;

/**
 * Catalogo in vigore: l'ultima revisione pubblicata dall'amministrazione (core.catalog_revisions). Finché nessuno ha
 * pubblicato niente vale il contenuto iniziale di config/catalogo.php. Le versioni dei piani già pubblicate si
 * ritrovano in tutte le revisioni, anche quando non sono più in vendita.
 */
final class DbCatalog implements Catalog
{
    private ?PublishedCatalog $cache = null;

    public function __construct(private readonly DatabaseManager $db, private readonly ConfigCatalog $initial) {}

    public function published(): PublishedCatalog
    {
        if ($this->cache === null) {
            $row = $this->db->connection('pgsql')->table('core.catalog_revisions')->orderByDesc('rev')->first(['rev', 'document']);
            $this->cache = $row === null ? $this->initial->published() : self::fromRow($row->rev, $row->document);
        }

        return $this->cache;
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

    /** Dimentica la copia in memoria (dopo una pubblicazione). */
    public function refresh(): void
    {
        $this->cache = null;
    }

    private static function fromRow(mixed $rev, mixed $document): PublishedCatalog
    {
        $decoded = is_string($document) ? json_decode($document, true, 64) : null;
        /** @var array<string, mixed> $doc */
        $doc = is_array($decoded) ? $decoded : [];

        return new PublishedCatalog(is_numeric($rev) ? (int) $rev : 0, $doc);
    }
}
