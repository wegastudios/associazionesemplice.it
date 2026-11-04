<?php

declare(strict_types=1);

use App\Modules\Soci\Http\Controllers\PeopleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'two-factor', 'operator'])->prefix('gestionale')->group(function (): void {
    Route::middleware('can:people.view')->group(function (): void {
        Route::get('/soci', [PeopleController::class, 'index'])->name('people');
        Route::get('/soci/cerca', [PeopleController::class, 'lookup'])->name('people.lookup');
        Route::get('/soci/{person}', [PeopleController::class, 'show'])->whereUuid('person')->name('people.show');
    });

    Route::middleware('can:people.edit')->group(function (): void {
        Route::get('/soci/nuovo', [PeopleController::class, 'create'])->name('people.create');
        Route::post('/soci', [PeopleController::class, 'store'])->name('people.store');
        Route::put('/soci/{person}', [PeopleController::class, 'update'])->whereUuid('person')->name('people.update');
        Route::post('/soci/{person}/uscita', [PeopleController::class, 'leave'])->whereUuid('person')->name('people.leave');
        Route::post('/soci/{person}/tessera-manuale', [PeopleController::class, 'grant'])->whereUuid('person')->name('people.grant');
        Route::post('/tessere/{membership}/togli', [PeopleController::class, 'revoke'])->whereUuid('membership')->name('memberships.revoke');
    });

    // Il rinnovo con incasso scrive in prima nota: servono anche i permessi della contabilità.
    Route::middleware(['can:people.edit', 'can:accounting.edit'])
        ->post('/soci/{person}/tessera', [PeopleController::class, 'renew'])->whereUuid('person')->name('people.renew');

    Route::middleware('can:org.settings')->post('/soci/impostazioni', [PeopleController::class, 'settings'])->name('people.settings');
});
