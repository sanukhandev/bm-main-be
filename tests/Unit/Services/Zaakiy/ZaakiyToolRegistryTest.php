<?php

namespace Tests\Unit\Services\Zaakiy;

use App\Models\Branch;
use App\Models\User;
use App\Services\Zaakiy\Agent\ZaakiyToolRegistry;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ZaakiyToolRegistryTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_registry_filters_tools_by_permission_hides_server_context_params_and_blocks_direct_calls(): void
    {
        $readOnlyRoleId = DB::table('roles')->insertGetId([
            'key' => 'viewer_only',
            'name' => 'Viewer Only',
            'scope' => 'branch',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $readOnlyUser = User::query()->create([
            'name' => 'Read Only',
            'email' => 'readonly-'.Str::uuid().'@example.com',
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->where('id', $readOnlyUser->id)->update(['status' => 'active']);
        $readOnlyUser->refresh();
        DB::table('branch_user')->insert([
            'branch_id' => $this->branchA,
            'user_id' => $readOnlyUser->id,
            'status' => 'active',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('branch_user_roles')->insert([
            'branch_id' => $this->branchA,
            'user_id' => $readOnlyUser->id,
            'role_id' => $readOnlyRoleId,
            'created_at' => now(),
        ]);

        $registry = app(ZaakiyToolRegistry::class);
        $readOnlyNames = array_column($registry->declarationsFor($readOnlyUser, $this->branchA), 'name');
        $fullNames = array_column($registry->declarationsFor($this->apiUser, $this->branchA), 'name');

        $this->assertContains('lookup_entities', $readOnlyNames);
        $this->assertContains('get_vacancy_analysis', $readOnlyNames);
        $this->assertContains('search_erp_faq', $readOnlyNames);
        $this->assertNotContains('get_collections_health', $readOnlyNames);
        $this->assertNotContains('get_intelligent_report', $readOnlyNames);

        $this->assertCount(14, $fullNames);
        $this->assertContains('get_collections_health', $fullNames);
        $this->assertContains('get_intelligent_report', $fullNames);
        $this->assertContains('query_erp_reports', $fullNames);

        foreach ($registry->declarationsFor($this->apiUser, $this->branchA) as $declaration) {
            $props = array_keys($declaration['parameters']['properties'] ?? []);
            $this->assertNotContains('branch_id', $props);
            $this->assertNotContains('user_id', $props);
        }

        $branchContext = new BranchContext;
        $branchContext->set(Branch::query()->findOrFail($this->branchA));

        $denied = $registry->execute('get_collections_health', [], $readOnlyUser, $branchContext);
        $this->assertSame('FORBIDDEN', $denied->modelPayload['error_code'] ?? null);

        $deniedReport = $registry->execute('query_erp_reports', ['report' => 'inward_receipts'], $readOnlyUser, $branchContext);
        $this->assertSame('FORBIDDEN', $deniedReport->modelPayload['error_code'] ?? null);
    }

    public function test_lookup_entities_scopes_to_active_branch_and_emits_disambiguation_for_multiple_matches(): void
    {
        $t1 = DB::table('customers')->insertGetId([
            'branch_id' => $this->branchA,
            'customer_code' => 'TEN-010',
            'customer_type' => 'individual',
            'display_name' => 'Ahmed Al Mansoori',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $t2 = DB::table('customers')->insertGetId([
            'branch_id' => $this->branchA,
            'customer_code' => 'TEN-011',
            'customer_type' => 'individual',
            'display_name' => 'Ahmed Khan',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tOtherBranch = DB::table('customers')->insertGetId([
            'branch_id' => $this->branchB,
            'customer_code' => 'TEN-999',
            'customer_type' => 'individual',
            'display_name' => 'Ahmed Sharjah',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assignCustomerRole($this->branchA, $t1, 'tenant');
        $this->assignCustomerRole($this->branchA, $t2, 'tenant');
        $this->assignCustomerRole($this->branchB, $tOtherBranch, 'tenant');

        $branchContext = new BranchContext;
        $branchContext->set(Branch::query()->findOrFail($this->branchA));

        $output = app(ZaakiyToolRegistry::class)->execute(
            'lookup_entities',
            ['query' => 'Ahmed', 'entity_type' => 'tenant'],
            $this->apiUser,
            $branchContext,
        );

        $this->assertNotNull($output->disambiguation);
        $this->assertCount(2, $output->disambiguation['candidates']);
        $labels = array_column($output->disambiguation['candidates'], 'label');
        $this->assertContains('Ahmed Al Mansoori', $labels);
        $this->assertContains('Ahmed Khan', $labels);
        $this->assertNotContains('Ahmed Sharjah', $labels);
    }
}
