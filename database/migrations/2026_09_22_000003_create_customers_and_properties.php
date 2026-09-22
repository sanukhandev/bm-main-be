<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id');
            $table->string('customer_code', 64);
            $table->string('customer_type', 20);
            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_registration_no', 100)->nullable();
            $table->string('identity_no', 100)->nullable();
            $table->string('company_registration_no', 100)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_or_emirate')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'customer_code'], 'uq_customers_branch_code');
            $table->unique(['id', 'branch_id'], 'uq_customers_id_branch');
            $table->foreign('branch_id', 'fk_customers_branch')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['branch_id', 'status'], 'idx_customers_branch_status');
            $table->index(['branch_id', 'customer_type'], 'idx_customers_branch_type');
            $table->index(['branch_id', 'display_name'], 'idx_customers_branch_name');
            $table->index(['branch_id', 'phone'], 'idx_customers_branch_phone');
            $table->index(['branch_id', 'email'], 'idx_customers_branch_email');
            $table->index(['branch_id', 'created_at'], 'idx_customers_branch_created');
        });

        Schema::create('customer_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('customer_id');
            $table->string('role', 20);
            $table->timestamps();
            $table->unique(['branch_id', 'customer_id', 'role'], 'uq_customer_roles_branch_customer_role');
            $table->foreign(['customer_id', 'branch_id'], 'fk_customer_roles_customer_branch')
                ->references(['id', 'branch_id'])->on('customers')->restrictOnDelete();
            $table->index(['branch_id', 'role'], 'idx_customer_roles_branch_role');
            $table->index(['branch_id', 'role', 'customer_id'], 'idx_customer_roles_lookup');
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('owner_customer_id');
            $table->string('property_code', 64);
            $table->string('unit_number', 100)->nullable();
            $table->string('property_type', 30);
            $table->string('name');
            $table->string('building_name')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_or_emirate')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->decimal('area', 18, 4)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'property_code'], 'uq_properties_branch_code');
            $table->unique(['id', 'branch_id'], 'uq_properties_id_branch');
            $table->unique(['id', 'branch_id', 'owner_customer_id'], 'uq_properties_id_branch_owner');
            $table->foreign('branch_id', 'fk_properties_branch')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign(['owner_customer_id', 'branch_id'], 'fk_properties_owner_branch')
                ->references(['id', 'branch_id'])->on('customers')->restrictOnDelete();
            $table->index(['branch_id', 'owner_customer_id'], 'idx_properties_branch_owner');
            $table->index(['branch_id', 'property_type'], 'idx_properties_branch_type');
            $table->index(['branch_id', 'status'], 'idx_properties_branch_status');
            $table->index(['branch_id', 'unit_number'], 'idx_properties_branch_unit');
            $table->index(['branch_id', 'owner_customer_id', 'status'], 'idx_properties_owner_status');
            $table->index(['branch_id', 'created_at'], 'idx_properties_branch_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
        Schema::dropIfExists('customer_role_assignments');
        Schema::dropIfExists('customers');
    }
};
