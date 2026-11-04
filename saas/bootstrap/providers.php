<?php

declare(strict_types=1);

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Backup\Providers\BackupServiceProvider;
use App\Modules\Cifratura\Providers\CifraturaServiceProvider;
use App\Modules\Contabilita\Providers\ContabilitaServiceProvider;
use App\Modules\Core\Providers\AuthServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Portale\Providers\PortaleServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    AuthServiceProvider::class,
    CifraturaServiceProvider::class,
    AuditServiceProvider::class,
    PortaleServiceProvider::class,
    BackupServiceProvider::class,
    ContabilitaServiceProvider::class,
];
