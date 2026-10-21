<?php

use App\Http\Controllers\Admin\Hub\SettingsController;
use App\Http\Controllers\Admin\Hub\SiteController;
use App\Http\Controllers\Admin\Hub\TicketController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/admin-auth.php';

Route::redirect('/', 'https://wegastudios.com', 302);

Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
    Route::redirect('/', '/admin/casella')->name('home');

    Route::get('/casella', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/casella/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');

    Route::get('/portali', [SiteController::class, 'index'])->name('sites.index');
    Route::get('/portali/nuovo', [SiteController::class, 'create'])->name('sites.create');
    Route::post('/portali', [SiteController::class, 'store'])->name('sites.store');
    Route::get('/portali/{site}', [SiteController::class, 'show'])->name('sites.show');
    Route::post('/portali/{site}/regole', [SiteController::class, 'update'])->name('sites.update');
    Route::post('/portali/{site}/ruota', [SiteController::class, 'rotate'])->name('sites.rotate');
    Route::post('/portali/{site}/revoca', [SiteController::class, 'revoke'])->name('sites.revoke');

    Route::get('/impostazioni', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::post('/impostazioni', [SettingsController::class, 'update'])->name('settings.update');
});
