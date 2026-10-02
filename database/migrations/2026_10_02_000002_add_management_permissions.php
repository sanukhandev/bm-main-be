<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        'agreements.manage' => 'Manage agreement workflows',
        'billing.manage' => 'Manage billing documents',
        'maintenance.manage' => 'Manage maintenance and inventory',
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $roleIds = DB::table('roles')->whereIn('key', ['super_admin', 'branch_admin'])->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($this->permissions))->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($this->permissions))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
