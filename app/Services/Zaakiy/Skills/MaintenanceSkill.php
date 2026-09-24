<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\OperationalDashboardService;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;

class MaintenanceSkill implements ZaakiySkill
{
    public function __construct(private readonly OperationalDashboardService $dashboard) {}

    public function matches(string $message): bool
    {
        return preg_match('/work order|maintenance|repair|vendor/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        return ['skill' => 'maintenance', 'data' => $this->dashboard->get($branch->branch()->id, false)['maintenance'], 'navigation' => ['label' => 'Open Work Orders', 'url' => '/app/maintenance/work-orders']];
    }
}
