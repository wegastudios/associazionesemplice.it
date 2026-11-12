<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Http\Controllers\AdminCatalogController;
use App\Modules\Piattaforma\Http\Controllers\AdminCatalogEditorController;
use App\Modules\Piattaforma\Http\Controllers\AdminConsoleController;
use App\Modules\Piattaforma\Http\Controllers\AdminLoginController;
use App\Modules\Piattaforma\Http\Controllers\AdminSubscriptionsController;
use App\Modules\Piattaforma\Http\Controllers\StaffSsoController;
use App\Modules\Piattaforma\Http\Middleware\UsePlatformSession;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
 * Amministrazione della piattaforma (sempre /saas/admin; app./admin è dei clienti). Sessione, cookie e guardia propri
 * (UsePlatformSession); lo staff è separato dagli operatori. L'accesso richiede email, password e codice a due passaggi.
 */

Route::middleware('web')->prefix(UsePlatformSession::path())->group(function (): void {
    // Ingresso dal portale (accesso unico dello staff): spento finché SAAS_STAFF_SSO non è acceso; senza CSRF perché è
    // una POST dal portale, protetta dalla firma del token.
    Route::post('sso', [StaffSsoController::class, 'receive'])->middleware('throttle:platform-login')
        ->withoutMiddleware([ValidateCsrfToken::class, PreventRequestForgery::class])->name('platform.sso');
    Route::get('accesso', [AdminLoginController::class, 'show'])->name('platform.login');
    Route::post('accesso', [AdminLoginController::class, 'login'])->middleware('throttle:platform-login');
    Route::get('verifica', [AdminLoginController::class, 'showVerify'])->name('platform.verify');
    Route::post('verifica', [AdminLoginController::class, 'verify'])->middleware('throttle:platform-code');
    Route::get('attivazione', [AdminLoginController::class, 'showEnroll'])->name('platform.enroll');
    Route::post('attivazione', [AdminLoginController::class, 'enroll'])->middleware('throttle:platform-code');

    Route::middleware('platform.staff')->group(function (): void {
        Route::get('/', [AdminConsoleController::class, 'home'])->name('platform.home');
        Route::get('catalogo', [AdminCatalogController::class, 'show'])->name('platform.catalog');
        Route::get('catalogo/bozza', [AdminCatalogController::class, 'draft'])->name('platform.catalog.draft');
        Route::middleware('platform.staff:write')->group(function (): void {
            Route::post('catalogo/bozza', [AdminCatalogController::class, 'save'])->name('platform.catalog.save');
            Route::post('catalogo/scarta', [AdminCatalogController::class, 'discard'])->name('platform.catalog.discard');
            Route::post('catalogo/pubblica', [AdminCatalogController::class, 'publish'])->middleware('throttle:10,1')->name('platform.catalog.publish');
        });
        // Editor a moduli (piani, pacchetti, promozioni): stessa bozza del JSON.
        Route::get('catalogo/piani', [AdminCatalogEditorController::class, 'plans'])->name('platform.plans');
        Route::get('catalogo/pacchetti', [AdminCatalogEditorController::class, 'addons'])->name('platform.addons');
        Route::get('catalogo/promozioni', [AdminCatalogEditorController::class, 'promos'])->name('platform.promos');
        Route::middleware('platform.staff:write')->group(function (): void {
            Route::get('catalogo/piani/nuovo', [AdminCatalogEditorController::class, 'planNew'])->name('platform.plans.new');
            Route::post('catalogo/piani/nuovo', [AdminCatalogEditorController::class, 'planCreate'])->name('platform.plans.create');
            Route::get('catalogo/pacchetti/nuovo', [AdminCatalogEditorController::class, 'addonNew'])->name('platform.addons.new');
            Route::post('catalogo/pacchetti/nuovo', [AdminCatalogEditorController::class, 'addonCreate'])->name('platform.addons.create');
        });
        Route::get('catalogo/piani/{code}', [AdminCatalogEditorController::class, 'planEdit'])->where('code', '[a-z][a-z0-9_]{1,39}')->name('platform.plans.edit');
        Route::get('catalogo/pacchetti/{code}', [AdminCatalogEditorController::class, 'addonEdit'])->where('code', '[a-z][a-z0-9_]{1,39}')->name('platform.addons.edit');
        Route::middleware('platform.staff:write')->group(function (): void {
            Route::post('catalogo/piani/{code}', [AdminCatalogEditorController::class, 'planSave'])->where('code', '[a-z][a-z0-9_]{1,39}')->name('platform.plans.save');
            Route::post('catalogo/pacchetti/{code}', [AdminCatalogEditorController::class, 'addonSave'])->where('code', '[a-z][a-z0-9_]{1,39}')->name('platform.addons.save');
            Route::post('catalogo/promozioni', [AdminCatalogEditorController::class, 'promosSave'])->name('platform.promos.save');
        });
        Route::get('abbonamenti', [AdminSubscriptionsController::class, 'index'])->name('platform.subscriptions');
        Route::get('registro', [AdminConsoleController::class, 'audit'])->name('platform.audit');
        Route::post('esci', [AdminLoginController::class, 'logout'])->name('platform.logout');
    });
});
