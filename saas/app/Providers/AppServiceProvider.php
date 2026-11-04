<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Errori subito invece di comportamenti silenziosi: attributi inesistenti, assegnazioni ignorate,
        // relazioni caricate una riga alla volta.
        Model::shouldBeStrict();

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
