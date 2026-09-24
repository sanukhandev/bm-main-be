<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;

class BillingSkill implements ZaakiySkill
{
    public function matches(string $message): bool
    {
        return preg_match('/invoice|invoices|quotation|quotations|billing/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        $branchId = $branch->branch()->id;

        return ['skill' => 'billing', 'data' => ['invoices' => DB::table('invoices')->where('branch_id', $branchId)->count(), 'quotations' => DB::table('quotations')->where('branch_id', $branchId)->count()], 'navigation' => ['label' => 'Open Billing', 'url' => '/app/billing/invoices']];
    }
}
