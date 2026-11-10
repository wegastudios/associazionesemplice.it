<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Http\Controllers\AdminConsoleController;
use App\Modules\Piattaforma\Http\Controllers\AdminLoginController;
use App\Modules\Piattaforma\Http\Middleware\UsePlatformSession;
use Illuminate\Support\Facades\Route;

/*
 * Amministrazione della piattaforma (/saas/admin; in sviluppo /admin). Sessione, cookie e guardia propri
 * (UsePlatformSession); lo staff è separato dagli operatori. L'accesso richiede email, password e codice a due passaggi.
 */

Route::middleware('web')->prefix(UsePlatformSession::path())->group(function (): void {
    Route::get('accesso', [AdminLoginController::class, 'show'])->name('platform.login');
    Route::post('accesso', [AdminLoginController::class, 'login'])->middleware('throttle:platform-login');
    Route::get('verifica', [AdminLoginController::class, 'showVerify'])->name('platform.verify');
    Route::post('verifica', [AdminLoginController::class, 'verify'])->middleware('throttle:platform-code');
    Route::get('attivazione', [AdminLoginController::class, 'showEnroll'])->name('platform.enroll');
    Route::post('attivazione', [AdminLoginController::class, 'enroll'])->middleware('throttle:platform-code');

    Route::middleware('platform.staff')->group(function (): void {
        Route::get('/', [AdminConsoleController::class, 'home'])->name('platform.home');
        Route::get('associazioni', [AdminConsoleController::class, 'orgs'])->name('platform.orgs');
        Route::get('registro', [AdminConsoleController::class, 'audit'])->name('platform.audit');
        Route::post('esci', [AdminLoginController::class, 'logout'])->name('platform.logout');
    });
});
