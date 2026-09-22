<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Support\Branch\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResolveBranchContext
{
    public function __construct(private readonly BranchContext $branchContext) {}

    public function handle(Request $request, Closure $next)
    {
        $rawBranchId = $request->header('X-Branch-Id');

        if ($rawBranchId === null || $rawBranchId === '') {
            throw new ApiException('BRANCH_CONTEXT_REQUIRED', 'Branch context is required.', 400);
        }

        if (! ctype_digit((string) $rawBranchId) || (int) $rawBranchId < 1) {
            throw new ApiException('BRANCH_NOT_FOUND', 'Branch not found.', 404);
        }

        $branch = Branch::query()
            ->whereKey((int) $rawBranchId)
            ->where('status', 'active')
            ->first();

        $user = $request->user();
        $authorized = $user?->isSuperAdmin() || ($user && $user->hasBranchRole($branch?->getKey() ?? 0, 'branch_admin'));

        if (! $branch || ! $authorized) {
            throw new ApiException('BRANCH_NOT_FOUND', 'Branch not found.', 404);
        }

        $this->branchContext->set($branch);
        Log::withContext([
            'user_id' => $user->getKey(),
            'branch_id' => $branch->getKey(),
        ]);

        return $next($request);
    }
}
