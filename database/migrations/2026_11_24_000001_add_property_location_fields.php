<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('plot_number', 100)->nullable()->after('unit_number');
            $table->string('usage', 20)->nullable()->after('plot_number');
            $table->string('landmark', 255)->nullable()->after('building_name');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn(['plot_number', 'usage', 'landmark']);
        });
    }
};
