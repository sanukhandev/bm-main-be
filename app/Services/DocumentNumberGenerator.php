<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

class DocumentNumberGenerator
{
    public function next(Branch $branch, string $documentType, int $year): string
    {
        DB::table('document_sequences')->insertOrIgnore([
            'branch_id' => $branch->id,
            'document_type' => $documentType,
            'year' => $year,
            'current_value' => 0,
        ]);

        $sequence = DB::table('document_sequences')
            ->where('branch_id', $branch->id)
            ->where('document_type', $documentType)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();
        $value = $sequence->current_value + 1;

        DB::table('document_sequences')->where('id', $sequence->id)->update([
            'current_value' => $value,
        ]);

        $prefix = match ($documentType) {
            'OWNER_CUSTOMER' => 'OWN',
            'TENANT_CUSTOMER' => 'TEN',
            'OWNER_AGREEMENT' => 'OA',
            'TENANT_AGREEMENT' => 'TA',
            'INWARD_RECEIPT' => 'IR',
            'OUTWARD_RECEIPT' => 'OR',
            'PETTY_CASH' => 'PC',
            'WORK_ORDER' => 'WO',
            default => throw new \InvalidArgumentException("Unknown document type: {$documentType}"),
        };

        return sprintf('%s-%s-%d-%06d', $branch->code, $prefix, $year, $value);
    }
}
