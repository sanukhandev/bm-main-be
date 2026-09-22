<?php

namespace Tests\Unit\Policies;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\OwnerAgreement;
use App\Models\Property;
use App\Models\TenantAgreement;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\OwnerAgreementPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\TenantAgreementPolicy;
use App\Support\Branch\BranchContext;
use Tests\TestCase;

class ResourcePolicyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User;
        $this->user->setRawAttributes(['status' => 'active']);
        $branch = new Branch;
        $branch->setRawAttributes(['id' => 1]);
        app(BranchContext::class)->set($branch);
    }

    public function test_customer_policy_requires_the_current_branch(): void
    {
        $policy = new CustomerPolicy;
        $customer = new Customer;
        $customer->setRawAttributes(['branch_id' => 1]);
        $other = new Customer;
        $other->setRawAttributes(['branch_id' => 2]);

        $this->assertTrue($policy->view($this->user, $customer));
        $this->assertFalse($policy->view($this->user, $other));
        $this->assertTrue($policy->delete($this->user, $customer));
    }

    public function test_property_policy_requires_the_current_branch(): void
    {
        $policy = new PropertyPolicy;
        $property = new Property;
        $property->setRawAttributes(['branch_id' => 1]);
        $other = new Property;
        $other->setRawAttributes(['branch_id' => 2]);

        $this->assertTrue($policy->view($this->user, $property));
        $this->assertFalse($policy->update($this->user, $other));
    }

    public function test_owner_agreement_policy_allows_edits_only_before_approval(): void
    {
        $policy = new OwnerAgreementPolicy;
        $draft = new OwnerAgreement;
        $draft->setRawAttributes(['branch_id' => 1, 'status' => 'draft']);
        $approved = new OwnerAgreement;
        $approved->setRawAttributes(['branch_id' => 1, 'status' => 'approved']);

        $this->assertTrue($policy->update($this->user, $draft));
        $this->assertFalse($policy->update($this->user, $approved));
    }

    public function test_tenant_agreement_policy_hides_other_branches(): void
    {
        $policy = new TenantAgreementPolicy;
        $agreement = new TenantAgreement;
        $agreement->setRawAttributes(['branch_id' => 1, 'status' => 'draft']);
        $other = new TenantAgreement;
        $other->setRawAttributes(['branch_id' => 2, 'status' => 'draft']);

        $this->assertTrue($policy->view($this->user, $agreement));
        $this->assertFalse($policy->delete($this->user, $other));
    }
}
