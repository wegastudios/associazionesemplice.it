<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\DemoScale;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SplFileInfo;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nella demo (pubblica) nessun file in arrivo, da nessuna rotta: i file finirebbero sul disco del server. Va nello stack globale
 * (bootstrap/app.php), prima del router, quindi vale per ogni percorso del gestionale, dell'area soci, dei moduli pubblici e di
 * ogni rotta di domani, senza elenchi: basta che la richiesta porti un file, in qualunque campo. Risponde 422 con la frase
 * DemoScale::UPLOADS_OFF (le pagine hanno già i campi file spenti: arriva qui solo chi prova a forzarli).
 * Fuori dalla demo non fa nulla.
 */
final class DemoFileBarrier
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (DemoScale::uploadsOff() && self::carriesFile($request)) {
            return new JsonResponse(['message' => DemoScale::UPLOADS_OFF, 'errors' => ['ledger' => [DemoScale::UPLOADS_OFF]]], 422);
        }

        return $next($request);
    }

    /** La richiesta porta almeno un file vero, in qualunque campo (un campo file lasciato vuoto non conta). */
    private static function carriesFile(Request $request): bool
    {
        $files = $request->allFiles();
        $found = false;
        array_walk_recursive($files, static function (mixed $file) use (&$found): void {
            if ($file instanceof SplFileInfo) {
                $found = true;
            }
        });

        return $found;
    }
}
