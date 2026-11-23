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
 * Elenco delle azioni PERMESSE: ogni richiesta che scrive passa solo se il percorso è qui sotto; tutto il resto,
 * anche una rotta nuova di domani, risponde con la stessa frase. La lettura delle pagine resta libera.
 * Fuori dalla demo non fa nulla.
 */
final class DemoRestrictions
{
    /** Percorsi (con * ) dove nella demo si può scrivere. */
    public const ALLOWED = [
        // Accesso e uscita (la password si conferma, non si cambia).
        'accesso', 'accesso/*', 'esci', 'user/confirm-password', 'associazioni/*',
        // Area soci: le azioni del socio dimostrativo e il modulo pubblico di adesione.
        'area/*', 'adesione/*',
        // Gestionale: il lavoro di ogni giorno.
        'gestionale/soci', 'gestionale/soci/*',
        'gestionale/tessere/*', 'gestionale/certificati/*',
        'gestionale/attivita', 'gestionale/attivita/*',
        'gestionale/risorse', 'gestionale/risorse/*',
        'gestionale/prenotazioni', 'gestionale/prenotazioni/*',
        'gestionale/eventi', 'gestionale/eventi/*',
        'gestionale/domande', 'gestionale/domande/*',
        'gestionale/conti', 'gestionale/conti/*',
        'gestionale/prima-nota', 'gestionale/prima-nota/*',
        'gestionale/ricevute/*',
        // Profilo: solo il nome; la configurazione guidata si può rimandare, non salvare.
        'gestionale/profilo/nome', 'gestionale/configurazione/rimanda',
    ];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! DemoScale::restricted()) {
            return $next($request);
        }
        // L'amministrazione della piattaforma e lo staff non esistono nella demo pubblica.
        if ($request->is($this->platformPaths())) {
            abort(404);
        }
        // Lo scarico della copia di sicurezza pesa sul server condiviso: chiuso, in lettura e in scrittura.
        if ($request->is('gestionale/esportazione.zip', 'gestionale/esportazione/*')) {
            throw ValidationException::withMessages(['ledger' => DemoScale::BACKUP_OFF]);
        }
        if (! $request->isMethodSafe() && ! $request->is(self::ALLOWED)) {
            throw ValidationException::withMessages(['ledger' => DemoScale::CHANGE_OFF]);
        }

        return $next($request);
    }

    /** @return list<string> */
    private function platformPaths(): array
    {
        $path = config('saas.admin_path', 'saas/admin');
        $path = trim(is_string($path) ? $path : 'saas/admin', '/');

        return [$path, $path.'/*', 'saas/staff/*'];
    }
}
