<?php

namespace App\Support\Branch;

use App\Models\Branch;
use LogicException;

final class BranchContext
{
    private ?Branch $branch = null;

    public function set(Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function branch(): Branch
    {
        return $this->branch ?? throw new LogicException('Branch context has not been resolved.');
    }

    public function id(): int
    {
        return $this->branch()->getKey();
    }

    public function isResolved(): bool
    {
        return $this->branch !== null;
    }
}
