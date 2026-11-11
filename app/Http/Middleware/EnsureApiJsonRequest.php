<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureApiJsonRequest
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/*')) {
            $accept = (string) $request->header('Accept', '');
            if ($accept === '' || $accept === '*/*' || str_contains($accept, 'text/event-stream')) {
                $request->headers->set('Accept', 'application/json');
            }
        }

        return $next($request);
    }
}
