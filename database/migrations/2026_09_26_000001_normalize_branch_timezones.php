<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('branches')->update(['timezone' => 'Asia/Dubai']);
    }

    public function down(): void
    {
        // The previous values are not recoverable from this normalization.
    }
};
