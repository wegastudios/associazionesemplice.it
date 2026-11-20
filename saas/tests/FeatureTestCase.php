<?php

declare(strict_types=1);

namespace Tests;

use Tests\Concerns\UsesSaasDatabase;

/** Test con il database PostgreSQL vero (cartella Feature). */
abstract class FeatureTestCase extends TestCase
{
    use UsesSaasDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le pagine si provano senza compilare CSS e JavaScript.
        $this->withoutVite();
    }

    /**
     * Un indirizzo senza host parte sempre dall'indirizzo dell'app (APP_URL), non da quello dell'ultima richiesta: il
     * gestionale non risponde sugli indirizzi dei soci, e un test che passa dall'area soci non deve portarsi dietro il suo host.
     */
    protected function prepareUrlForRequest($uri)
    {
        if (is_string($uri) && ! str_starts_with($uri, 'http://') && ! str_starts_with($uri, 'https://')) {
            $base = config('app.url');

            return rtrim(is_string($base) ? $base : 'http://localhost', '/').'/'.ltrim($uri, '/');
        }

        return parent::prepareUrlForRequest($uri);
    }
}
