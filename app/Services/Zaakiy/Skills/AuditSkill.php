<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;

class AuditSkill implements ZaakiySkill
{
    public function matches(string $message): bool
    {
        return preg_match('/audit|activity history|who changed/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        $branchId = $branch->branch()->id;
        if (! $user->hasPermission('audit.view', $branchId)) {
            return ['skill' => 'audit', 'data' => ['restricted' => true]];
        }

        return ['skill' => 'audit', 'data' => ['available' => true, 'entries' => DB::table('audit_logs')->where('branch_id', $branchId)->count()], 'navigation' => ['label' => 'Open Audit Trail', 'url' => '/app/administration/audit']];
    }
}
