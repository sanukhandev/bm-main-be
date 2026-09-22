<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name', 180);
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['id', 'branch_id']);
            $table->index(['branch_id', 'status']);
        });
        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('sku', 80);
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('unit_of_measure', 30)->default('piece');
            $table->decimal('reorder_level', 18, 3)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['branch_id', 'sku']);
            $table->unique(['id', 'branch_id']);
            $table->index(['branch_id', 'status']);
        });
        Schema::create('work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('work_order_no', 80);
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('open');
            $table->decimal('service_charge', 18, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'work_order_no']);
            $table->index(['branch_id', 'status', 'created_at']);
            $table->foreign(['property_id', 'branch_id'])->references(['id', 'branch_id'])->on('properties')->restrictOnDelete();
            $table->foreign(['vendor_id', 'branch_id'])->references(['id', 'branch_id'])->on('vendors')->restrictOnDelete();
        });
        Schema::create('work_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->string('line_type', 20);
            $table->foreignId('inventory_item_id')->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 18, 3)->default(1);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->timestamps();
            $table->foreign(['inventory_item_id', 'branch_id'])->references(['id', 'branch_id'])->on('inventory_items')->restrictOnDelete();
            $table->index(['branch_id', 'work_order_id']);
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('movement_type', 40);
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'inventory_item_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('work_order_lines');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('vendors');
    }
};
