<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;
use App\Support\Branch\BranchContext;

class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && app(BranchContext::class)->isResolved();
    }

    public function create(User $user): bool
    {
        return $user->isActive() && app(BranchContext::class)->isResolved();
    }

    public function view(User $user, Property $property): bool
    {
        return $user->isActive() && $this->matchesBranch($property);
    }

    public function update(User $user, Property $property): bool
    {
        return $user->isActive() && $this->matchesBranch($property);
    }

    public function delete(User $user, Property $property): bool
    {
        return $user->isActive() && $this->matchesBranch($property);
    }

    private function matchesBranch(Property $property): bool
    {
        return app(BranchContext::class)->isResolved() && $property->branch_id === app(BranchContext::class)->id();
    }
}
