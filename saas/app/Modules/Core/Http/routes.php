<?php

declare(strict_types=1);

use App\Modules\Core\Http\Controllers\ArchiveController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\ExportController;
use App\Modules\Core\Http\Controllers\OperatorsController;
use App\Modules\Core\Http\Controllers\OrgSwitchController;
use App\Modules\Core\Http\Controllers\ProfileController;
use App\Modules\Core\Http\Controllers\SetupController;
use App\Modules\Core\Http\Controllers\SuspendedController;
use App\Modules\Core\Http\Controllers\TwoFactorEmailResetController;
use App\Modules\Core\Http\Controllers\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

/*
 * Rotte del gestionale (app.associazionesemplice.it). Accesso, password e verifica in due passaggi sono le rotte
 * di Fortify (config/fortify.php).
 *
 * Catena del gestionale: auth → two-factor (obbligatoria) → operator (associazione e OrgContext).
 */

Route::middleware('web')->group(function (): void {
    Route::redirect('/', '/gestionale');

    // Chi ha perso telefono e codici di recupero: link per email che toglie la verifica in due passaggi.
    Route::post('/verifica-accesso/recupero-email', [TwoFactorEmailResetController::class, 'request'])->middleware('throttle:5,60')->name('two-factor.email-reset.request');
    Route::get('/verifica-accesso/recupero-email/conferma', [TwoFactorEmailResetController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.email-reset.confirm');
    Route::post('/verifica-accesso/recupero-email/conferma', [TwoFactorEmailResetController::class, 'apply'])->middleware('throttle:10,1')->name('two-factor.email-reset.apply');

    Route::middleware(['auth', 'password.confirm'])->group(function (): void {
        Route::get('/account/verifica-in-due-passaggi', [TwoFactorSetupController::class, 'show'])->name('account.two-factor');
    });

    Route::middleware(['auth', 'two-factor'])->group(function (): void {
        Route::get('/associazioni', [OrgSwitchController::class, 'index'])->name('orgs.choose');
        Route::get('/associazione-sospesa', SuspendedController::class)->name('org.suspended');

        Route::post('/associazioni/{org}', [OrgSwitchController::class, 'store'])->whereUuid('org')->name('orgs.switch');

        Route::middleware('operator')->prefix('gestionale')->group(function (): void {
            Route::get('/', DashboardController::class)->name('dashboard');

            // Profilo di chi lavora: nome, email, password, due passaggi, dispositivi.
            Route::get('/profilo', [ProfileController::class, 'show'])->name('profile');
            Route::put('/profilo/nome', [ProfileController::class, 'name'])->name('profile.name');
            Route::put('/profilo/email', [ProfileController::class, 'email'])->middleware('throttle:10,1')->name('profile.email');
            Route::get('/profilo/email/conferma', [ProfileController::class, 'confirmEmail'])->middleware('throttle:10,1')->name('profile.email.confirm');
            Route::post('/profilo/dispositivi', [ProfileController::class, 'closeOthers'])->middleware('throttle:10,1')->name('profile.devices');

            // Configurazione guidata: titolare e amministratore.
            Route::middleware('can:org.settings')->group(function (): void {
                Route::get('/configurazione', [SetupController::class, 'show'])->name('setup');
                Route::post('/configurazione', [SetupController::class, 'save'])->name('setup.save');
                Route::get('/configurazione/anteprima-tessere', [SetupController::class, 'cardsPreview'])->name('setup.cards');
                Route::post('/configurazione/rimanda', [SetupController::class, 'skip'])->name('setup.skip');
                Route::get('/esportazione', [ExportController::class, 'show'])->middleware('exports.allowed')->name('export');
                Route::post('/esportazione.zip', [ExportController::class, 'download'])->middleware(['exports.allowed', 'password.confirm', 'throttle:3,1'])->name('export.download');
                Route::post('/archivio/{year}', [ArchiveController::class, 'archive'])->whereNumber('year')->middleware(['exports.allowed', 'throttle:6,1'])->name('archive.create');
                Route::post('/archivio/{year}/rimetti', [ArchiveController::class, 'restore'])->whereNumber('year')->middleware(['exports.allowed', 'throttle:6,1'])->name('archive.restore');
                Route::post('/archivio/{year}/ricostruisci', [ArchiveController::class, 'rebuild'])->whereNumber('year')->middleware(['exports.allowed', 'throttle:6,1'])->name('archive.rebuild');
            });

            // Operatori e ruoli personalizzati: titolare e amministratore.
            Route::middleware('can:org.operators')->group(function (): void {
                Route::get('/operatori', [OperatorsController::class, 'index'])->name('operators');
                // Chi cambia chi può fare cosa riconferma la password: un accesso lasciato aperto non basta.
                Route::middleware('password.confirm')->group(function (): void {
                    Route::post('/operatori', [OperatorsController::class, 'invite'])->middleware('throttle:20,1')->name('operators.invite');
                    Route::put('/operatori/{user}', [OperatorsController::class, 'update'])->whereUuid('user')->name('operators.update');
                    Route::put('/operatori/{user}/permessi', [OperatorsController::class, 'permissions'])->whereUuid('user')->name('operators.permissions');
                    Route::delete('/operatori/{user}', [OperatorsController::class, 'remove'])->whereUuid('user')->name('operators.remove');
                    Route::post('/ruoli', [OperatorsController::class, 'saveRole'])->name('roles.store');
                    Route::put('/ruoli/{role}', [OperatorsController::class, 'saveRole'])->whereUuid('role')->name('roles.update');
                    Route::delete('/ruoli/{role}', [OperatorsController::class, 'deleteRole'])->whereUuid('role')->name('roles.delete');
                });
            });
        });
    });
});
