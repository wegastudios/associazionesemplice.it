<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Http\Controllers\StaffLoginController;
use Illuminate\Support\Facades\Route;

/*
 * Accesso unico dello staff (docs/LOGIN_UNICO_E_DATABASE.md, passo 2): sotto /saas/staff, cioè dietro il proxy del
 * portale. Sessione e cookie propri (UsePlatformSession). Tutto risponde 404 finché SAAS_UNIFIED_LOGIN non è acceso.
 */
Route::middleware('web')->prefix('saas/staff')->group(function (): void {
    Route::get('accedi', [StaffLoginController::class, 'show']);
    Route::post('accedi', [StaffLoginController::class, 'login'])->middleware('throttle:platform-login');
    Route::get('codice', [StaffLoginController::class, 'showCode']);
    Route::post('codice', [StaffLoginController::class, 'code'])->middleware('throttle:staff-code');
    Route::get('aree', [StaffLoginController::class, 'areas']);
    Route::post('vai', [StaffLoginController::class, 'go']);
    Route::post('esci', [StaffLoginController::class, 'logout']);
    Route::get('password-dimenticata', [StaffLoginController::class, 'showForgot']);
    Route::post('password-dimenticata', [StaffLoginController::class, 'forgot'])->middleware('throttle:platform-login');
    Route::get('reimposta/{code}', [StaffLoginController::class, 'showReset'])->where('code', '[0-9a-f]{48}');
    Route::post('reimposta/{code}', [StaffLoginController::class, 'reset'])->where('code', '[0-9a-f]{48}')->middleware('throttle:10,1');
});
