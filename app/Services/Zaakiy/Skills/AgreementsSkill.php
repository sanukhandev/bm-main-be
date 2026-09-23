<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\OperationalDashboardService;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;

class AgreementsSkill implements ZaakiySkill
{
    public function __construct(private readonly OperationalDashboardService $dashboard) {}

    public function matches(string $message): bool
    {
        return preg_match('/agreement|agreements|lease|expir|renew|commenc|tenant contract|owner contract/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        $data = $this->dashboard->get($branch->branch()->id, false);

        return ['skill' => 'agreements', 'data' => ['active_owner_agreements' => $data['summary']['owner_agreements_active'], 'active_tenant_agreements' => $data['summary']['tenant_agreements_active'], 'owner_expiring_30_days' => $data['agreements']['owner_expiring_30_days'], 'tenant_expiring_30_days' => $data['agreements']['tenant_expiring_30_days'], 'expiring_agreements' => $data['expiring_agreements']], 'navigation' => ['label' => 'Open Agreement Expiry', 'url' => '/app/reports/agreement-expiry']];
    }
}
