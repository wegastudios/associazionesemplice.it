<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Correlation-Id', '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) ? $incoming : (string) Str::ulid();

        app()->instance('correlation_id', $id);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $id);

        return $response;
    }
}
