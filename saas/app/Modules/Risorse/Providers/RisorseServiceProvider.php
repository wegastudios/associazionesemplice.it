<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Providers;

use App\Modules\Core\Contracts\DataExport;
use App\Modules\Risorse\Export\RisorseDataExport;
use Illuminate\Support\ServiceProvider;

final class RisorseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([RisorseDataExport::class], DataExport::TAG);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }
}
