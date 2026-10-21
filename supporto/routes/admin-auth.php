<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\MailSettingsController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\ResetPasswordController;
use App\Http\Controllers\BrandAssetController;
use Illuminate\Support\Facades\Route;

// Parti comuni ai due ruoli: accesso, password, posta in uscita, marchio

// Link inviato per email: il nome password.reset e quello atteso dalla notifica di Laravel
Route::get('/admin/nuova-password/{token}', [ResetPasswordController::class, 'form'])->name('password.reset');

Route::get('/brand/{file}', BrandAssetController::class)->name('brand');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::get('/password-dimenticata', [ResetPasswordController::class, 'request'])->name('password.forgot');
    Route::post('/password-dimenticata', [ResetPasswordController::class, 'send'])->middleware('throttle:5,1')->name('password.email');
    Route::post('/nuova-password', [ResetPasswordController::class, 'reset'])->middleware('throttle:5,1')->name('password.reset.store');

    Route::middleware('admin')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::post('/password', [PasswordController::class, 'update'])->name('password.update');
        Route::get('/email', [MailSettingsController::class, 'edit'])->name('mail.edit');
        Route::post('/email', [MailSettingsController::class, 'update'])->name('mail.update');
        Route::post('/email/prova', [MailSettingsController::class, 'test'])->middleware('throttle:5,1')->name('mail.test');
    });
});
