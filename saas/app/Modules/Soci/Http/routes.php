<?php

declare(strict_types=1);

use App\Modules\Soci\Http\Controllers\ApplicationsController;
use App\Modules\Soci\Http\Controllers\MemberBookController;
use App\Modules\Soci\Http\Controllers\PeopleController;
use App\Modules\Soci\Http\Controllers\PeopleTransferController;
use App\Modules\Soci\Http\Controllers\PublicApplicationController;
use App\Modules\Soci\Http\Controllers\SociExtrasController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'two-factor', 'operator'])->prefix('gestionale')->group(function (): void {
    Route::middleware('can:people.view')->group(function (): void {
        Route::get('/soci', [PeopleController::class, 'index'])->name('people');
        Route::get('/soci/cerca', [PeopleController::class, 'lookup'])->name('people.lookup');
        Route::get('/soci/livelli', [SociExtrasController::class, 'levels'])->name('levels');
        Route::get('/soci/libro', [MemberBookController::class, 'index'])->name('member-book');
        Route::get('/soci/{person}', [PeopleController::class, 'show'])->whereUuid('person')->name('people.show');
        Route::get('/domande', [ApplicationsController::class, 'index'])->name('applications');
        Route::get('/domande/{application}', [ApplicationsController::class, 'show'])->whereUuid('application')->name('applications.show');
    });

    Route::middleware('can:people.edit')->group(function (): void {
        Route::get('/soci/nuovo', [PeopleController::class, 'create'])->name('people.create');
        Route::get('/soci/importa', [PeopleTransferController::class, 'form'])->name('people.import');
        Route::post('/soci/importa', [PeopleTransferController::class, 'import'])->name('people.import.run');
        Route::get('/soci/modello-import.csv', [PeopleTransferController::class, 'template'])->name('people.import.template');
        Route::post('/soci', [PeopleController::class, 'store'])->name('people.store');
        Route::put('/soci/{person}', [PeopleController::class, 'update'])->whereUuid('person')->name('people.update');
        Route::post('/soci/{person}/uscita', [PeopleController::class, 'leave'])->whereUuid('person')->name('people.leave');
        Route::post('/soci/{person}/tessera-manuale', [PeopleController::class, 'grant'])->whereUuid('person')->name('people.grant');
        Route::post('/tessere/{membership}/togli', [PeopleController::class, 'revoke'])->whereUuid('membership')->name('memberships.revoke');
        Route::post('/soci/{person}/consensi', [PeopleController::class, 'consent'])->whereUuid('person')->name('people.consent');
        Route::post('/domande/{application}/accogli', [ApplicationsController::class, 'approve'])->whereUuid('application')->name('applications.approve');
        Route::post('/domande/{application}/respingi', [ApplicationsController::class, 'reject'])->whereUuid('application')->name('applications.reject');
        Route::post('/soci/{person}/tutori', [SociExtrasController::class, 'linkGuardian'])->whereUuid('person')->name('guardians.link');
        Route::post('/soci/{person}/tutori/{guardian}/operativo', [SociExtrasController::class, 'operativeGuardian'])->whereUuid(['person', 'guardian'])->name('guardians.operative');
        Route::delete('/soci/{person}/tutori/{guardian}', [SociExtrasController::class, 'unlinkGuardian'])->whereUuid(['person', 'guardian'])->name('guardians.unlink');
    });

    // Export con i dati sensibili: non per il commercialista; ogni export finisce nel registro.
    Route::middleware(['can:people.export', 'exports.allowed', 'throttle:10,1'])->group(function (): void {
        Route::get('/soci/esporta', [PeopleTransferController::class, 'export'])->name('people.export');
        Route::get('/soci/libro.pdf', [MemberBookController::class, 'pdf'])->name('member-book.pdf');
        Route::get('/soci/libro.csv', [MemberBookController::class, 'csv'])->name('member-book.csv');
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
    Route::middleware(['can:people.edit', 'can:accounting.edit', 'can:accounting.income'])
        ->post('/soci/{person}/tessera', [PeopleController::class, 'renew'])->whereUuid('person')->name('people.renew');

    Route::middleware('can:org.settings')->group(function (): void {
        Route::post('/soci/impostazioni', [PeopleController::class, 'settings'])->name('people.settings');
        Route::post('/domande/impostazioni', [ApplicationsController::class, 'settings'])->name('applications.settings');
        Route::post('/soci/livelli', [SociExtrasController::class, 'storeLevel'])->name('levels.store');
        Route::put('/soci/livelli/{level}', [SociExtrasController::class, 'updateLevel'])->whereUuid('level')->name('levels.update');
    });
});

// Domanda di adesione pubblica, senza accesso: pochi invii per indirizzo e per associazione.
Route::middleware('web')->group(function (): void {
    Route::get('/adesione/{slug}', [PublicApplicationController::class, 'show'])->name('application.form');
    Route::post('/adesione/{slug}', [PublicApplicationController::class, 'store'])->middleware('throttle:applications')->name('application.submit');
});
