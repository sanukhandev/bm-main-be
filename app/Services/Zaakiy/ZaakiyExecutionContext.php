<?php

namespace App\Services\Zaakiy;

use App\Models\User;
use App\Support\Branch\BranchContext;

final readonly class ZaakiyExecutionContext
{
    public function __construct(
        public User $user,
        public BranchContext $branch,
        public IntentFrame $intent,
        public string $requestAt,
    ) {}

    public function branchId(): int
    {
        return $this->branch->id();
    }

    public function can(string $permission): bool
    {
        return $this->user->hasPermission($permission, $this->branchId());
    }
}
