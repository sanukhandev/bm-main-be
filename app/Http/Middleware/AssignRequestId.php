<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssignRequestId
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        try {
            return $next($request)->header('X-Request-Id', $requestId);
        } finally {
            Log::withoutContext();
        }
    }
}
