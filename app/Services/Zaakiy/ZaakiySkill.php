<?php

namespace App\Services\Zaakiy;

use App\Models\User;
use App\Support\Branch\BranchContext;

interface ZaakiySkill
{
    public function matches(string $message): bool;

    public function run(string $message, User $user, BranchContext $branch): array;
}
