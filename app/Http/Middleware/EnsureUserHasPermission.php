<?php

namespace App\Http\Middleware;

use App\Support\Branch\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function __construct(private readonly BranchContext $context) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $allowed = collect($permissions)->contains(
            fn (string $required): bool => $request->user()?->hasPermission($required, $this->context->id()) ?? false,
        );
        abort_unless($allowed, 403);

        return $next($request);
    }
}
