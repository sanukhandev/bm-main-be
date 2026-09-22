<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreement_additional_payments', function (Blueprint $table): void {
            $table->string('particulars', 255)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('agreement_additional_payments', function (Blueprint $table): void {
            $table->dropColumn('particulars');
        });
    }
};
