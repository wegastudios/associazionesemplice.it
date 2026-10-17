<?php

namespace App\Http\Middleware;

use App\Exceptions\SupportException;
use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticazione dei siti di supporto: "Authorization: Bearer <client_id>.<segreto>".
 * Prima dell'autenticazione tutte le risposte sono generiche (nessuna enumerazione di credenziali).
 */
class SiteAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        [$clientId, $secret] = array_pad(explode('.', $token, 2), 2, '');

        $site = ($clientId !== '' && $secret !== '')
            ? Site::query()->where('client_id', $clientId)->whereNull('revoked_at')->first()
            : null;

        $hash = hash('sha256', $secret);
        $valid = false;

        if ($site !== null) {
            $valid = hash_equals($site->secret_hash, $hash);

            if (! $valid && $site->previous_secret_hash !== null
                && $site->previous_secret_expires_at?->isFuture()) {
                $valid = hash_equals($site->previous_secret_hash, $hash);
            }
        } else {
            // Confronto fittizio per non rivelare via timing se il sito esiste
            hash_equals(str_repeat('0', 64), $hash);
        }

        if (! $valid) {
            throw SupportException::of('UNAUTHENTICATED', 'Credenziali non valide.', 401);
        }

        if ($site->last_used_at === null || $site->last_used_at->diffInSeconds(now(), true) > 60) {
            $site->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('site', $site);

        return $next($request);
    }
}
