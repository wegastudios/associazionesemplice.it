<?php

declare(strict_types=1);

use App\Modules\Portale\Http\Controllers\CatalogController;
use App\Modules\Portale\Http\Controllers\PortalApiController;
use App\Modules\Portale\Http\Controllers\PortalServicesController;
use App\Modules\Portale\Http\Controllers\RuntimeController;
use App\Modules\Portale\Http\Controllers\SubscriptionSsoController;
use App\Modules\Portale\Http\Middleware\ThrottlePortalApi;
use App\Modules\Portale\Http\Middleware\ThrottleRuntimeApi;
use Illuminate\Support\Facades\Route;

// API per il portale: niente sessione, niente CSRF, solo token firmati.
Route::prefix('api/portale/v1')->middleware(ThrottlePortalApi::class)->group(function (): void {
    Route::post('/orgs', [PortalApiController::class, 'provision']);
    // Diritti v1: non più accettati, rispondono 410 (§8). Resta solo `subscription` v2.
    Route::put('/orgs/{org}/entitlements', [PortalApiController::class, 'entitlements'])->whereUuid('org');
});

// Abbonamento v2: il SaaS ricava i limiti dal piano (CONTRATTO_PORTALE.md §3.2).
Route::put('api/portale/v2/orgs/{org}/subscription', [PortalApiController::class, 'subscription'])
    ->whereUuid('org')->middleware(ThrottlePortalApi::class);

// Promozioni, cambio di piano, dominio e stato (§3.3-§3.6).
Route::prefix('api/portale/v2')->middleware(ThrottlePortalApi::class)->group(function (): void {
    Route::post('promo/check', [PortalServicesController::class, 'promoCheck']);
    Route::post('promo/redeem', [PortalServicesController::class, 'promoRedeem']);
    Route::post('orgs/{org}/plan-change/check', [PortalServicesController::class, 'planChangeCheck'])->whereUuid('org');
    Route::post('orgs/{org}/domain/check', [PortalServicesController::class, 'domainCheck'])->whereUuid('org');
    Route::post('orgs/{org}/domain/set', [PortalServicesController::class, 'domainSet'])->whereUuid('org');
    Route::post('orgs/{org}/status', [PortalServicesController::class, 'status'])->whereUuid('org');
});

// Come gira il PHP web (SAPI, APCu, OPcache): token firmato dal SaaS stesso, ambito e tipo propri, nessun dato.
Route::post('api/portale/v2/runtime', RuntimeController::class)->middleware(ThrottleRuntimeApi::class);

// Catalogo pubblico e firmato: piani, prezzi e promozioni che il portale legge (§2).
Route::get('api/catalogo/v1', CatalogController::class)->middleware('throttle:portal-api');

Route::middleware(['web', 'auth', 'two-factor', 'operator', 'can:org.subscription'])
    ->get('/gestionale/abbonamento', SubscriptionSsoController::class)
    ->name('subscription');
