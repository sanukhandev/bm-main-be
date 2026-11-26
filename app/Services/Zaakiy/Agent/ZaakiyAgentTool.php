<?php

namespace App\Services\Zaakiy\Agent;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Support\Branch\BranchContext;

interface ZaakiyAgentTool
{
    public function name(): string;

    public function statusLabel(): string;

    /** @return array<string, mixed> */
    public function declaration(): array;

    /** @return array<int, string> */
    public function requiredPermissions(): array;

    public function isWriteProposal(): bool;

    /** @param array<string, mixed> $args */
    public function execute(array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput;
}
