<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Providers\AreaSociServiceProvider;
use App\Modules\Attivita\Providers\AttivitaServiceProvider;
use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Backup\Providers\BackupServiceProvider;
use App\Modules\Cifratura\Providers\CifraturaServiceProvider;
use App\Modules\Contabilita\Providers\ContabilitaServiceProvider;
use App\Modules\Core\Providers\AuthServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Portale\Providers\PortaleServiceProvider;
use App\Modules\Risorse\Providers\RisorseServiceProvider;
use App\Modules\Soci\Providers\SociServiceProvider;
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
    SociServiceProvider::class,
    AttivitaServiceProvider::class,
    RisorseServiceProvider::class,
    AreaSociServiceProvider::class,
];
