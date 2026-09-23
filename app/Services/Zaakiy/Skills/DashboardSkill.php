<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\OperationalDashboardService;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;

class DashboardSkill implements ZaakiySkill
{
    public function __construct(private readonly OperationalDashboardService $dashboard) {}

    public function matches(string $message): bool
    {
        return preg_match('/dashboard|overview|summary|kpi|metric|occupancy|how many/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        $branchId = $branch->branch()->id;

        return ['skill' => 'dashboard', 'data' => $this->dashboard->get($branchId, $user->hasPermission('accounts.view', $branchId)), 'navigation' => ['label' => 'Open Dashboard', 'url' => '/app/dashboard']];
    }
}
