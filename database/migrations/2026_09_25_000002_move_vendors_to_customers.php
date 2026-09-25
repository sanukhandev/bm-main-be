<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $vendorIds = [];

        if (Schema::hasTable('vendors')) {
            foreach (DB::table('vendors')->orderBy('id')->get() as $vendor) {
                $customerId = DB::table('customers')->insertGetId([
                    'branch_id' => $vendor->branch_id,
                    'customer_code' => 'VEN-'.$vendor->id,
                    'customer_type' => 'organization',
                    'display_name' => $vendor->name,
                    'phone' => $vendor->phone,
                    'email' => $vendor->email,
                    'status' => $vendor->status,
                    'created_at' => $vendor->created_at,
                    'updated_at' => $vendor->updated_at,
                ]);
                DB::table('customer_role_assignments')->insert([
                    'branch_id' => $vendor->branch_id,
                    'customer_id' => $customerId,
                    'role' => 'vendor',
                    'created_at' => $vendor->created_at,
                    'updated_at' => $vendor->updated_at,
                ]);
                $vendorIds[$vendor->id] = $customerId;
            }
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::disableForeignKeyConstraints();
        } else {
            $constraints = DB::select("SELECT DISTINCT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'vendors'");
            foreach ($constraints as $constraint) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $constraint->TABLE_NAME, $constraint->CONSTRAINT_NAME));
            }
        }

        foreach ($vendorIds as $vendorId => $customerId) {
            foreach (['work_orders', 'quotations', 'invoices'] as $table) {
                DB::table($table)->where('vendor_id', $vendorId)->update(['vendor_id' => $customerId]);
            }
        }

        if (Schema::hasTable('vendors')) {
            Schema::drop('vendors');
        }

        if (Schema::hasTable('work_orders')) {
            Schema::table('work_orders', function (Blueprint $table): void {
                $table->foreign(['vendor_id', 'branch_id'], 'fk_work_orders_vendor_customer_branch')
                    ->references(['id', 'branch_id'])->on('customers')->restrictOnDelete();
            });
        }
        foreach (['quotations', 'invoices'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->foreign('vendor_id')
                        ->references('id')->on('customers')->nullOnDelete();
                });
            }
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        // Vendor identity is now part of customers; restoring the old table would lose role data.
    }
};
