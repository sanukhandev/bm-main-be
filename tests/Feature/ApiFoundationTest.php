<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $branchUser;

    private int $branchA;

    private int $branchB;

    private int $customerA;

    private int $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        DB::table('roles')->insert([
            ['key' => 'super_admin', 'name' => 'Super Admin', 'scope' => 'global', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'branch_admin', 'name' => 'Branch Admin', 'scope' => 'branch', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->branchA = DB::table('branches')->insertGetId([
            'code' => 'TEST-A', 'name' => 'Test A', 'status' => 'active', 'timezone' => 'Asia/Dubai', 'currency_code' => 'AED', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->branchB = DB::table('branches')->insertGetId([
            'code' => 'TEST-B', 'name' => 'Test B', 'status' => 'active', 'timezone' => 'Asia/Dubai', 'currency_code' => 'AED', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->branchUser = User::query()->create([
            'name' => 'Branch User',
            'email' => 'branch-user@example.com',
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->where('id', $this->branchUser->getKey())->update(['status' => 'active']);
        $this->branchUser->refresh();

        $branchRole = DB::table('roles')->where('key', 'branch_admin')->value('id');
        DB::table('branch_user')->insert([
            'branch_id' => $this->branchA,
            'user_id' => $this->branchUser->getKey(),
            'status' => 'active',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('branch_user_roles')->insert([
            'branch_id' => $this->branchA,
            'user_id' => $this->branchUser->getKey(),
            'role_id' => $branchRole,
            'created_at' => $now,
        ]);

        $this->customerA = $this->customer($this->branchA, 'A-001');
        $this->customerB = $this->customer($this->branchB, 'B-001');
    }

    public function test_active_user_can_login_and_receive_a_safe_user_resource(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:4200')
            ->postJson('/api/v1/auth/login', [
                'email' => 'BRANCH-USER@example.com',
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'branch-user@example.com')
            ->assertJsonMissingPath('data.password')
            ->assertHeader('X-Request-Id');
        $this->assertAuthenticated('web');
    }

    public function test_invalid_and_inactive_login_responses_are_safe(): void
    {
        $invalid = $this->postJson('/api/v1/auth/login', [
            'email' => 'branch-user@example.com',
            'password' => 'wrong',
        ]);

        $inactive = User::query()->create([
            'name' => 'Inactive User',
            'email' => 'inactive-'.Str::uuid().'@example.com',
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->where('id', $inactive->getKey())->update(['status' => 'inactive']);

        $inactiveResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $inactive->email,
            'password' => 'password',
        ]);
        $invalid->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $inactiveResponse->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->assertSame($invalid->json('message'), $inactiveResponse->json('message'));
    }

    public function test_sse_api_requests_return_json_unauthorized_instead_of_redirect_500(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'text/event-stream',
            'X-Branch-Id' => (string) $this->branchA,
        ])->post('/api/v1/ai/zaakiy/chat', [
            'message' => 'hi',
            'history' => [],
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED')
            ->assertHeader('X-Request-Id');
    }

    public function test_protected_routes_return_structured_unauthenticated_errors(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized()
            ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED')
            ->assertJsonStructure(['message', 'code', 'request_id'])
            ->assertHeader('X-Request-Id');
    }

    public function test_branch_context_and_cross_branch_binding_are_enforced(): void
    {
        $this->actingAs($this->branchUser, 'web');

        $this->getJson('/api/v1/customers')
            ->assertBadRequest()
            ->assertJsonPath('code', 'BRANCH_CONTEXT_REQUIRED');

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_code', 'A-001');

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->getJson('/api/v1/customers/'.$this->customerB)
            ->assertNotFound()
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->postJson('/api/v1/customers', [
                'branch_id' => $this->branchB,
                'customer_code' => 'A-002',
                'customer_type' => 'individual',
                'display_name' => 'Created In A',
            ])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $this->branchA);

        $this->assertDatabaseHas('customers', ['branch_id' => $this->branchA, 'customer_code' => 'A-002']);
        $this->assertDatabaseMissing('customers', ['branch_id' => $this->branchB, 'customer_code' => 'A-002']);
    }

    public function test_super_admin_can_select_an_authorized_branch_without_unscoping_the_endpoint(): void
    {
        $now = now();
        $superAdmin = User::query()->create([
            'name' => 'Super Admin',
            'email' => 'super-'.Str::uuid().'@example.com',
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->where('id', $superAdmin->getKey())->update(['status' => 'active']);
        $superAdmin->refresh();
        DB::table('user_global_roles')->insert([
            'user_id' => $superAdmin->getKey(),
            'role_id' => DB::table('roles')->where('key', 'super_admin')->value('id'),
            'created_at' => $now,
        ]);

        $this->actingAs($superAdmin, 'web');

        $this->withHeader('X-Branch-Id', (string) $this->branchB)
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_code', 'B-001');
    }

    public function test_dashboard_metrics_are_scoped_to_the_active_branch(): void
    {
        DB::table('customer_role_assignments')->insert([
            ['branch_id' => $this->branchA, 'customer_id' => $this->customerA, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $this->branchA, 'customer_id' => $this->customerA, 'role' => 'tenant', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('properties')->insert([
            'branch_id' => $this->branchA,
            'owner_customer_id' => $this->customerA,
            'property_code' => 'A-PROP-001',
            'property_type' => 'apartment',
            'name' => 'A Property',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->branchUser, 'web');

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->getJson('/api/v1/dashboard/metrics')
            ->assertOk()
            ->assertJsonPath('data.total_owners', 1)
            ->assertJsonPath('data.total_tenants', 1)
            ->assertJsonPath('data.total_properties', 1)
            ->assertJsonPath('data.total_owner_agreements', 0)
            ->assertJsonPath('data.total_tenant_agreements', 0);
    }

    public function test_operational_dashboard_returns_branch_scoped_kpis_and_attention_data(): void
    {
        $now = now();
        DB::table('customer_role_assignments')->insert([
            ['branch_id' => $this->branchA, 'customer_id' => $this->customerA, 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $this->branchA, 'customer_id' => $this->customerA, 'role' => 'tenant', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $property = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => 'A-DASH-001',
            'property_type' => 'apartment', 'name' => 'Dashboard Property', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $owner = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA, 'agreement_no' => 'A-OA-001', 'owner_customer_id' => $this->customerA,
            'start_date' => $now->toDateString(), 'end_date' => $now->copy()->addDays(10)->toDateString(), 'total_amount' => 10000,
            'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('owner_agreement_properties')->insert([
            'branch_id' => $this->branchA, 'owner_agreement_id' => $owner, 'property_id' => $property, 'owner_customer_id' => $this->customerA,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $tenant = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA, 'agreement_no' => 'A-TA-001', 'tenant_customer_id' => $this->customerA,
            'start_date' => $now->toDateString(), 'end_date' => $now->copy()->addDays(10)->toDateString(), 'total_amount' => 12000,
            'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('tenant_agreement_properties')->insert([
            'branch_id' => $this->branchA, 'tenant_agreement_id' => $tenant, 'property_id' => $property, 'source_owner_agreement_id' => $owner,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $permission = DB::table('permissions')->insertGetId(['key' => 'accounts.view', 'name' => 'View accounts', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('role_permissions')->insert(['role_id' => DB::table('roles')->where('key', 'branch_admin')->value('id'), 'permission_id' => $permission]);

        $this->actingAs($this->branchUser, 'web');
        $response = $this->withHeader('X-Branch-Id', (string) $this->branchA)->getJson('/api/v1/dashboard/operational')->assertOk();
        $response->assertJsonPath('data.summary.owners', 1)
            ->assertJsonPath('data.summary.tenants', 1)
            ->assertJsonPath('data.summary.properties', 1)
            ->assertJsonPath('data.summary.owner_agreements_active', 1)
            ->assertJsonPath('data.summary.tenant_agreements_active', 1)
            ->assertJsonPath('data.occupancy.occupied_properties', 1)
            ->assertJsonPath('data.occupancy.available_properties', 0)
            ->assertJsonPath('data.agreements.owner_expiring_30_days', 1)
            ->assertJsonPath('data.agreements.tenant_expiring_30_days', 1)
            ->assertJsonPath('data.financial_attention.tenant_receivables', '0.00');

        $this->withHeader('X-Branch-Id', (string) $this->branchB)->getJson('/api/v1/dashboard/operational')->assertNotFound();
    }

    public function test_operational_dashboard_hides_financial_attention_without_accounts_permission(): void
    {
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('key', ['accounts.view', 'accounts.post', 'accounts.void'])->pluck('id'))->delete();
        $this->actingAs($this->branchUser, 'web');

        $this->withHeader('X-Branch-Id', (string) $this->branchA)->getJson('/api/v1/dashboard/operational')
            ->assertOk()->assertJsonPath('data.financial_attention', null);
    }

    public function test_super_admin_can_read_administration_users_and_roles(): void
    {
        $email = 'administration-'.Str::uuid().'@example.com';
        $superAdmin = User::query()->create([
            'name' => 'Administration Admin',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->where('id', $superAdmin->getKey())->update([
            'status' => 'active',
            'last_login_at' => now(),
        ]);
        DB::table('user_global_roles')->insert([
            'user_id' => $superAdmin->getKey(),
            'role_id' => DB::table('roles')->where('key', 'super_admin')->value('id'),
            'created_at' => now(),
        ]);
        $superAdmin->refresh();

        $this->actingAs($superAdmin, 'web');

        $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonFragment(['email' => $email]);
        $this->getJson('/api/v1/admin/roles')
            ->assertOk()
            ->assertJsonFragment(['name' => 'super_admin']);
    }

    public function test_super_admin_can_create_and_edit_branches_with_emirate_codes(): void
    {
        $this->actingAs($this->superAdmin(), 'web');

        $first = $this->postJson('/api/v1/admin/branches', [
            'name' => 'Dubai One', 'state_or_emirate' => 'Dubai', 'timezone' => 'Asia/Dubai', 'currency_code' => 'AED',
        ])->assertCreated()->assertJsonPath('data.code', 'DXB-001');
        $second = $this->postJson('/api/v1/admin/branches', [
            'name' => 'Dubai Two', 'state_or_emirate' => 'Dubai', 'timezone' => 'Asia/Dubai', 'currency_code' => 'AED',
        ])->assertCreated()->assertJsonPath('data.code', 'DXB-002');
        $this->postJson('/api/v1/admin/branches', [
            'code' => 'FAKE', 'name' => 'Ajman One', 'state_or_emirate' => 'Ajman', 'timezone' => 'Asia/Dubai', 'currency_code' => 'AED',
        ])->assertCreated()->assertJsonPath('data.code', 'AJM-001');
        $this->patchJson('/api/v1/admin/branches/'.$first->json('data.id'), ['name' => 'Dubai Updated'])
            ->assertOk()->assertJsonPath('data.name', 'Dubai Updated')->assertJsonPath('data.code', 'DXB-001');
        $this->assertNotSame($first->json('data.code'), $second->json('data.code'));
    }

    public function test_super_admin_can_create_update_and_suspend_users_with_branch_access(): void
    {
        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin, 'web');

        $created = $this->postJson('/api/v1/admin/users', [
            'name' => 'Managed User',
            'email' => 'managed@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_ids' => [$this->branchA],
            'roles' => ['branch_admin'],
        ]);

        $created->assertCreated()->assertJsonPath('data.email', 'managed@example.com');
        $managedId = $created->json('data.id');
        $this->assertDatabaseHas('branch_user', ['user_id' => $managedId, 'branch_id' => $this->branchA, 'is_default' => 1]);
        $this->assertDatabaseHas('branch_user_roles', ['user_id' => $managedId, 'branch_id' => $this->branchA]);

        $this->patchJson('/api/v1/admin/users/'.$managedId, [
            'name' => 'Updated Managed User',
            'email' => 'managed-updated@example.com',
            'branch_ids' => [$this->branchB],
            'roles' => ['branch_admin'],
        ])->assertOk()->assertJsonPath('data.name', 'Updated Managed User');
        $this->assertDatabaseMissing('branch_user', ['user_id' => $managedId, 'branch_id' => $this->branchA]);
        $this->assertDatabaseHas('branch_user', ['user_id' => $managedId, 'branch_id' => $this->branchB]);

        $this->patchJson('/api/v1/admin/users/'.$managedId.'/status', ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson('/api/v1/auth/login', ['email' => 'managed@example.com', 'password' => 'password123'])
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_only_super_admin_can_manage_users_and_cannot_suspend_self(): void
    {
        $this->actingAs($this->branchUser, 'web');
        $this->postJson('/api/v1/admin/users', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_ids' => [$this->branchA],
            'roles' => ['branch_admin'],
        ])->assertForbidden();
    }

    public function test_super_admin_cannot_suspend_self(): void
    {
        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin, 'web');
        $this->patchJson('/api/v1/admin/users/'.$superAdmin->id.'/status', ['status' => 'suspended'])
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_USER_STATUS');
    }

    public function test_identity_document_extraction_verifies_customer_on_create_and_locks_the_id(): void
    {
        Http::fake(['*generativelanguage.googleapis.com*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'display_name' => 'Ahmed Al Mansoori',
                'identity_no' => '784-1990-1234567-1',
                'country_code' => 'ARE',
                'confidence' => ['display_name' => 0.98],
                'warnings' => [],
            ])]]]]],
        ], 200)]);
        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin, 'web');
        $before = DB::table('customers')->count();

        $response = $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->post('/api/v1/customers/identity-extract', [
                'role' => 'owner',
                'document' => UploadedFile::fake()->create('emirates-id.jpg', 100, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.fields.display_name', 'Ahmed Al Mansoori')
            ->assertJsonPath('data.fields.identity_no', '784-1990-1234567-1')
            ->assertJsonPath('data.fields.country_code', 'AE');
        $this->assertNotEmpty($response->json('data.verification_token'));

        $customer = $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->postJson('/api/v1/customers', [
                'customer_type' => 'individual',
                'display_name' => 'Ahmed Al Mansoori',
                'identity_no' => '784-1990-1234567-1',
                'roles' => ['owner'],
                'identity_verification_token' => $response->json('data.verification_token'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.identity_verified', true);

        $this->assertSame($before + 1, DB::table('customers')->count());
        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->patchJson('/api/v1/customers/'.$customer->json('data.id'), ['identity_no' => '784-1991-7654321-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identity_no');
    }

    public function test_customer_profile_returns_role_properties_agreements_and_authorized_transactions(): void
    {
        DB::table('customer_role_assignments')->insert(['branch_id' => $this->branchA, 'customer_id' => $this->customerA, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $property = DB::table('properties')->insertGetId(['branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => 'A-PROFILE-001', 'property_type' => 'apartment', 'name' => 'Profile Flat', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $agreement = DB::table('owner_agreements')->insertGetId(['branch_id' => $this->branchA, 'agreement_no' => 'OA-PROFILE-001', 'owner_customer_id' => $this->customerA, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_amount' => '12000.00', 'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('owner_agreement_properties')->insert(['branch_id' => $this->branchA, 'owner_agreement_id' => $agreement, 'property_id' => $property, 'owner_customer_id' => $this->customerA, 'created_at' => now(), 'updated_at' => now()]);
        $installment = DB::table('owner_agreement_installments')->insertGetId(['branch_id' => $this->branchA, 'owner_agreement_id' => $agreement, 'installment_no' => 1, 'due_date' => '2026-01-01', 'amount' => '12000.00', 'paid_amount' => '0.00', 'payment_mode' => 'cash', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $transaction = DB::table('account_transactions')->insertGetId(['branch_id' => $this->branchA, 'document_no' => 'OUT-PROFILE-001', 'direction' => 'outward', 'transaction_date' => '2026-01-15', 'payment_mode' => 'cash', 'amount' => '5000.00', 'party_customer_id' => $this->customerA, 'source_type' => 'owner_agreement_payment', 'source_id' => $agreement, 'status' => 'posted', 'created_by' => $this->branchUser->id, 'posted_by' => $this->branchUser->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('account_transaction_allocations')->insert(['branch_id' => $this->branchA, 'account_transaction_id' => $transaction, 'owner_agreement_installment_id' => $installment, 'amount' => '5000.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->superAdmin(), 'web');
        $this->withHeader('X-Branch-Id', (string) $this->branchA)->getJson('/api/v1/customers/'.$this->customerA.'/profile')
            ->assertOk()
            ->assertJsonPath('data.customer.id', $this->customerA)
            ->assertJsonPath('data.profile.properties.0.property_code', 'A-PROFILE-001')
            ->assertJsonPath('data.profile.agreements.owner.0.agreement_no', 'OA-PROFILE-001')
            ->assertJsonPath('data.profile.agreements.owner.0.payment_lines.0.line_no', 1)
            ->assertJsonPath('data.profile.agreements.owner.0.payment_lines.0.direction', 'outward')
            ->assertJsonPath('data.profile.transactions.data.0.document_no', 'OUT-PROFILE-001')
            ->assertJsonPath('data.profile.financial_restricted', false);
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Super Admin',
            'email' => 'super-'.Str::uuid().'@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->forceFill(['status' => 'active'])->save();
        DB::table('user_global_roles')->insert([
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('key', 'super_admin')->value('id'),
            'created_at' => now(),
        ]);

        return $user->fresh();
    }

    public function test_login_rate_limit_returns_standard_json_error(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'throttled@example.com',
                'password' => 'wrong',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'throttled@example.com',
            'password' => 'wrong',
        ])->assertTooManyRequests()
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
            ->assertJsonStructure(['message', 'code', 'request_id']);
    }

    public function test_property_and_agreement_crud_uses_safe_delete(): void
    {
        $tenant = $this->customer($this->branchA, 'A-002');
        $ownerRole = DB::table('roles')->where('key', 'owner')->value('id');
        $tenantRole = DB::table('roles')->where('key', 'tenant')->value('id');

        if (! $ownerRole) {
            $ownerRole = DB::table('roles')->insertGetId([
                'key' => 'owner', 'name' => 'Owner', 'scope' => 'branch', 'is_system' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (! $tenantRole) {
            $tenantRole = DB::table('roles')->insertGetId([
                'key' => 'tenant', 'name' => 'Tenant', 'scope' => 'branch', 'is_system' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('customer_role_assignments')->insert([
            ['branch_id' => $this->branchA, 'customer_id' => $this->customerA, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $this->branchA, 'customer_id' => $tenant, 'role' => 'tenant', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingAs($this->branchUser, 'web');

        $property = $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->postJson('/api/v1/properties', [
                'owner_customer_id' => $this->customerA,
                'property_code' => 'PROP-001',
                'property_type' => 'apartment',
                'name' => 'Flat 101',
            ])
            ->assertCreated()
            ->json('data');

        $ownerAgreement = $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->postJson('/api/v1/owner-agreements', [
                'agreement_no' => 'OA-001',
                'owner_customer_id' => $this->customerA,
                'property_ids' => [$property['id']],
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'total_amount' => '12000.00',
                'currency_code' => 'AED',
                'payment_count' => 12,
                'payment_mode' => 'bank_transfer',
            ])
            ->assertCreated()
            ->json('data');

        $tenantAgreement = $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->postJson('/api/v1/tenant-agreements', [
                'agreement_no' => 'TA-001',
                'tenant_customer_id' => $tenant,
                'properties' => [[
                    'property_id' => $property['id'],
                    'source_owner_agreement_id' => $ownerAgreement['id'],
                ]],
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'total_amount' => '24000.00',
                'currency_code' => 'AED',
                'payment_count' => 12,
                'payment_mode' => 'cash',
            ])
            ->assertCreated();

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->deleteJson('/api/v1/properties/'.$property['id'])
            ->assertNoContent();
        $this->assertDatabaseHas('properties', ['id' => $property['id'], 'status' => 'archived']);
        $this->assertNotNull(DB::table('properties')->where('id', $property['id'])->value('deleted_at'));

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->deleteJson('/api/v1/owner-agreements/'.$ownerAgreement['id'], ['reason' => 'Owner record closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('owner_agreements', ['id' => $ownerAgreement['id'], 'status' => 'cancelled']);
        $this->assertNull(DB::table('owner_agreements')->where('id', $ownerAgreement['id'])->value('deleted_at'));

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->deleteJson('/api/v1/tenant-agreements/'.$tenantAgreement->json('data.id'), ['reason' => 'Tenant record closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('tenant_agreements', ['id' => $tenantAgreement->json('data.id'), 'status' => 'cancelled']);
        $this->assertNull(DB::table('tenant_agreements')->where('id', $tenantAgreement->json('data.id'))->value('deleted_at'));

        $this->withHeader('X-Branch-Id', (string) $this->branchA)
            ->deleteJson('/api/v1/customers/'.$this->customerA)
            ->assertNoContent();
        $this->assertDatabaseHas('customers', ['id' => $this->customerA, 'status' => 'archived']);
        $this->assertNotNull(DB::table('customers')->where('id', $this->customerA)->value('deleted_at'));
    }

    private function customer(int $branchId, string $code): int
    {
        return DB::table('customers')->insertGetId([
            'branch_id' => $branchId,
            'customer_code' => $code,
            'customer_type' => 'individual',
            'display_name' => $code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
