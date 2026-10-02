<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['owner_agreement_installments', 'tenant_agreement_installments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('category', 30)->default('rent')->after('payment_mode');
                $table->string('particulars', 255)->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        foreach (['owner_agreement_installments', 'tenant_agreement_installments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['category', 'particulars']);
            });
        }
    }
};
