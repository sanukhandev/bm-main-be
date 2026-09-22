<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait ApiScenario
{
    protected User $apiUser;

    protected int $branchA;

    protected int $branchB;

    protected int $customerA;

    protected int $customerB;

    protected function seedApiScenario(): void
    {
        $now = now();
        DB::table('roles')->insert([
            ['key' => 'super_admin', 'name' => 'Super Admin', 'scope' => 'global', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'branch_admin', 'name' => 'Branch Admin', 'scope' => 'branch', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'owner', 'name' => 'Owner', 'scope' => 'branch', 'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'tenant', 'name' => 'Tenant', 'scope' => 'branch', 'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->branchA = $this->branch('E2E-A', 'Test A');
        $this->branchB = $this->branch('E2E-B', 'Test B');
        $this->apiUser = User::query()->create([
            'name' => 'API User',
            'email' => 'api-'.Str::uuid().'@example.com',
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->where('id', $this->apiUser->getKey())->update(['status' => 'active']);
        $this->apiUser->refresh();
        DB::table('branch_user')->insert([
            'branch_id' => $this->branchA,
            'user_id' => $this->apiUser->getKey(),
            'status' => 'active',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('branch_user_roles')->insert([
            'branch_id' => $this->branchA,
            'user_id' => $this->apiUser->getKey(),
            'role_id' => DB::table('roles')->where('key', 'branch_admin')->value('id'),
            'created_at' => $now,
        ]);
        $this->customerA = $this->customer($this->branchA, 'A-001');
        $this->customerB = $this->customer($this->branchB, 'B-001');
    }

    protected function branch(string $code, string $name): int
    {
        return DB::table('branches')->insertGetId([
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'timezone' => 'Asia/Dubai',
            'currency_code' => 'AED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function customer(int $branchId, string $code): int
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

    protected function assignCustomerRole(int $branchId, int $customerId, string $role): void
    {
        DB::table('customer_role_assignments')->insert([
            'branch_id' => $branchId,
            'customer_id' => $customerId,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function branchRequest(?string $branchId = null)
    {
        return $this->withHeader('X-Branch-Id', $branchId ?? (string) $this->branchA);
    }
}
