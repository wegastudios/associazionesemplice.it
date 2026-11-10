<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Providers;

use App\Modules\Piattaforma\Catalogo\ConfigCatalog;
use App\Modules\Piattaforma\Contracts\Catalog;
use Illuminate\Support\ServiceProvider;

final class PiattaformaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Catalog::class, ConfigCatalog::class);
    }
}
