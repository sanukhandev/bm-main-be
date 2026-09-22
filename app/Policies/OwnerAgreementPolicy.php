<?php

namespace App\Policies;

use App\Models\OwnerAgreement;
use App\Models\User;
use App\Support\Branch\BranchContext;

class OwnerAgreementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && app(BranchContext::class)->isResolved();
    }

    public function create(User $user): bool
    {
        return $user->isActive() && app(BranchContext::class)->isResolved();
    }

    public function view(User $user, OwnerAgreement $agreement): bool
    {
        return $user->isActive() && $this->matchesBranch($agreement);
    }

    public function update(User $user, OwnerAgreement $agreement): bool
    {
        return $user->isActive() && $this->matchesBranch($agreement) && in_array($agreement->status, ['draft', 'pending_approval'], true);
    }

    public function delete(User $user, OwnerAgreement $agreement): bool
    {
        return $user->isActive() && $this->matchesBranch($agreement);
    }

    private function matchesBranch(OwnerAgreement $agreement): bool
    {
        return app(BranchContext::class)->isResolved() && $agreement->branch_id === app(BranchContext::class)->id();
    }
}
