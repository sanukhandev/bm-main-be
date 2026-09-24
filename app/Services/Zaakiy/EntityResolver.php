<?php

namespace App\Services\Zaakiy;

use Illuminate\Support\Facades\DB;

class EntityResolver
{
    public function resolve(string $message, ZaakiyExecutionContext $context): ?SkillEvidence
    {
        preg_match_all('/\b(?:TA|OA|P|WO|IR|OV|CUS)[-_][A-Z0-9-]+\b/i', $message, $matches);
        if ($matches[0] === []) {
            return null;
        }

        $records = [];
        foreach (array_values(array_unique($matches[0])) as $reference) {
            $normalized = strtoupper($reference);
            $match = null;
            $type = null;
            if (str_starts_with($normalized, 'P-')) {
                $match = DB::table('properties')->where('branch_id', $context->branchId())->where('property_code', $reference)->first(['id', 'property_code', 'name']);
                $type = 'property';
            } elseif (str_starts_with($normalized, 'TA-')) {
                $match = DB::table('tenant_agreements')->where('branch_id', $context->branchId())->where('agreement_no', $reference)->first(['id', 'agreement_no', 'status']);
                $type = 'tenant_agreement';
            } elseif (str_starts_with($normalized, 'OA-')) {
                $match = DB::table('owner_agreements')->where('branch_id', $context->branchId())->where('agreement_no', $reference)->first(['id', 'agreement_no', 'status']);
                $type = 'owner_agreement';
            } elseif (str_starts_with($normalized, 'WO-')) {
                $match = DB::table('work_orders')->where('branch_id', $context->branchId())->where('work_order_no', $reference)->first(['id', 'work_order_no', 'status']);
                $type = 'work_order';
            } elseif (str_starts_with($normalized, 'CUS-')) {
                $match = DB::table('customers')->where('branch_id', $context->branchId())->where('customer_code', $reference)->whereNull('deleted_at')->first(['id', 'customer_code', 'display_name']);
                $type = 'customer';
            }
            $records[] = $match
                ? ['entity_type' => $type, 'reference' => $reference, 'fields' => (array) $match]
                : ['reference' => $reference, 'ambiguous_or_not_found' => true];
        }

        return new SkillEvidence('entity_resolution', 'resolve branch-scoped business references', records: $records, warnings: ['Unresolved references are never guessed.']);
    }
}
