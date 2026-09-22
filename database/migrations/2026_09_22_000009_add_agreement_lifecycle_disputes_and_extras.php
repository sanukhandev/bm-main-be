<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['owner_agreements', 'tenant_agreements'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->timestamp('held_at')->nullable();
                $table->foreignId('held_by_user_id')->nullable();
                $table->text('hold_reason')->nullable();
                $table->foreign('held_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::create('agreement_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('owner_agreement_id')->nullable();
            $table->foreignId('tenant_agreement_id')->nullable();
            $table->foreignId('raised_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('subject', 180);
            $table->text('description');
            $table->string('status', 30)->default('open');
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->foreign(['owner_agreement_id', 'branch_id'], 'fk_disputes_owner_branch')->references(['id', 'branch_id'])->on('owner_agreements')->restrictOnDelete();
            $table->foreign(['tenant_agreement_id', 'branch_id'], 'fk_disputes_tenant_branch')->references(['id', 'branch_id'])->on('tenant_agreements')->restrictOnDelete();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('agreement_dispute_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('agreement_dispute_id')->constrained('agreement_disputes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('comment');
            $table->timestamps();
            $table->index(['branch_id', 'agreement_dispute_id']);
        });

        Schema::create('agreement_additional_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('owner_agreement_id')->nullable();
            $table->foreignId('tenant_agreement_id')->nullable();
            $table->string('direction', 20);
            $table->string('category', 80);
            $table->decimal('amount', 18, 2)->unsigned();
            $table->date('due_date')->nullable();
            $table->string('payment_mode', 30)->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['owner_agreement_id', 'branch_id'], 'fk_extra_owner_branch')->references(['id', 'branch_id'])->on('owner_agreements')->restrictOnDelete();
            $table->foreign(['tenant_agreement_id', 'branch_id'], 'fk_extra_tenant_branch')->references(['id', 'branch_id'])->on('tenant_agreements')->restrictOnDelete();
            $table->index(['branch_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_additional_payments');
        Schema::dropIfExists('agreement_dispute_comments');
        Schema::dropIfExists('agreement_disputes');
        foreach (['owner_agreements', 'tenant_agreements'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropForeign(['held_by_user_id']);
                $table->dropColumn(['held_at', 'held_by_user_id', 'hold_reason']);
            });
        }
    }
};
