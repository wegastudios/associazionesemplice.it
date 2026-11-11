<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Http\Controllers\StaffInviteController;
use Illuminate\Support\Facades\Route;

/*
 * Invito dello staff (docs/UTENTI_E_ACCESSI.md §4): senza sessione né CSRF, perché il codice nell'indirizzo è il segreto.
 * Il blocco del troppo-veloce è per indirizzo.
 */
Route::prefix('saas/staff/invito')->middleware('throttle:20,1')->group(function (): void {
    Route::get('{code}', [StaffInviteController::class, 'show'])->where('code', '[0-9a-f]{48}');
    Route::post('{code}', [StaffInviteController::class, 'accept'])->where('code', '[0-9a-f]{48}');
});
