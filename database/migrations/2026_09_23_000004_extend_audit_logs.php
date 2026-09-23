<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('entity_type', 80)->nullable()->change();
            $table->unsignedBigInteger('entity_id')->nullable()->change();
            $table->foreignId('actor_user_id')->nullable()->after('user_id');
            $table->json('before_json')->nullable()->after('entity_id');
            $table->json('after_json')->nullable()->after('before_json');
            $table->string('ip_address', 45)->nullable()->after('metadata_json');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->index(['branch_id', 'created_at'], 'idx_audit_branch_created');
            $table->index(['actor_user_id', 'created_at'], 'idx_audit_actor_created');
            $table->index(['action', 'created_at'], 'idx_audit_action_created');
            $table->foreign('actor_user_id', 'fk_audit_actor')->references('id')->on('users')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropForeign('fk_audit_actor');
            $table->dropIndex('idx_audit_branch_created');
            $table->dropIndex('idx_audit_actor_created');
            $table->dropIndex('idx_audit_action_created');
            $table->dropColumn(['actor_user_id', 'before_json', 'after_json', 'ip_address', 'user_agent']);
        });
    }
};
