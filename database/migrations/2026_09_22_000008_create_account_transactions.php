<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('document_type', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('current_value')->default(0);
            $table->unique(['branch_id', 'document_type', 'year']);
        });

        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('document_no', 80);
            $table->string('direction', 20);
            $table->date('transaction_date');
            $table->string('payment_mode', 30);
            $table->decimal('amount', 18, 2)->unsigned();
            $table->foreignId('party_customer_id')->nullable();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('payment_sequence')->nullable();
            $table->text('remarks')->nullable();
            $table->string('cheque_no', 100)->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_reference')->nullable();
            $table->date('transfer_date')->nullable();
            $table->string('status', 20)->default('posted');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'document_no'], 'uq_account_tx_branch_document');
            $table->unique(['branch_id', 'idempotency_key'], 'uq_account_tx_branch_idempotency');
            $table->index(['branch_id', 'transaction_date'], 'idx_account_tx_branch_date');
            $table->index(['branch_id', 'direction', 'transaction_date'], 'idx_account_tx_branch_direction_date');
            $table->index(['branch_id', 'status', 'transaction_date'], 'idx_account_tx_branch_status_date');
            $table->index(['branch_id', 'payment_mode', 'transaction_date'], 'idx_account_tx_branch_mode_date');
            $table->index(['branch_id', 'source_type', 'source_id'], 'idx_account_tx_source');
            $table->foreign(['party_customer_id', 'branch_id'], 'fk_account_tx_party_branch')->references(['id', 'branch_id'])->on('customers')->restrictOnDelete();
        });

        Schema::create('account_transaction_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('account_transaction_id');
            $table->unsignedBigInteger('owner_agreement_installment_id')->nullable();
            $table->unsignedBigInteger('tenant_agreement_installment_id')->nullable();
            $table->decimal('amount', 18, 2)->unsigned();
            $table->timestamps();
            $table->foreign('account_transaction_id', 'fk_account_alloc_transaction')->references('id')->on('account_transactions')->restrictOnDelete();
            $table->foreign('owner_agreement_installment_id', 'fk_account_alloc_owner_installment')->references('id')->on('owner_agreement_installments')->restrictOnDelete();
            $table->foreign('tenant_agreement_installment_id', 'fk_account_alloc_tenant_installment')->references('id')->on('tenant_agreement_installments')->restrictOnDelete();
            $table->index(['branch_id', 'account_transaction_id'], 'idx_account_alloc_branch_transaction');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'entity_type', 'entity_id'], 'idx_audit_branch_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('account_transaction_allocations');
        Schema::dropIfExists('account_transactions');
        Schema::dropIfExists('document_sequences');
    }
};
