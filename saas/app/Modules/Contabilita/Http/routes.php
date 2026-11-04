<?php

declare(strict_types=1);

use App\Modules\Contabilita\Http\Controllers\AccountsController;
use App\Modules\Contabilita\Http\Controllers\LedgerController;
use App\Modules\Contabilita\Http\Controllers\YearsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'two-factor', 'operator'])->prefix('gestionale')->group(function (): void {
    Route::middleware('can:accounting.view')->group(function (): void {
        Route::get('/prima-nota', [LedgerController::class, 'index'])->name('ledger');
        Route::get('/conti', [AccountsController::class, 'index'])->name('accounts');
        Route::get('/rendiconto/{year?}', [YearsController::class, 'statement'])->whereNumber('year')->name('statement');
    });

    Route::middleware('can:accounting.edit')->group(function (): void {
        Route::post('/prima-nota/incassi', [LedgerController::class, 'income'])->name('ledger.income');
        Route::post('/prima-nota/spese', [LedgerController::class, 'expense'])->name('ledger.expense');
        Route::post('/prima-nota/giroconti', [LedgerController::class, 'transfer'])->name('ledger.transfer');
        Route::post('/prima-nota/{transaction}/storno', [LedgerController::class, 'void'])->whereUuid('transaction')->name('ledger.void');
        Route::post('/conti', [AccountsController::class, 'store'])->name('accounts.store');
        Route::post('/conti/{account}/chiudi', [AccountsController::class, 'close'])->whereUuid('account')->name('accounts.close');
        Route::post('/conti/{account}/riapri', [AccountsController::class, 'reopen'])->whereUuid('account')->name('accounts.reopen');
    });

    Route::middleware('can:accounting.close')->group(function (): void {
        Route::post('/esercizi/{year}/chiudi', [YearsController::class, 'close'])->whereNumber('year')->name('years.close');
        Route::post('/esercizi/{year}/riapri', [YearsController::class, 'reopen'])->whereNumber('year')->name('years.reopen');
    });
});
