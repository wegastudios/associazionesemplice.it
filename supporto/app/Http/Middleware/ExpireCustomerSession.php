<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'accesso del cliente viene dal portale e non si aggiorna da solo: dopo poco tempo scade e "Accedi" lo rinnova
 * (se nel portale la persona e ancora connessa, il ritorno e immediato; se ha fatto Esci nel portale, non entra piu).
 */
class ExpireCustomerSession
{
    public const MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->session()->get('customer');

        if (is_array($customer) && (time() - (int) ($customer['at'] ?? 0)) > self::MINUTES * 60) {
            $request->session()->forget('customer');
        }

        return $next($request);
    }
}
