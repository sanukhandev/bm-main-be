<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique('uq_branches_code');
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_or_emirate')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('timezone', 64)->default('Asia/Dubai');
            $table->string('currency_code', 3)->default('AED');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index('status', 'idx_branches_status');
            $table->index('name', 'idx_branches_name');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique('uq_roles_key');
            $table->string('name');
            $table->string('scope', 20);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
            $table->index('scope', 'idx_roles_scope');
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id');
            $table->foreignId('user_id');
            $table->string('status', 20)->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->primary(['branch_id', 'user_id'], 'pk_branch_user');
            $table->foreign('branch_id', 'fk_branch_user_branch')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('user_id', 'fk_branch_user_user')->references('id')->on('users')->restrictOnDelete();
            $table->index(['user_id', 'status'], 'idx_branch_user_user_status');
            $table->index(['branch_id', 'status'], 'idx_branch_user_branch_status');
        });

        Schema::create('user_global_roles', function (Blueprint $table) {
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'role_id'], 'pk_user_global_roles');
            $table->foreign('user_id', 'fk_user_global_roles_user')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('role_id', 'fk_user_global_roles_role')->references('id')->on('roles')->restrictOnDelete();
            $table->index('role_id', 'idx_user_global_roles_role');
        });

        Schema::create('branch_user_roles', function (Blueprint $table) {
            $table->foreignId('branch_id');
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['branch_id', 'user_id', 'role_id'], 'pk_branch_user_roles');
            $table->foreign(['branch_id', 'user_id'], 'fk_branch_user_roles_membership')
                ->references(['branch_id', 'user_id'])->on('branch_user')->restrictOnDelete();
            $table->foreign('role_id', 'fk_branch_user_roles_role')->references('id')->on('roles')->restrictOnDelete();
            $table->index(['user_id', 'branch_id'], 'idx_branch_user_roles_user_branch');
            $table->index('role_id', 'idx_branch_user_roles_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user_roles');
        Schema::dropIfExists('user_global_roles');
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('branches');
    }
};
