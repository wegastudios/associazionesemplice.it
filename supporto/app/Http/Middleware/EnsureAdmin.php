<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Accesso al pannello riservato agli amministratori del sito (users.global_role = admin). */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->guest(route('admin.login'));
        }

        abort_unless($user->isAdmin(), 403, 'Accesso riservato agli amministratori.');

        return $next($request);
    }
}
