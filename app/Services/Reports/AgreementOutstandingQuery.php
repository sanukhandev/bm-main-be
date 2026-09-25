<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AgreementOutstandingQuery
{
    public function unpaid(array $branchIds, string $type): Builder
    {
        [$installments, $agreements, $foreign, $party] = $this->tables($type);

        return DB::table($installments.' as installments')
            ->join($agreements.' as agreements', 'agreements.id', '=', 'installments.'.$foreign)
            ->join('customers', 'customers.id', '=', 'agreements.'.$party)
            ->whereIn('installments.branch_id', $branchIds)
            ->whereColumn('agreements.branch_id', 'installments.branch_id')
            ->whereColumn('installments.paid_amount', '<', 'installments.amount')
            ->select('installments.*', 'agreements.agreement_no', 'agreements.'.$party, 'customers.display_name as party_name');
    }

    public function tables(string $type): array
    {
        return match ($type) {
            'tenant' => ['tenant_agreement_installments', 'tenant_agreements', 'tenant_agreement_id', 'tenant_customer_id'],
            'owner' => ['owner_agreement_installments', 'owner_agreements', 'owner_agreement_id', 'owner_customer_id'],
            default => throw new InvalidArgumentException('Unsupported agreement type.'),
        };
    }
}
