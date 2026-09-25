<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\OwnerAgreement;
use App\Models\Property;
use App\Models\TenantAgreement;
use App\Models\WorkOrder;

class PropertyProfileService
{
    private const ACTIVE_OWNER_STATUSES = ['approved', 'commenced', 'on_hold'];

    private const ACTIVE_TENANT_STATUSES = ['pending_approval', 'approved', 'commenced', 'on_hold'];

    public function build(Property $property, bool $includeFinancials): array
    {
        $ownerAgreements = OwnerAgreement::query()
            ->where('branch_id', $property->branch_id)
            ->whereHas('properties', fn ($query) => $query->where('properties.id', $property->id))
            ->with(['owner', 'installments.allocations.transaction', 'additionalPayments.accountTransaction'])
            ->latest('id')->get();

        $tenantAgreements = TenantAgreement::query()
            ->where('branch_id', $property->branch_id)
            ->whereHas('properties', fn ($query) => $query->where('properties.id', $property->id))
            ->with(['tenant', 'installments.allocations.transaction', 'additionalPayments.accountTransaction'])
            ->latest('id')->get();

        $workOrders = WorkOrder::query()
            ->forBranch($property->branch_id)
            ->where('property_id', $property->id)
            ->with(['vendor', 'payments.accountTransaction'])
            ->latest('id')->get();

        $activeOwners = $ownerAgreements->whereIn('status', self::ACTIVE_OWNER_STATUSES);
        $activeTenants = $tenantAgreements->whereIn('status', self::ACTIVE_TENANT_STATUSES);

        return [
            'owner_agreements' => $ownerAgreements->map(fn ($agreement) => $this->agreement($agreement, 'owner', $includeFinancials))->values()->all(),
            'tenant_agreements' => $tenantAgreements->map(fn ($agreement) => $this->agreement($agreement, 'tenant', $includeFinancials))->values()->all(),
            'work_orders' => $workOrders->map(fn ($workOrder) => $this->workOrder($workOrder, $includeFinancials))->values()->all(),
            'actions' => [
                'can_create_owner_agreement' => $activeOwners->isEmpty(),
                'can_create_tenant_agreement' => $activeOwners->isNotEmpty() && $activeTenants->isEmpty(),
                'default_owner_agreement_id' => $activeOwners->sortByDesc('id')->first()?->id,
            ],
            'active_agreement_statuses' => [
                'owner' => self::ACTIVE_OWNER_STATUSES,
                'tenant' => self::ACTIVE_TENANT_STATUSES,
            ],
            'financial_restricted' => ! $includeFinancials,
        ];
    }

    private function agreement($agreement, string $type, bool $includeFinancials): array
    {
        return [
            'id' => $agreement->id,
            'agreement_no' => $agreement->agreement_no,
            'customer' => $this->customer($type === 'owner' ? $agreement->owner : $agreement->tenant),
            'start_date' => $agreement->start_date?->format('Y-m-d'),
            'end_date' => $agreement->end_date?->format('Y-m-d'),
            'status' => $agreement->status,
            'total_amount' => $includeFinancials ? $agreement->total_amount : null,
            'payment_lines' => $includeFinancials ? $this->paymentLines($agreement, $type) : [],
        ];
    }

    private function paymentLines($agreement, string $type): array
    {
        $direction = $type === 'owner' ? 'outward' : 'inward';
        $scheduled = $agreement->installments->map(function ($line) use ($direction) {
            $transaction = $line->allocations->map->transaction
                ->filter(fn ($transaction) => $transaction && $transaction->status?->value === 'posted')
                ->sortByDesc('id')->first();

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
                'receipt' => $this->receipt($transaction),
            ];
        });

        $additional = $agreement->additionalPayments->map(function ($line) {
            $transaction = $line->status === 'paid' ? $line->accountTransaction : null;

            return [
                'id' => $line->id,
                'line_type' => 'additional',
                'line_no' => 'extra-'.$line->id,
                'particulars' => $line->particulars.' | '.$line->category,
                'due_date' => $line->due_date?->format('Y-m-d'),
                'amount' => $line->amount,
                'paid_amount' => $line->status === 'paid' ? $line->amount : '0.00',
                'balance' => $line->status === 'paid' ? '0.00' : $line->amount,
                'direction' => $line->direction,
                'payment_mode' => $line->payment_mode,
                'status' => $line->status,
                'receipt' => $this->receipt($transaction),
            ];
        });

        return $scheduled->concat($additional)->values()->all();
    }

    private function workOrder($workOrder, bool $includeFinancials): array
    {
        return [
            'id' => $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'title' => $workOrder->title,
            'priority' => $workOrder->priority,
            'status' => $workOrder->status,
            'vendor' => $this->customer($workOrder->vendor),
            'service_charge' => $includeFinancials ? $workOrder->service_charge : null,
            'opened_at' => $workOrder->opened_at?->toIso8601String(),
            'completed_at' => $workOrder->completed_at?->toIso8601String(),
            'payments' => $includeFinancials ? $workOrder->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'direction' => $payment->direction,
                'category' => $payment->category,
                'particulars' => $payment->particulars,
                'amount' => $payment->amount,
                'status' => $payment->status,
                'payment_mode' => $payment->payment_mode,
                'receipt' => $this->receipt($payment->status === 'paid' ? $payment->accountTransaction : null),
            ])->values()->all() : [],
        ];
    }

    private function customer($customer): ?array
    {
        return $customer ? ['id' => $customer->id, 'customer_code' => $customer->customer_code, 'display_name' => $customer->display_name] : null;
    }

    private function receipt(?AccountTransaction $transaction): ?array
    {
        return $transaction && $transaction->status?->value === 'posted'
            ? ['id' => $transaction->id, 'document_no' => $transaction->document_no, 'direction' => $transaction->direction?->value]
            : null;
    }
}
