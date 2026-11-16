<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\DemoScale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nella demo gli accessi sono pubblici: chi li usa non deve poter cambiare ciò che resta uguale per tutti gli altri
 * (password e due passaggi dei titolari, email, operatori e ruoli, chiavi dei pagamenti, dati dell'ente).
 * Si guardano solo le richieste che scrivono; la lettura delle stesse pagine resta libera. Fuori dalla demo non fa nulla.
 */
final class DemoRestrictions
{
    /** Percorsi (con * ) dove nella demo non si scrive. */
    public const BLOCKED = [
        'user/password',
        'user/two-factor-authentication',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-recovery-codes',
        'user/profile-information',
        'gestionale/profilo/email',
        'gestionale/profilo/dispositivi',
        'gestionale/operatori',
        'gestionale/operatori/*',
        'gestionale/ruoli',
        'gestionale/ruoli/*',
        'gestionale/pagamenti-online',
        'gestionale/pagamenti-online/*',
        'gestionale/configurazione',
    ];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (DemoScale::restricted() && ! $request->isMethodSafe() && $request->is(self::BLOCKED)) {
            throw ValidationException::withMessages(['ledger' => DemoScale::CHANGE_OFF]);
        }

        return $next($request);
    }
}
