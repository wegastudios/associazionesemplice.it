<?php

declare(strict_types=1);

use App\Modules\Attivita\Http\Controllers\ActivitiesController;
use App\Modules\Attivita\Http\Controllers\SessionsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'two-factor', 'operator'])->prefix('gestionale')->group(function (): void {
    Route::middleware('can:people.view')->group(function (): void {
        Route::get('/attivita', [ActivitiesController::class, 'index'])->name('activities');
        Route::get('/attivita/{activity}', [ActivitiesController::class, 'show'])->whereUuid('activity')->name('activities.show');
        Route::get('/attivita/date/{session}', [SessionsController::class, 'show'])->whereUuid('session')->name('sessions.show');
        Route::get('/attivita/{activity}/presenze', [SessionsController::class, 'attendance'])->whereUuid('activity')->name('activities.attendance');
        Route::get('/calendario', [SessionsController::class, 'calendar'])->name('activities.calendar');
    });

    Route::middleware('can:people.edit')->group(function (): void {
        Route::get('/attivita/nuova', [ActivitiesController::class, 'create'])->name('activities.create');
        Route::post('/attivita', [ActivitiesController::class, 'store'])->name('activities.store');
        Route::put('/attivita/{activity}', [ActivitiesController::class, 'update'])->whereUuid('activity')->name('activities.update');
        Route::post('/attivita/{activity}/archivia', [ActivitiesController::class, 'archive'])->whereUuid('activity')->name('activities.archive');
        Route::post('/attivita/{activity}/iscrizioni', [ActivitiesController::class, 'enroll'])->whereUuid('activity')->name('activities.enroll');
        Route::post('/attivita/{activity}/date', [SessionsController::class, 'addDates'])->whereUuid('activity')->name('sessions.add');
        Route::put('/attivita/date/{session}', [SessionsController::class, 'update'])->whereUuid('session')->name('sessions.update');
        Route::post('/attivita/date/{session}/annulla', [SessionsController::class, 'cancel'])->whereUuid('session')->name('sessions.cancel');
        Route::post('/attivita/date/{session}/prenota', [SessionsController::class, 'book'])->whereUuid('session')->name('sessions.book');
        Route::post('/attivita/date/{session}/attesa', [SessionsController::class, 'waitlistJoin'])->whereUuid('session')->name('sessions.waitlist');
        Route::post('/attivita/date/{session}/attesa/{person}/esci', [SessionsController::class, 'waitlistLeave'])->whereUuid(['session', 'person'])->name('sessions.waitlist.leave');
        Route::post('/attivita/prenotazioni/{booking}/annulla', [SessionsController::class, 'cancelBooking'])->whereUuid('booking')->name('bookings.cancel');
        Route::post('/attivita/{activity}/presenze', [SessionsController::class, 'saveAttendance'])->whereUuid('activity')->name('activities.attendance.save');
        Route::post('/attivita/{activity}/iscrizioni/{person}/chiudi', [ActivitiesController::class, 'unenroll'])->whereUuid(['activity', 'person'])->name('activities.unenroll');
    });

    // Incassi e spese scrivono in prima nota: servono anche i permessi della contabilità.
    Route::middleware(['can:people.edit', 'can:accounting.edit'])
        ->group(function (): void {
            Route::post('/attivita/{activity}/incassi', [ActivitiesController::class, 'collect'])->whereUuid('activity')->name('activities.collect');
            Route::post('/attivita/prenotazioni/{booking}/incassa', [SessionsController::class, 'collect'])->whereUuid('booking')->name('bookings.collect');
        });
    Route::middleware('can:accounting.edit')
        ->post('/attivita/{activity}/spese', [ActivitiesController::class, 'expense'])->whereUuid('activity')->name('activities.expense');
});
