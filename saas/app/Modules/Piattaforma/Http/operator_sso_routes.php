<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Http\Controllers\OperatorSsoController;
use Illuminate\Support\Facades\Route;

/*
 * Ingresso degli operatori dal login unico (passo 6): il biglietto firmato è il segreto, e viaggia in POST (nel GET c'è solo
 * la pagina intermedia, che lo legge dal frammento dell'indirizzo). Blocco del troppo-veloce per indirizzo.
 */
Route::middleware(['web', 'throttle:20,1'])->group(function (): void {
    Route::get('accesso-unico', OperatorSsoController::class)->name('operator.sso');
    Route::get('accesso-unico/inoltra.js', [OperatorSsoController::class, 'script'])->name('operator.sso.script');
    Route::post('accesso-unico', [OperatorSsoController::class, 'enter'])->name('operator.sso.enter');
    Route::post('accesso-unico/conferma', [OperatorSsoController::class, 'confirm'])->name('operator.sso.confirm');
});
