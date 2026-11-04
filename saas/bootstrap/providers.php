<?php

declare(strict_types=1);

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Cifratura\Providers\CifraturaServiceProvider;
use App\Modules\Core\Providers\AuthServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    AuthServiceProvider::class,
    CifraturaServiceProvider::class,
    AuditServiceProvider::class,
];
