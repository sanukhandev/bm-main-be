<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_transactions', function (Blueprint $table): void {
            $table->unique(
                ['branch_id', 'source_type', 'source_id', 'payment_sequence'],
                'uq_account_tx_agreement_payment_sequence'
            );
        });
    }

    public function down(): void
    {
        Schema::table('account_transactions', function (Blueprint $table): void {
            $table->dropUnique('uq_account_tx_agreement_payment_sequence');
        });
    }
};
