<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zaakiy_action_proposals')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Prevent MariaDB/MySQL implicit ON UPDATE CURRENT_TIMESTAMP from overwriting expires_at on row updates.
            DB::statement('ALTER TABLE `zaakiy_action_proposals` MODIFY `expires_at` DATETIME NOT NULL, MODIFY `confirmed_at` DATETIME NULL DEFAULT NULL, MODIFY `rejected_at` DATETIME NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        // Keeping DATETIME avoids re-introducing implicit ON UPDATE CURRENT_TIMESTAMP behavior.
    }
};
