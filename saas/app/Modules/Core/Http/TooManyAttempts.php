<?php

declare(strict_types=1);

namespace App\Modules\Core\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use SymfonyComponentHttpKernelxceptionHttpExceptionInterface;
use Throwable;

/**
 * Limite di frequenza superato (429). Un'azione fatta da una pagina del gestionale o dell'area soci torna indietro con
 * l'avviso sopra la pagina, al posto del riquadro che Inertia apre per le risposte che non conosce. Aprendo una pagina, e
 * nelle pagine senza quell'avviso (accesso e moduli pubblici), resta la pagina 429; alle API la risposta di Laravel.
 */
final class TooManyAttempts
{
    /** Pagine dell'area soci senza l'avviso: mostrano la pagina 429. */
    private const WITHOUT_NOTICE = ['area/accesso', 'area/accesso/*', 'area/entra/*'];

    public static function message(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return 'Hai fatto troppi tentativi in poco tempo. Aspetta qualche minuto e riprova.';
        }
        $minutes = (int) ceil($seconds / 60);
        if ($minutes < 60) {
            return 'Hai fatto troppi tentativi in poco tempo. Riprova tra '.($minutes === 1 ? 'un minuto' : $minutes.' minuti').'.';
        }
        $hours = (int) ceil($seconds / 3600);

        return 'Hai fatto troppi tentativi in poco tempo. Riprova tra '.($hours === 1 ? 'un\'ora' : $hours.' ore').'.';
    }

    /** @param  array<mixed>  $headers  intestazioni dell'errore */
    public static function fromHeaders(array $headers): string
    {
        $wait = $headers['Retry-After'] ?? null;

        return self::message(is_numeric($wait) ? (int) $wait : null);
    }

    public static function page(Throwable $e): string
    {
        return self::fromHeaders($e instanceof HttpExceptionInterface ? $e->getHeaders() : []);
    }

    public static function render(Throwable $e, Request $request): ?RedirectResponse
    {
        $noticed = $request->is('gestionale/*', 'area/*') && ! $request->is(self::WITHOUT_NOTICE);
        // Aprendo una pagina (GET) non si torna indietro: si finirebbe sulla stessa pagina, di nuovo oltre il limite.
        if ($request->isMethodSafe() || ! $noticed || ! $request->hasHeader('X-Inertia')) {
            return null;
        }

        // 303: dopo un PUT o un DELETE il browser deve chiedere la pagina con un GET.
        return back(303)->withErrors(['ledger' => self::page($e)]);
    }
}
