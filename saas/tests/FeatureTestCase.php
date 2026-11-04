<?php

declare(strict_types=1);

namespace Tests;

use Tests\Concerns\UsesSaasDatabase;

/** Test con il database PostgreSQL vero (cartella Feature). */
abstract class FeatureTestCase extends TestCase
{
    use UsesSaasDatabase;
}
