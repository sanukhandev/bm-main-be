<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\Branch\BranchContext;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && app(BranchContext::class)->isResolved();
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->isActive() && $this->matchesBranch($customer);
    }

    public function create(User $user): bool
    {
        return $user->isActive() && app(BranchContext::class)->isResolved();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->isActive() && $this->matchesBranch($customer);
    }

    private function matchesBranch(Customer $customer): bool
    {
        return app(BranchContext::class)->isResolved()
            && $customer->branch_id === app(BranchContext::class)->id();
    }
}
