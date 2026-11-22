<?php

declare(strict_types=1);

namespace App\Modules\Core\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Blocco o istruzione scaduti (config saas.database_timeouts): non è un guasto, c'è un'altra operazione in corso.
 * Chi aspettava riceve «riprova tra poco» (503) al posto dell'errore generico; nel log una riga senza valori.
 */
final class DatabaseBusy
{
    /** lock_timeout, statement_timeout, idle_in_transaction_session_timeout */
    private const CODES = ['55P03', '57014', '25P03'];

    private const RETRY_AFTER = '5';

    public const HEADING = 'Riprova tra poco';

    /** Nella pagina, nella risposta JSON e nell'avviso sopra le pagine del gestionale e dell'area soci. */
    public const MESSAGE = 'In questo momento c\'è un\'altra operazione in corso e la tua richiesta non è riuscita a finire. Aspetta qualche secondo e riprova.';

    /** Le richieste a cui gli errori si danno in JSON, anche quelli di Laravel (bootstrap/app.php). */
    public static function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function matches(QueryException $e): bool
    {
        return in_array(self::sqlstate($e), self::CODES, true);
    }

    /**
     * Al posto del registro normale (che riporterebbe i valori della query): codice, connessione e testo con i segnaposto.
     * Con false il gestore non scrive altro.
     */
    public static function report(QueryException $e): bool
    {
        if (! self::matches($e)) {
            return true;
        }
        Log::warning('Tempo massimo scaduto sul database', ['sqlstate' => self::sqlstate($e), 'connection' => $e->connectionName, 'sql' => mb_substr($e->getSql(), 0, 1000)]);

        return false;
    }

    public static function render(QueryException $e, Request $request): JsonResponse|RedirectResponse|Response|null
    {
        if (! self::matches($e)) {
            return null;
        }
        if ($request->hasHeader('X-Inertia')) {
            return back()->withErrors(['ledger' => self::MESSAGE]);
        }
        if (self::wantsJson($request)) {
            return response()->json(['message' => self::MESSAGE], 503, ['Retry-After' => self::RETRY_AFTER]);
        }

        return response()->view('errors.busy', [], 503, ['Retry-After' => self::RETRY_AFTER]);
    }

    private static function sqlstate(QueryException $e): mixed
    {
        return $e->errorInfo[0] ?? $e->getCode();
    }
}
