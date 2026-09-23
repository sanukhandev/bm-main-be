<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\OperationalDashboardService;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;

class PropertiesSkill implements ZaakiySkill
{
    public function __construct(private readonly OperationalDashboardService $dashboard) {}

    public function matches(string $message): bool
    {
        return preg_match('/property|properties|occupied|vacant|available/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        $data = $this->dashboard->get($branch->branch()->id, false);

        return ['skill' => 'properties', 'data' => ['properties' => $data['summary']['properties'], 'available_properties' => $data['occupancy']['available_properties'], 'occupied_properties' => $data['occupancy']['occupied_properties']], 'navigation' => ['label' => 'Open Properties', 'url' => '/app/properties']];
    }
}
