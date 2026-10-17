<?php

use App\Http\Controllers\Api\V1\HubTicketController;
use Illuminate\Support\Facades\Route;

// API per i siti di supporto registrati (mai dal JavaScript pubblico)
Route::prefix('v1')->middleware(['throttle:site-ip', 'site.auth', 'throttle:site-api'])->group(function () {
    Route::get('/rules', [HubTicketController::class, 'rules']);
    Route::post('/tickets', [HubTicketController::class, 'store']);
    Route::get('/tickets/{ticket}', [HubTicketController::class, 'show'])->whereNumber('ticket');
    Route::patch('/tickets/{ticket}', [HubTicketController::class, 'update'])->whereNumber('ticket');
});
