<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('roles')->upsert([
            [
                'key' => 'super_admin',
                'name' => 'Super Admin',
                'scope' => 'global',
                'description' => 'Global access across authorized branches.',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'branch_admin',
                'name' => 'Branch Admin',
                'scope' => 'branch',
                'description' => 'Administrative access within an assigned branch.',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['key'], ['name', 'scope', 'description', 'is_system', 'updated_at']);

        $permissions = collect([
            ['key' => 'accounts.view', 'name' => 'View accounts'],
            ['key' => 'accounts.post', 'name' => 'Post financial transactions'],
            ['key' => 'accounts.void', 'name' => 'Void financial transactions'],
        ])->map(fn (array $permission) => [...$permission, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('permissions')->upsert($permissions->all(), ['key'], ['name', 'updated_at']);

        $roleIds = DB::table('roles')->whereIn('key', ['super_admin', 'branch_admin'])->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('key', ['accounts.view', 'accounts.post', 'accounts.void'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }
}
