<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;

class ReportsSkill implements ZaakiySkill
{
    public function matches(string $message): bool
    {
        return preg_match('/report|reports|receipt|voucher|cash movement|daybook/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        return ['skill' => 'reports', 'data' => ['available' => true, 'financial_reports' => $user->hasPermission('accounts.view', $branch->branch()->id)], 'navigation' => ['label' => 'Open Reports', 'url' => '/app/accounts/reports']];
    }
}
