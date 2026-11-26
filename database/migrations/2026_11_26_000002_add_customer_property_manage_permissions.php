<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ([['customers.manage', 'Manage customers'], ['properties.manage', 'Manage properties']] as [$key, $name]) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            foreach (DB::table('roles')->whereIn('key', ['super_admin', 'branch_admin'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], []);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', ['customers.manage', 'properties.manage'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
