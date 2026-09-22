<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            ->assertJsonPath('data.branch_id', null);

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
