<?php

declare(strict_types=1);

use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\OrgSwitchController;
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
        Route::post('/associazioni/{org}', [OrgSwitchController::class, 'store'])->whereUuid('org')->name('orgs.switch');

        Route::middleware('operator')->prefix('gestionale')->group(function (): void {
            Route::get('/', DashboardController::class)->name('dashboard');
        });
    });
});
