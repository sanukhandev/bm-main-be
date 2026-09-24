<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_transactions', function (Blueprint $table): void {
            $table->string('cheque_status', 20)->nullable()->after('cheque_date');
            $table->timestamp('cheque_status_changed_at')->nullable()->after('cheque_status');
            $table->foreignId('cheque_status_changed_by')->nullable()->after('cheque_status_changed_at')->constrained('users')->nullOnDelete();
            $table->index(['branch_id', 'payment_mode', 'cheque_status'], 'idx_account_tx_cheque_status');
        });
    }

    public function down(): void
    {
        Schema::table('account_transactions', function (Blueprint $table): void {
            $table->dropForeign(['cheque_status_changed_by']);
            $table->dropIndex('idx_account_tx_cheque_status');
            $table->dropColumn(['cheque_status_changed_by', 'cheque_status_changed_at', 'cheque_status']);
        });
    }
};
