<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_agreements', function (Blueprint $table) {
            $table->boolean('coverage_override')->default(false)->after('file_no');
            $table->text('coverage_override_reason')->nullable()->after('coverage_override');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_agreements', function (Blueprint $table) {
            $table->dropColumn(['coverage_override', 'coverage_override_reason']);
        });
    }
};
