<?php

namespace App\Services;

use App\Models\User;
use App\Services\Zaakiy\ZaakiySkillRouter;
use App\Support\Branch\BranchContext;

class ZaakiyContextService
{
    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly ZaakiySkillRouter $skills,
    ) {}

    public function build(string $message, User $user): array
    {
        return $this->skills->run($message, $user, $this->branchContext);
    }
}
