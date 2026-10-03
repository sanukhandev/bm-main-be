<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('electricity_provider', 40)->nullable()->after('area');
            $table->string('electricity_account_number', 100)->nullable()->after('electricity_provider');
            $table->string('cooling_provider', 40)->nullable()->after('electricity_account_number');
            $table->string('cooling_account_number', 100)->nullable()->after('cooling_provider');
            $table->string('gas_provider', 40)->nullable()->after('cooling_account_number');
            $table->string('gas_connection_type', 30)->nullable()->after('gas_provider');
            $table->string('gas_connection_number', 100)->nullable()->after('gas_connection_type');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'electricity_provider',
                'electricity_account_number',
                'cooling_provider',
                'cooling_account_number',
                'gas_provider',
                'gas_connection_type',
                'gas_connection_number',
            ]);
        });
    }
};
