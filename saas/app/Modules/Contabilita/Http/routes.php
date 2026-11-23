<?php

declare(strict_types=1);

use App\Modules\Contabilita\Http\Controllers\AccountsController;
use App\Modules\Contabilita\Http\Controllers\FiscalSettingsController;
use App\Modules\Contabilita\Http\Controllers\LedgerController;
use App\Modules\Contabilita\Http\Controllers\LedgerTransferController;
use App\Modules\Contabilita\Http\Controllers\ReceiptCheckController;
use App\Modules\Contabilita\Http\Controllers\ReceiptsController;
use App\Modules\Contabilita\Http\Controllers\YearsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'two-factor', 'operator'])->prefix('gestionale')->group(function (): void {
    Route::middleware('can:accounting.view')->group(function (): void {
        Route::get('/prima-nota', [LedgerController::class, 'index'])->name('ledger');
        Route::get('/prima-nota/esporta', [LedgerTransferController::class, 'export'])->middleware(['exports.allowed', 'throttle:10,1', 'db.long'])->name('ledger.export');
        Route::get('/conti', [AccountsController::class, 'index'])->middleware('can:accounting.funds')->name('accounts');
        Route::get('/ricevute/{receipt}.pdf', [ReceiptsController::class, 'pdf'])->whereUuid('receipt')->name('receipts.pdf');
        Route::get('/rendiconto/{year?}', [YearsController::class, 'statement'])->whereNumber('year')->middleware('db.long')->name('statement');
    });

    Route::middleware('can:accounting.edit')->group(function (): void {
        Route::get('/prima-nota/importa', [LedgerTransferController::class, 'form'])->name('ledger.import');
        Route::post('/prima-nota/importa', [LedgerTransferController::class, 'import'])->middleware('db.long')->name('ledger.import.run');
        Route::post('/prima-nota/importa/{import}/annulla', [LedgerTransferController::class, 'cancel'])->whereUuid('import')->name('ledger.import.cancel');
        Route::post('/prima-nota/importa/{import}/riprendi', [LedgerTransferController::class, 'resume'])->whereUuid('import')->name('ledger.import.resume');
        Route::post('/prima-nota/incassi', [LedgerController::class, 'income'])->middleware('can:accounting.income')->name('ledger.income');
        Route::post('/prima-nota/spese', [LedgerController::class, 'expense'])->middleware('can:accounting.expense')->name('ledger.expense');
        Route::post('/prima-nota/giroconti', [LedgerController::class, 'transfer'])->name('ledger.transfer');
        Route::post('/ricevute/{receipt}/invia', [ReceiptsController::class, 'send'])->whereUuid('receipt')->middleware('throttle:20,1')->name('receipts.send');
        Route::post('/prima-nota/{transaction}/attivita', [LedgerController::class, 'activity'])->whereUuid('transaction')->name('ledger.activity');
        Route::post('/prima-nota/{transaction}/storno', [LedgerController::class, 'void'])->whereUuid('transaction')->name('ledger.void');
        Route::post('/conti', [AccountsController::class, 'store'])->name('accounts.store');
        Route::post('/conti/{account}/commissione', [AccountsController::class, 'fee'])->whereUuid('account')->name('accounts.fee');
        Route::post('/conti/{account}/chiudi', [AccountsController::class, 'close'])->whereUuid('account')->name('accounts.close');
        Route::post('/conti/{account}/riapri', [AccountsController::class, 'reopen'])->whereUuid('account')->name('accounts.reopen');
    });

    Route::middleware('can:org.settings')->group(function (): void {
        Route::get('/aspetto-fiscale', [FiscalSettingsController::class, 'show'])->name('fiscal.settings');
        Route::put('/aspetto-fiscale', [FiscalSettingsController::class, 'update'])->name('fiscal.settings.update');
        Route::put('/aspetto-fiscale/erogazioni-liberali', [FiscalSettingsController::class, 'donations'])->name('fiscal.settings.donations');
        Route::get('/verifica-ricevute', [ReceiptCheckController::class, 'show'])->middleware('db.long')->name('receipts.check');
        Route::post('/verifica-ricevute/fissa', [ReceiptCheckController::class, 'fix'])->middleware('db.long')->name('receipts.check.fix');
        Route::post('/verifica-ricevute/{receipt}/rifai', [ReceiptCheckController::class, 'rebuild'])->whereUuid('receipt')->middleware('db.long')->name('receipts.check.rebuild');
    });

    Route::middleware('can:accounting.close')->group(function (): void {
        Route::post('/esercizi/{year}/chiudi', [YearsController::class, 'close'])->whereNumber('year')->middleware('db.long')->name('years.close');
        Route::post('/esercizi/{year}/riapri', [YearsController::class, 'reopen'])->whereNumber('year')->name('years.reopen');
    });
});
