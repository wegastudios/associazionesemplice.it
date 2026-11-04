<?php

declare(strict_types=1);

use Tests\FeatureTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Architecture');

pest()->extend(FeatureTestCase::class)->in('Feature');
