<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureApiJsonRequest
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/*') && str_contains((string) $request->header('Accept'), 'text/event-stream')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
