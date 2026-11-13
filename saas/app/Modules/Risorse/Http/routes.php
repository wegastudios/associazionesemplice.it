<?php

declare(strict_types=1);

use App\Modules\Risorse\Http\Controllers\ReservationsController;
use App\Modules\Risorse\Http\Controllers\ResourcesController;
use Illuminate\Support\Facades\Route;

// Pacchetto «Risorse e prenotazioni»: senza, 403 con l'invito ad aggiungerlo dal portale.
Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:resources'])->prefix('gestionale')->group(function (): void {
    Route::middleware('can:people.view')->group(function (): void {
        Route::get('/risorse', [ResourcesController::class, 'index'])->name('resources');
        Route::get('/risorse/calendario', [ReservationsController::class, 'calendar'])->name('resources.calendar');
        Route::get('/risorse/{resource}', [ResourcesController::class, 'show'])->whereUuid('resource')->name('resources.show');
    });

    Route::middleware('can:people.edit')->group(function (): void {
        Route::get('/risorse/nuova', [ResourcesController::class, 'create'])->name('resources.create');
        Route::post('/risorse', [ResourcesController::class, 'store'])->name('resources.store');
        Route::put('/risorse/{resource}', [ResourcesController::class, 'update'])->whereUuid('resource')->name('resources.update');
        Route::post('/risorse/{resource}/archivia', [ResourcesController::class, 'archive'])->whereUuid('resource')->name('resources.archive');
        Route::post('/risorse/{resource}/prenotazioni', [ReservationsController::class, 'store'])->whereUuid('resource')->name('reservations.store');
        Route::post('/risorse/{resource}/preventivo', [ReservationsController::class, 'quote'])->whereUuid('resource')->name('reservations.quote');
        Route::post('/risorse/{resource}/blocchi', [ReservationsController::class, 'block'])->whereUuid('resource')->name('reservations.block');
        Route::post('/prenotazioni/{reservation}/annulla', [ReservationsController::class, 'cancel'])->whereUuid('reservation')->name('reservations.cancel');
        Route::post('/risorse/{resource}/attesa', [ReservationsController::class, 'waitlistJoin'])->whereUuid('resource')->name('resources.waitlist');
        Route::post('/risorse/{resource}/attesa/{person}/esci', [ReservationsController::class, 'waitlistLeave'])->whereUuid(['resource', 'person'])->name('resources.waitlist.leave');
    });

    // Gli incassi scrivono in prima nota: servono anche i permessi della contabilità.
    Route::middleware(['can:people.edit', 'can:accounting.edit', 'can:accounting.income'])
        ->post('/prenotazioni/{reservation}/incassa', [ReservationsController::class, 'collect'])->whereUuid('reservation')->name('reservations.collect');
});
