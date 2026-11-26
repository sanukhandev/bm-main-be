<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zaakiy_threads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'user_id', 'last_message_at'], 'idx_zaakiy_threads_branch_user_recent');
        });

        Schema::create('zaakiy_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('zaakiy_threads')->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content');
            $table->json('blocks_json')->nullable();
            $table->json('navigation_json')->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('candidates_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('feedback', 20)->nullable();
            $table->string('feedback_note', 500)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'thread_id', 'id'], 'idx_zaakiy_messages_thread');
        });

        Schema::create('zaakiy_tool_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('zaakiy_threads')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('zaakiy_messages')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tool_name', 80);
            $table->json('args_json')->nullable();
            $table->string('status', 24);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at'], 'idx_zaakiy_tool_runs_branch_date');
        });

        Schema::create('zaakiy_action_proposals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('zaakiy_threads')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('zaakiy_messages')->nullOnDelete();
            $table->string('action_type', 64);
            $table->string('summary_text', 255);
            $table->json('payload_json');
            $table->string('required_permission', 64);
            $table->string('idempotency_key', 100);
            $table->string('status', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('result_entity_type', 64)->nullable();
            $table->unsignedBigInteger('result_entity_id')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'expires_at'], 'idx_zaakiy_proposals_branch_status');
            $table->index(['branch_id', 'user_id', 'status'], 'idx_zaakiy_proposals_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zaakiy_action_proposals');
        Schema::dropIfExists('zaakiy_tool_runs');
        Schema::dropIfExists('zaakiy_messages');
        Schema::dropIfExists('zaakiy_threads');
    }
};
