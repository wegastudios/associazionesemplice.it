<?php

use App\Services\Site\TicketRegistrar;
use Illuminate\Support\Facades\Schedule;

if (config('support.role') === 'site') {
    // Registra presso l'hub i ticket rimasti in attesa (hub non raggiungibile al momento dell'invio)
    Schedule::call(fn () => app(TicketRegistrar::class)->registerPending())
        ->name('tickets-register-pending')
        ->everyMinute()
        ->withoutOverlapping()
        ->onOneServer();
}
