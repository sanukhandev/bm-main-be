<?php

namespace App\Services;

use App\Models\User;
use App\Services\Zaakiy\ReadOrchestrator;
use App\Support\Branch\BranchContext;

class ZaakiyContextService
{
    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly ReadOrchestrator $orchestrator,
    ) {}

    public function build(string $message, User $user, array $history = []): array
    {
        return $this->orchestrator->build($message, $history, $user, $this->branchContext);
    }
}
