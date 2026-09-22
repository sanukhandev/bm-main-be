<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->unique(['id', 'branch_id'], 'uq_work_orders_id_branch');
        });
        Schema::create('work_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->string('direction', 20);
            $table->string('category', 80);
            $table->string('particulars', 255);
            $table->decimal('amount', 18, 2)->unsigned();
            $table->date('due_date')->nullable();
            $table->string('payment_mode', 30);
            $table->string('status', 30)->default('pending');
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['work_order_id', 'branch_id'], 'fk_work_order_payments_order_branch')->references(['id', 'branch_id'])->on('work_orders')->restrictOnDelete();
            $table->index(['branch_id', 'work_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_payments');
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropUnique('uq_work_orders_id_branch');
        });
    }
};
