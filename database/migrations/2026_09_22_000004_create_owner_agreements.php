<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id');
            $table->string('agreement_no', 64);
            $table->foreignId('owner_customer_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_amount', 18, 2)->unsigned();
            $table->string('currency_code', 3)->default('AED');
            $table->unsignedSmallInteger('payment_count');
            $table->string('payment_frequency', 30)->nullable();
            $table->string('payment_mode', 30);
            $table->text('terms_text')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable();
            $table->timestamp('commenced_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->foreignId('terminated_by_user_id')->nullable();
            $table->text('termination_reason')->nullable();
            $table->foreignId('renewed_from_agreement_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['branch_id', 'agreement_no'], 'uq_owner_agreements_branch_no');
            $table->unique(['id', 'branch_id'], 'uq_owner_agreements_id_branch');
            $table->unique(['id', 'branch_id', 'owner_customer_id'], 'uq_owner_agreements_id_branch_owner');
            $table->foreign('branch_id', 'fk_owner_agreements_branch')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign(['owner_customer_id', 'branch_id'], 'fk_owner_agreements_owner_branch')
                ->references(['id', 'branch_id'])->on('customers')->restrictOnDelete();
            $table->foreign('approved_by_user_id', 'fk_owner_agreements_approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('terminated_by_user_id', 'fk_owner_agreements_terminated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['renewed_from_agreement_id', 'branch_id'], 'fk_owner_agreements_renewed_from')
                ->references(['id', 'branch_id'])->on('owner_agreements')->restrictOnDelete();
            $table->index(['branch_id', 'owner_customer_id'], 'idx_owner_agreements_branch_owner');
            $table->index(['branch_id', 'status'], 'idx_owner_agreements_branch_status');
            $table->index(['branch_id', 'status', 'start_date'], 'idx_owner_agreements_status_start');
            $table->index(['branch_id', 'status', 'end_date'], 'idx_owner_agreements_status_end');
            $table->index(['branch_id', 'owner_customer_id', 'status'], 'idx_owner_agreements_owner_status');
            $table->index(['branch_id', 'start_date', 'end_date'], 'idx_owner_agreements_dates');
            $table->index(['branch_id', 'created_at'], 'idx_owner_agreements_branch_created');
            $table->index('renewed_from_agreement_id', 'idx_owner_agreements_renewed_from');
        });

        Schema::create('owner_agreement_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('owner_agreement_id');
            $table->foreignId('property_id');
            $table->foreignId('owner_customer_id');
            $table->timestamps();
            $table->unique(['owner_agreement_id', 'property_id'], 'uq_owner_agreement_properties_pair');
            $table->unique(['owner_agreement_id', 'property_id', 'branch_id'], 'uq_owner_agreement_properties_coverage');
            $table->foreign('branch_id', 'fk_owner_agreement_properties_branch')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign(['owner_agreement_id', 'branch_id', 'owner_customer_id'], 'fk_owner_agreement_properties_agreement')
                ->references(['id', 'branch_id', 'owner_customer_id'])->on('owner_agreements')->restrictOnDelete();
            $table->foreign(['property_id', 'branch_id', 'owner_customer_id'], 'fk_owner_agreement_properties_property')
                ->references(['id', 'branch_id', 'owner_customer_id'])->on('properties')->restrictOnDelete();
            $table->index(['branch_id', 'property_id'], 'idx_owner_agreement_properties_branch_property');
            $table->index(['branch_id', 'owner_customer_id'], 'idx_owner_agreement_properties_branch_owner');
            $table->index(['branch_id', 'owner_agreement_id'], 'idx_owner_agreement_properties_branch_agreement');
            $table->index(['property_id', 'owner_agreement_id'], 'idx_owner_agreement_properties_property_agreement');
        });

        Schema::create('owner_agreement_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('owner_agreement_id');
            $table->unsignedSmallInteger('installment_no');
            $table->date('due_date');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->decimal('paid_amount', 18, 2)->unsigned()->default(0);
            $table->string('payment_mode', 30);
            $table->string('status', 30)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['owner_agreement_id', 'installment_no'], 'uq_owner_installments_agreement_no');
            $table->unique(['id', 'branch_id'], 'uq_owner_installments_id_branch');
            $table->foreign(['owner_agreement_id', 'branch_id'], 'fk_owner_installments_agreement')
                ->references(['id', 'branch_id'])->on('owner_agreements')->restrictOnDelete();
            $table->index(['branch_id', 'due_date'], 'idx_owner_installments_branch_due');
            $table->index(['branch_id', 'status', 'due_date'], 'idx_owner_installments_status_due');
            $table->index(['branch_id', 'owner_agreement_id'], 'idx_owner_installments_branch_agreement');
        });

        Schema::create('owner_agreement_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('owner_agreement_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('action', 30);
            $table->foreignId('changed_by_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['owner_agreement_id', 'branch_id'], 'fk_owner_history_agreement')
                ->references(['id', 'branch_id'])->on('owner_agreements')->restrictOnDelete();
            $table->foreign('changed_by_user_id', 'fk_owner_history_changed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'owner_agreement_id', 'created_at'], 'idx_owner_history_agreement_created');
            $table->index(['branch_id', 'to_status', 'created_at'], 'idx_owner_history_status_created');
            $table->index(['changed_by_user_id', 'created_at'], 'idx_owner_history_changed_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_agreement_status_history');
        Schema::dropIfExists('owner_agreement_installments');
        Schema::dropIfExists('owner_agreement_properties');
        Schema::dropIfExists('owner_agreements');
    }
};
