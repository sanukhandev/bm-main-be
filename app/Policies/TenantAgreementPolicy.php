<?php

namespace App\Policies;

use App\Models\TenantAgreement;
use App\Models\User;
use App\Support\Branch\BranchContext;

class TenantAgreementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && app(BranchContext::class)->isResolved();
    }

    public function create(User $user): bool
    {
        return $user->isActive() && app(BranchContext::class)->isResolved();
    }

    public function view(User $user, TenantAgreement $agreement): bool
    {
        return $user->isActive() && $this->matchesBranch($agreement);
    }

    public function lifecycle(User $user, TenantAgreement $agreement): bool
    {
        return $user->isActive() && $this->matchesBranch($agreement);
    }

    public function update(User $user, TenantAgreement $agreement): bool
    {
        return $user->isActive() && $this->matchesBranch($agreement) && in_array($agreement->status, ['draft', 'pending_approval'], true);
    }

    public function delete(User $user, TenantAgreement $agreement): bool
    {
        return $user->isActive() && $this->matchesBranch($agreement);
    }

    private function matchesBranch(TenantAgreement $agreement): bool
    {
        return app(BranchContext::class)->isResolved() && $agreement->branch_id === app(BranchContext::class)->id();
    }
}
