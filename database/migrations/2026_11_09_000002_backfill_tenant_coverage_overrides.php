<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('audit_logs')
            ->where('action', 'tenant_agreement.coverage_overridden')
            ->where('entity_type', 'tenant_agreement')
            ->orderBy('id')
            ->get(['entity_id', 'metadata_json'])
            ->each(function (object $audit): void {
                $metadata = json_decode((string) $audit->metadata_json, true);
                $reason = is_array($metadata) ? trim((string) ($metadata['reason'] ?? '')) : '';

                if ($reason === '') {
                    return;
                }

                DB::table('tenant_agreements')
                    ->where('id', $audit->entity_id)
                    ->where('coverage_override', false)
                    ->update([
                        'coverage_override' => true,
                        'coverage_override_reason' => $reason,
                    ]);
            });
    }

    public function down(): void
    {
        // The original authorization is preserved in the immutable audit log.
    }
};
