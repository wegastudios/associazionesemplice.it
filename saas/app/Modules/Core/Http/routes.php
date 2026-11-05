<?php

declare(strict_types=1);

use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\ExportController;
use App\Modules\Core\Http\Controllers\OperatorsController;
use App\Modules\Core\Http\Controllers\OrgSwitchController;
use App\Modules\Core\Http\Controllers\PlatformController;
use App\Modules\Core\Http\Controllers\SetupController;
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

    Route::middleware(['auth', 'password.confirm'])->group(function (): void {
        Route::get('/account/verifica-in-due-passaggi', [TwoFactorSetupController::class, 'show'])->name('account.two-factor');
    });

    Route::middleware(['auth', 'two-factor'])->group(function (): void {
        Route::get('/associazioni', [OrgSwitchController::class, 'index'])->name('orgs.choose');

        // Amministrazione della piattaforma: posta, backup, servizio chiavi, cache.
        Route::middleware(['platform-admin', 'password.confirm'])->prefix('admin/piattaforma')->group(function (): void {
            Route::get('/', [PlatformController::class, 'show'])->name('platform');
            Route::post('/{section}', [PlatformController::class, 'save'])->whereIn('section', ['mail', 'backup', 'keys', 'cache'])->name('platform.save');
            Route::post('/{section}/prova', [PlatformController::class, 'test'])->whereIn('section', ['mail', 'backup', 'keys', 'cache'])->middleware('throttle:10,1')->name('platform.test');
        });
        Route::post('/associazioni/{org}', [OrgSwitchController::class, 'store'])->whereUuid('org')->name('orgs.switch');

        Route::middleware('operator')->prefix('gestionale')->group(function (): void {
            Route::get('/', DashboardController::class)->name('dashboard');

            // Configurazione guidata: titolare e amministratore.
            Route::middleware('can:org.settings')->group(function (): void {
                Route::get('/configurazione', [SetupController::class, 'show'])->name('setup');
                Route::post('/configurazione', [SetupController::class, 'save'])->name('setup.save');
                Route::post('/configurazione/rimanda', [SetupController::class, 'skip'])->name('setup.skip');
                Route::get('/esportazione', [ExportController::class, 'show'])->name('export');
                Route::get('/esportazione.zip', [ExportController::class, 'download'])->middleware('throttle:3,1')->name('export.download');
            });

            // Operatori e ruoli personalizzati: titolare e amministratore.
            Route::middleware('can:org.operators')->group(function (): void {
                Route::get('/operatori', [OperatorsController::class, 'index'])->name('operators');
                Route::post('/operatori', [OperatorsController::class, 'invite'])->middleware('throttle:20,1')->name('operators.invite');
                Route::put('/operatori/{user}', [OperatorsController::class, 'update'])->whereUuid('user')->name('operators.update');
                Route::delete('/operatori/{user}', [OperatorsController::class, 'remove'])->whereUuid('user')->name('operators.remove');
                Route::post('/ruoli', [OperatorsController::class, 'saveRole'])->name('roles.store');
                Route::put('/ruoli/{role}', [OperatorsController::class, 'saveRole'])->whereUuid('role')->name('roles.update');
                Route::delete('/ruoli/{role}', [OperatorsController::class, 'deleteRole'])->whereUuid('role')->name('roles.delete');
            });
        });
    });
});
