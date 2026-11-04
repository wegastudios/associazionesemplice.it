<?php

declare(strict_types=1);

use App\Modules\Soci\Http\Controllers\PeopleController;
use App\Modules\Soci\Http\Controllers\SociExtrasController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'two-factor', 'operator'])->prefix('gestionale')->group(function (): void {
    Route::middleware('can:people.view')->group(function (): void {
        Route::get('/soci', [PeopleController::class, 'index'])->name('people');
        Route::get('/soci/cerca', [PeopleController::class, 'lookup'])->name('people.lookup');
        Route::get('/soci/livelli', [SociExtrasController::class, 'levels'])->name('levels');
        Route::get('/soci/{person}', [PeopleController::class, 'show'])->whereUuid('person')->name('people.show');
    });

    Route::middleware('can:people.edit')->group(function (): void {
        Route::get('/soci/nuovo', [PeopleController::class, 'create'])->name('people.create');
        Route::post('/soci', [PeopleController::class, 'store'])->name('people.store');
        Route::put('/soci/{person}', [PeopleController::class, 'update'])->whereUuid('person')->name('people.update');
        Route::post('/soci/{person}/uscita', [PeopleController::class, 'leave'])->whereUuid('person')->name('people.leave');
        Route::post('/soci/{person}/tessera-manuale', [PeopleController::class, 'grant'])->whereUuid('person')->name('people.grant');
        Route::post('/tessere/{membership}/togli', [PeopleController::class, 'revoke'])->whereUuid('membership')->name('memberships.revoke');
        Route::post('/soci/{person}/tutori', [SociExtrasController::class, 'linkGuardian'])->whereUuid('person')->name('guardians.link');
        Route::post('/soci/{person}/tutori/{guardian}/operativo', [SociExtrasController::class, 'operativeGuardian'])->whereUuid(['person', 'guardian'])->name('guardians.operative');
        Route::delete('/soci/{person}/tutori/{guardian}', [SociExtrasController::class, 'unlinkGuardian'])->whereUuid(['person', 'guardian'])->name('guardians.unlink');
    });

    // Dati sanitari: non per il commercialista.
    Route::middleware('can:health.view')->group(function (): void {
        Route::get('/certificati', [SociExtrasController::class, 'certificates'])->name('certificates');
        Route::get('/certificati/{certificate}/file', [SociExtrasController::class, 'downloadCertificate'])->whereUuid('certificate')->name('certificates.file');
        Route::middleware('can:people.edit')->group(function (): void {
            Route::post('/soci/{person}/certificati', [SociExtrasController::class, 'addCertificate'])->whereUuid('person')->name('certificates.add');
            Route::delete('/certificati/{certificate}', [SociExtrasController::class, 'deleteCertificate'])->whereUuid('certificate')->name('certificates.delete');
        });
    });

    // Il rinnovo con incasso scrive in prima nota: servono anche i permessi della contabilità.
    Route::middleware(['can:people.edit', 'can:accounting.edit'])
        ->post('/soci/{person}/tessera', [PeopleController::class, 'renew'])->whereUuid('person')->name('people.renew');

    Route::middleware('can:org.settings')->group(function (): void {
        Route::post('/soci/impostazioni', [PeopleController::class, 'settings'])->name('people.settings');
        Route::post('/soci/livelli', [SociExtrasController::class, 'storeLevel'])->name('levels.store');
        Route::put('/soci/livelli/{level}', [SociExtrasController::class, 'updateLevel'])->whereUuid('level')->name('levels.update');
    });
});
