<?php

namespace Tests\Unit\Policies;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerRoleAssignment;
use App\Models\OwnerAgreement;
use App\Models\Property;
use App\Models\TenantAgreement;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\OwnerAgreementPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\TenantAgreementPolicy;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ResourcePolicyTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->user = $this->apiUser;
        app(BranchContext::class)->set(Branch::query()->findOrFail($this->branchA));
    }

    public function test_customer_policy_requires_the_current_branch(): void
    {
        $policy = new CustomerPolicy;
        $customer = new Customer;
        $customer->setRawAttributes(['branch_id' => $this->branchA]);
        $other = new Customer;
        $other->setRawAttributes(['branch_id' => $this->branchB]);

        $this->assertTrue($policy->view($this->user, $customer));
        $this->assertFalse($policy->view($this->user, $other));
        $this->assertTrue($policy->delete($this->user, $customer));
    }

    public function test_property_policy_requires_the_current_branch(): void
    {
        $policy = new PropertyPolicy;
        $property = new Property;
        $property->setRawAttributes(['branch_id' => $this->branchA]);
        $other = new Property;
        $other->setRawAttributes(['branch_id' => $this->branchB]);

        $this->assertTrue($policy->view($this->user, $property));
        $this->assertFalse($policy->update($this->user, $other));
    }

    public function test_owner_agreement_policy_allows_edits_only_before_approval(): void
    {
        $policy = new OwnerAgreementPolicy;
        $draft = new OwnerAgreement;
        $draft->setRawAttributes(['branch_id' => $this->branchA, 'status' => 'draft']);
        $approved = new OwnerAgreement;
        $approved->setRawAttributes(['branch_id' => $this->branchA, 'status' => 'approved']);

        $this->assertTrue($policy->update($this->user, $draft));
        $this->assertFalse($policy->update($this->user, $approved));
    }

    public function test_tenant_agreement_policy_hides_other_branches(): void
    {
        $policy = new TenantAgreementPolicy;
        $agreement = new TenantAgreement;
        $agreement->setRawAttributes(['branch_id' => $this->branchA, 'status' => 'draft']);
        $other = new TenantAgreement;
        $other->setRawAttributes(['branch_id' => $this->branchB, 'status' => 'draft']);

        $this->assertTrue($policy->view($this->user, $agreement));
        $this->assertFalse($policy->delete($this->user, $other));
    }

    public function test_inactive_super_admin_has_no_permissions(): void
    {
        DB::table('user_global_roles')->insert([
            'user_id' => $this->user->id,
            'role_id' => DB::table('roles')->where('key', 'super_admin')->value('id'),
        ]);
        $this->user->forceFill(['status' => 'suspended'])->save();

        $this->assertFalse($this->user->hasPermission('accounts.view', $this->branchA));
    }

    public function test_branch_owned_child_route_binding_rejects_another_branch(): void
    {
        $id = DB::table('customer_role_assignments')->insertGetId([
            'branch_id' => $this->branchB, 'customer_id' => $this->customerB, 'role' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull((new CustomerRoleAssignment)->resolveRouteBindingQuery(CustomerRoleAssignment::query(), $id)->first());
    }
}
