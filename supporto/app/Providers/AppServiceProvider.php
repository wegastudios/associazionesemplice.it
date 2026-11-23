<?php

namespace App\Providers;

use App\Services\MailSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('site-ip', fn (Request $request) => Limit::perMinute(120)->by('ip:'.$request->ip()));

        RateLimiter::for('site-api', function (Request $request) {
            $clientId = explode('.', (string) $request->bearerToken(), 2)[0];

            return Limit::perMinute(300)->by('client:'.($clientId !== '' ? $clientId : $request->ip()));
        });

        // Form pubblico dei ticket: limite per indirizzo per fermare lo spam senza CAPTCHA di terzi
        RateLimiter::for('public-ticket', fn (Request $request) => Limit::perHour(10)->by('ticket:'.$request->ip()));

        // I lavori in coda usano la posta configurata dal pannello (se salvata)
        Queue::before(function () {
            try {
                app(MailSettings::class)->applyIfConfigured();
            } catch (\Throwable) {
                // database non ancora pronto (es. prima migrazione)
            }
        });
    }
}
