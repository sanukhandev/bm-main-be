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
    }
}
