<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['agreement_additional_payments', 'work_order_payments', 'quotation_payments', 'invoice_payments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('cheque_no', 100)->nullable();
                $table->date('cheque_date')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('bank_reference')->nullable();
                $table->date('transfer_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['agreement_additional_payments', 'work_order_payments', 'quotation_payments', 'invoice_payments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['cheque_no', 'cheque_date', 'bank_name', 'bank_reference', 'transfer_date']);
            });
        }
    }
};
