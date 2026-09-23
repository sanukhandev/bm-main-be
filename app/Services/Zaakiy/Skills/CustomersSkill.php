<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;

class CustomersSkill implements ZaakiySkill
{
    public function matches(string $message): bool
    {
        return preg_match('/owner|owners|tenant|tenants|customer|customers/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        $branchId = $branch->branch()->id;
        $count = fn (string $role) => DB::table('customer_role_assignments as roles')->join('customers', 'customers.id', '=', 'roles.customer_id')->where('roles.branch_id', $branchId)->where('roles.role', $role)->where('customers.status', 'active')->whereNull('customers.deleted_at')->count();
        $route = preg_match('/tenant/i', $message) === 1 ? ['label' => 'Open Tenants', 'url' => '/app/customers/tenants'] : ['label' => 'Open Owners', 'url' => '/app/customers/owners'];

        return ['skill' => 'customers', 'data' => ['owners' => $count('owner'), 'tenants' => $count('tenant')], 'navigation' => $route];
    }
}
