<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Identity\OperatorEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** `/accesso-unico` sul gestionale: consuma il biglietto del login unico e apre la sessione dell'operatore. */
final readonly class OperatorSsoController
{
    public function __construct(private OperatorEntry $entry) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $token = $request->query('t');
        $entered = $this->entry->enabled() && is_string($token) && $token !== '' && strlen($token) < 4000 ? $this->entry->consume($token) : null;
        if ($entered === null) {
            return redirect()->away($this->entry->accountUrl());
        }

        // Sessione nuova: niente che resti da un accesso precedente.
        $request->session()->invalidate();
        Auth::guard('web')->loginUsingId($entered['user']);
        $request->session()->regenerate();
        // Il secondo livello l'ha verificato l'accesso unico: il gestionale lo riconosce senza chiederlo di nuovo.
        $request->session()->put('operator.sso_mfa', $entered['mfa']);

        return redirect('/gestionale');
    }
}
