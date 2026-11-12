<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Http\Controllers\OperatorSsoController;
use Illuminate\Support\Facades\Route;

/* Ingresso degli operatori dal login unico (passo 6): il biglietto firmato nell'indirizzo è il segreto; blocco del troppo-veloce per indirizzo. */
Route::middleware(['web', 'throttle:20,1'])->get('accesso-unico', OperatorSsoController::class)->name('operator.sso');
