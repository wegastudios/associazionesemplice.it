<?php

declare(strict_types=1);

use App\Modules\Attivita\Http\Controllers\ActivitiesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'two-factor', 'operator'])->prefix('gestionale')->group(function (): void {
    Route::middleware('can:people.view')->group(function (): void {
        Route::get('/attivita', [ActivitiesController::class, 'index'])->name('activities');
        Route::get('/attivita/{activity}', [ActivitiesController::class, 'show'])->whereUuid('activity')->name('activities.show');
    });

    Route::middleware('can:people.edit')->group(function (): void {
        Route::get('/attivita/nuova', [ActivitiesController::class, 'create'])->name('activities.create');
        Route::post('/attivita', [ActivitiesController::class, 'store'])->name('activities.store');
        Route::put('/attivita/{activity}', [ActivitiesController::class, 'update'])->whereUuid('activity')->name('activities.update');
        Route::post('/attivita/{activity}/archivia', [ActivitiesController::class, 'archive'])->whereUuid('activity')->name('activities.archive');
        Route::post('/attivita/{activity}/iscrizioni', [ActivitiesController::class, 'enroll'])->whereUuid('activity')->name('activities.enroll');
        Route::post('/attivita/{activity}/iscrizioni/{person}/chiudi', [ActivitiesController::class, 'unenroll'])->whereUuid(['activity', 'person'])->name('activities.unenroll');
    });

    // Incassi e spese scrivono in prima nota: servono anche i permessi della contabilità.
    Route::middleware(['can:people.edit', 'can:accounting.edit'])
        ->post('/attivita/{activity}/incassi', [ActivitiesController::class, 'collect'])->whereUuid('activity')->name('activities.collect');
    Route::middleware('can:accounting.edit')
        ->post('/attivita/{activity}/spese', [ActivitiesController::class, 'expense'])->whereUuid('activity')->name('activities.expense');
});
