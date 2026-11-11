<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Http\Controllers\IdentityApiController;
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

// Identità unica: le richieste del portale (docs/IDENTITA_API.md), tutte firmate, tutte POST.
Route::prefix('saas/api/staff/v1/identity')->middleware('throttle:portal-api')->group(function (): void {
    Route::post('{action}', [IdentityApiController::class, 'handle'])
        ->where('action', 'login|code|session|profile|password|email|email-confirm|2fa-start|2fa-confirm|2fa-disable|sessions|session-revoke|logout|reset-request|reset-complete|token');
});
