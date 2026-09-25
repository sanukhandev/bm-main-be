<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\OwnerAgreement;
use App\Models\TenantAgreement;
use Illuminate\Support\Facades\DB;

class CustomerProfileService
{
    public function build(Customer $customer, bool $includeTransactions): array
    {
        $owner = $customer->businessRoles()->where('role', 'owner')->exists();
        $tenant = $customer->businessRoles()->where('role', 'tenant')->exists();
        $agreementRelations = ['properties'];
        if ($includeTransactions) {
            $agreementRelations = array_merge($agreementRelations, ['installments.allocations.transaction', 'additionalPayments.accountTransaction']);
        }
        $ownerAgreements = $owner ? OwnerAgreement::query()->where('branch_id', $customer->branch_id)->where('owner_customer_id', $customer->id)->with($agreementRelations)->latest()->get() : collect();
        $tenantAgreements = $tenant ? TenantAgreement::query()->where('branch_id', $customer->branch_id)->where('tenant_customer_id', $customer->id)->with($agreementRelations)->latest()->get() : collect();

        $properties = $owner
            ? DB::table('properties')->where('branch_id', $customer->branch_id)->where('owner_customer_id', $customer->id)->whereNull('deleted_at')->orderBy('property_code')->get()
            : $tenantAgreements->flatMap->properties->unique('id')->values();

        $transactions = $includeTransactions ? $this->transactions($customer) : ['data' => [], 'total' => 0, 'truncated' => false];

        return [
            'properties' => collect($properties)->map(fn ($property) => [
                'id' => $property->id,
                'property_code' => $property->property_code,
                'name' => $property->name,
                'building_name' => $property->building_name,
                'unit_number' => $property->unit_number,
                'property_type' => $property->property_type instanceof \BackedEnum ? $property->property_type->value : $property->property_type,
                'status' => $property->status,
            ])->values()->all(),
            'agreements' => [
                'owner' => $this->agreements($ownerAgreements, $includeTransactions),
                'tenant' => $this->agreements($tenantAgreements, $includeTransactions),
            ],
            'transactions' => $transactions,
            'financial_restricted' => ! $includeTransactions,
        ];
    }

    private function agreements($agreements, bool $includeFinancial): array
    {
        return $agreements->map(fn ($agreement) => [
            'id' => $agreement->id,
            'agreement_no' => $agreement->agreement_no,
            'start_date' => $agreement->start_date?->format('Y-m-d'),
            'end_date' => $agreement->end_date?->format('Y-m-d'),
            'status' => $agreement->status,
            'total_amount' => $agreement->total_amount,
            'payment_lines' => $includeFinancial ? $this->paymentLines($agreement) : [],
            'properties' => $agreement->properties->map(fn ($property) => [
                'id' => $property->id,
                'property_code' => $property->property_code,
                'name' => $property->name,
                'unit_number' => $property->unit_number,
            ])->values()->all(),
        ])->values()->all();
    }

    private function paymentLines($agreement): array
    {
        $direction = $agreement instanceof OwnerAgreement ? 'outward' : 'inward';
        $scheduled = $agreement->installments->map(function ($line) use ($direction) {
            $receipt = null;
            if ($line->status === 'paid') {
                $transaction = $line->allocations->map->transaction
                    ->filter(fn ($transaction) => $transaction && $transaction->status?->value === 'posted')
                    ->sortByDesc('id')->first();
                $receipt = $transaction ? ['id' => $transaction->id, 'document_no' => $transaction->document_no, 'direction' => $transaction->direction?->value] : null;
            }

            return [
                'id' => $line->id,
                'line_type' => 'scheduled',
                'line_no' => $line->installment_no,
                'particulars' => $line->notes,
                'due_date' => $line->due_date?->format('Y-m-d'),
                'amount' => $line->amount,
                'paid_amount' => $line->paid_amount,
                'balance' => number_format((float) $line->amount - (float) $line->paid_amount, 2, '.', ''),
                'direction' => $direction,
                'payment_mode' => $line->payment_mode,
                'status' => $line->status,
                'receipt' => $receipt,
            ];
        });

        $additional = $agreement->additionalPayments->map(function ($line) {
            $transaction = $line->status === 'paid' ? $line->accountTransaction : null;

            return [
                'id' => $line->id,
                'line_type' => 'additional',
                'line_no' => 'extra-'.$line->id,
                'particulars' => $line->particulars,
                'category' => $line->category,
                'due_date' => $line->due_date?->format('Y-m-d'),
                'amount' => $line->amount,
                'paid_amount' => $line->status === 'paid' ? $line->amount : '0.00',
                'balance' => $line->status === 'paid' ? '0.00' : $line->amount,
                'direction' => $line->direction,
                'payment_mode' => $line->payment_mode,
                'status' => $line->status,
                'receipt' => $transaction && $transaction->status?->value === 'posted'
                    ? ['id' => $transaction->id, 'document_no' => $transaction->document_no, 'direction' => $transaction->direction?->value]
                    : null,
            ];
        });

        return $scheduled->concat($additional)->values()->all();
    }

    private function transactions(Customer $customer): array
    {
        $query = AccountTransaction::query()->where('branch_id', $customer->branch_id)->where('party_customer_id', $customer->id)->latest('transaction_date')->latest('id');
        $total = (clone $query)->count();
        $rows = $query->limit(100)->get();
        $ids = $rows->modelKeys();
        $agreementRefs = DB::table('account_transaction_allocations as allocations')
            ->leftJoin('tenant_agreement_installments as tenant_installments', 'tenant_installments.id', '=', 'allocations.tenant_agreement_installment_id')
            ->leftJoin('tenant_agreements as tenant_agreements', 'tenant_agreements.id', '=', 'tenant_installments.tenant_agreement_id')
            ->leftJoin('owner_agreement_installments as owner_installments', 'owner_installments.id', '=', 'allocations.owner_agreement_installment_id')
            ->leftJoin('owner_agreements as owner_agreements', 'owner_agreements.id', '=', 'owner_installments.owner_agreement_id')
            ->whereIn('allocations.account_transaction_id', $ids)
            ->select('allocations.account_transaction_id', 'tenant_agreements.agreement_no as tenant_agreement_no', 'owner_agreements.agreement_no as owner_agreement_no')
            ->get()->groupBy('account_transaction_id');

        return [
            'data' => $rows->map(fn (AccountTransaction $transaction) => [
                'id' => $transaction->id,
                'document_no' => $transaction->document_no,
                'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
                'direction' => $transaction->direction?->value,
                'payment_mode' => $transaction->payment_mode?->value,
                'amount' => $transaction->amount,
                'status' => $transaction->status?->value,
                'remarks' => $transaction->remarks,
                'agreement_numbers' => $agreementRefs->get($transaction->id, collect())->flatMap(fn ($row) => [$row->tenant_agreement_no, $row->owner_agreement_no])->filter()->unique()->values()->all(),
            ])->values()->all(),
            'total' => $total,
            'truncated' => $total > $rows->count(),
        ];
    }
}
