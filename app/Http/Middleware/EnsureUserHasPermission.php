<?php

namespace App\Http\Middleware;

use App\Support\Branch\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function __construct(private readonly BranchContext $context) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless($request->user()?->hasPermission($permission, $this->context->id()), 403);

        return $next($request);
    }
}
