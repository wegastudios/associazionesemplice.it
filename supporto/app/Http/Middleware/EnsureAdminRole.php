<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Parti riservate all'amministratore (argomenti, guide, piani, utenti, posta): l'agente risponde ai ticket e basta. */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Auth::user()?->isAdmin(), 403, 'Accesso riservato agli amministratori.');

        return $next($request);
    }
}
