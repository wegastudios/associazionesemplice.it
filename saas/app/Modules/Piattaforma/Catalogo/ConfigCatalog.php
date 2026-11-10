<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Catalogo;

use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Contracts\PublishedCatalog;
use Illuminate\Contracts\Config\Repository;

/**
 * Prima fonte del catalogo: il file `config/catalogo.php`. Quando l'amministrazione avrà il suo editor, il catalogo
 * pubblicato verrà dal database e questo file resterà solo come contenuto iniziale.
 */
final class ConfigCatalog implements Catalog
{
    private ?PublishedCatalog $cache = null;

    public function __construct(private readonly Repository $config) {}

    public function published(): PublishedCatalog
    {
        if ($this->cache === null) {
            $raw = $this->config->get('catalogo');
            $document = CatalogValidator::validate(is_array($raw) ? $raw : []);
            $rev = $document['rev'];
            $this->cache = new PublishedCatalog(is_int($rev) ? $rev : 0, $document);
        }

        return $this->cache;
    }

    public function plan(string $code, int $version): ?array
    {
        return $this->published()->plan($code, $version);
    }
}
