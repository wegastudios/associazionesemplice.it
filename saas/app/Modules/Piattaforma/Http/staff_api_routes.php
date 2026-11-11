<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Http\Controllers\StaffApiController;
use Illuminate\Support\Facades\Route;

/*
 * Il SaaS come fornitore di identità dello staff: richieste firmate delle aree (docs/UTENTI_E_ACCESSI.md). Senza
 * sessione né CSRF (solo token firmati); 404 finché SAAS_STAFF_ISSUER non è acceso.
 */
Route::prefix('saas/api/staff/v1')->middleware('throttle:portal-api')->group(function (): void {
    Route::post('login', [StaffApiController::class, 'login']);
    Route::post('token', [StaffApiController::class, 'token']);
    Route::post('session-check', [StaffApiController::class, 'check']);
});
