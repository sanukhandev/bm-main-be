<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('quotation_no', 80);
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->string('status', 30)->default('draft');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'quotation_no']);
            $table->index(['branch_id', 'status', 'quotation_date']);
        });

        Schema::create('quotation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->string('particulars', 255);
            $table->decimal('quantity', 18, 2)->default(1);
            $table->decimal('unit_price', 18, 2)->unsigned();
            $table->decimal('line_total', 18, 2)->unsigned();
            $table->timestamps();
            $table->index(['branch_id', 'quotation_id']);
        });

        Schema::create('quotation_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->restrictOnDelete();
            $table->string('direction', 20);
            $table->string('particulars', 255);
            $table->decimal('amount', 18, 2)->unsigned();
            $table->date('due_date')->nullable();
            $table->string('payment_mode', 30);
            $table->string('status', 30)->default('pending');
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'quotation_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('invoice_no', 80);
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'invoice_no']);
            $table->index(['branch_id', 'status', 'invoice_date']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('particulars', 255);
            $table->decimal('quantity', 18, 2)->default(1);
            $table->decimal('unit_price', 18, 2)->unsigned();
            $table->decimal('line_total', 18, 2)->unsigned();
            $table->timestamps();
            $table->index(['branch_id', 'invoice_id']);
        });

        Schema::create('invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('direction', 20);
            $table->string('particulars', 255);
            $table->decimal('amount', 18, 2)->unsigned();
            $table->date('due_date')->nullable();
            $table->string('payment_mode', 30);
            $table->string('status', 30)->default('pending');
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quotation_payments');
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
