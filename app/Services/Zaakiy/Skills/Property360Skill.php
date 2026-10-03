<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\OwnerAgreement;
use App\Models\Property;
use App\Models\TenantAgreement;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\EntityResolver;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use Illuminate\Support\Facades\DB;

final class Property360Skill implements ZaakiyReadSkill
{
    private const ACTIVE_OWNER_STATUSES = ['approved', 'commenced', 'on_hold'];

    private const ACTIVE_TENANT_STATUSES = ['approved', 'commenced', 'on_hold'];

    public function __construct(private readonly EntityResolver $entities) {}

    public function supports(IntentFrame $intent): bool
    {
        return $intent->intent === 'property_360' || in_array('property_360', $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $matches = $this->entities->resolveProperties($context->intent->question, $context);
        if (count($matches) !== 1) {
            return $this->unresolved($matches);
        }

        $property = $matches[0];
        $property->loadMissing('owner');
        $branchId = $context->branchId();
        $financial = $context->can('accounts.view');
        $tenant = $this->currentTenantAgreement($property, $branchId);
        $owner = $this->currentOwnerAgreement($property, $branchId);
        $workOrders = DB::table('work_orders')
            ->where('branch_id', $branchId)
            ->where('property_id', $property->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['id', 'work_order_no', 'title', 'priority', 'status', 'opened_at', 'created_at']);

        $metrics = [
            'occupancy_status' => $this->occupancyStatus($tenant),
            'open_work_order_count' => DB::table('work_orders')->where('branch_id', $branchId)->where('property_id', $property->id)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'high_priority_open_work_order_count' => DB::table('work_orders')->where('branch_id', $branchId)->where('property_id', $property->id)->whereIn('priority', ['high', 'urgent'])->whereNotIn('status', ['completed', 'cancelled'])->count(),
        ];
        $oldest = DB::table('work_orders')->where('branch_id', $branchId)->where('property_id', $property->id)->whereNotIn('status', ['completed', 'cancelled'])->orderBy('opened_at')->value('opened_at');
        if ($oldest !== null) {
            $metrics['oldest_open_work_order_date'] = $oldest;
        }

        $records = [
            $this->propertyRecord($property),
            $this->customerRecord('owner', $property->owner),
        ];
        $sources = [
            $this->source('property', $property->id, $property->property_code),
            $this->source('customer', $property->owner?->id, $property->owner?->customer_code),
        ];
        $navigation = [[
            'label' => 'View property',
            'route' => '/app/properties/'.$property->id,
            'query' => [],
        ]];
        $followups = [];
        $warnings = [];

        if ($tenant) {
            $followups[] = 'Show the tenant agreement.';
            $records[] = $this->agreementRecord($tenant, 'tenant', $financial);
            $sources[] = $this->source('tenant_agreement', $tenant->id, $tenant->agreement_no);
            $navigation[] = ['label' => 'View tenant agreement', 'route' => '/app/tenant-agreements/'.$tenant->id, 'query' => []];
            $metrics['days_until_tenant_agreement_expiry'] = $this->daysUntil($tenant->end_date);
            if ($financial) {
                $metrics += $this->financialMetrics('tenant_agreement_installments', 'tenant_agreement_id', $tenant->id, 'tenant_', $branchId);
                $this->appendNextInstallment($records, $tenant, 'tenant');
            }
        }
        if ($owner) {
            $followups[] = 'Show the owner agreement.';
            $records[] = $this->agreementRecord($owner, 'owner', $financial);
            $sources[] = $this->source('owner_agreement', $owner->id, $owner->agreement_no);
            $navigation[] = ['label' => 'View owner agreement', 'route' => '/app/owner-agreements/'.$owner->id, 'query' => []];
            $metrics['days_until_owner_agreement_expiry'] = $this->daysUntil($owner->end_date);
            if ($financial) {
                $metrics += $this->financialMetrics('owner_agreement_installments', 'owner_agreement_id', $owner->id, 'owner_', $branchId);
                $this->appendNextInstallment($records, $owner, 'owner');
            }
        }
        if ($workOrders->isNotEmpty()) {
            $followups[] = 'Show its maintenance issues.';
            foreach ($workOrders as $workOrder) {
                $records[] = ['type' => 'maintenance_work_order', 'id' => $workOrder->id, 'work_order_no' => $workOrder->work_order_no, 'title' => $workOrder->title, 'priority' => $workOrder->priority, 'status' => $workOrder->status, 'opened_at' => $workOrder->opened_at];
                $sources[] = $this->source('work_order', $workOrder->id, $workOrder->work_order_no);
            }
            $navigation[] = ['label' => 'View maintenance work orders', 'route' => '/app/maintenance/work-orders', 'query' => []];
        }
        if (! $financial) {
            $warnings[] = ['code' => 'FINANCIAL_DATA_RESTRICTED', 'message' => 'Financial information requires accounts.view.'];
            $followups[] = 'When does the agreement expire?';
        } else {
            if ($tenant || $owner) {
                $followups[] = 'Does this property have outstanding payments?';
            }
            if ($tenant && ($metrics['tenant_outstanding'] ?? 0) > 0) {
                $navigation[] = ['label' => 'View tenant outstanding', 'route' => '/app/reports/tenant-outstanding', 'query' => ['property_id' => $property->id]];
            }
        }

        return new ZaakiySkillResult(
            intent: 'property_360',
            subject: (string) ($property->name ?: $property->property_code),
            summaryMetrics: $metrics,
            records: $records,
            warnings: $warnings,
            sources: array_values(array_filter($sources, fn (?array $source): bool => $source !== null)),
            navigation: $navigation,
            suggestedFollowups: array_values(array_unique(array_slice($followups, 0, 5))),
            meta: ['result_type' => 'property_360', 'branch_scoped' => true, 'financial_included' => $financial, 'record_count' => count($records)],
        );
    }

    private function unresolved(array $matches): ZaakiySkillResult
    {
        $records = array_map(fn (Property $property): array => $this->propertyRecord($property), array_slice($matches, 0, 5));

        return new ZaakiySkillResult(
            intent: 'property_360',
            subject: 'Property summary',
            records: $records,
            warnings: [[
                'code' => $matches === [] ? 'PROPERTY_NOT_FOUND' : 'PROPERTY_AMBIGUOUS',
                'message' => $matches === [] ? 'No authorized property matched the request.' : 'More than one authorized property matched; no property was selected.',
            ]],
            meta: ['result_type' => 'property_360', 'branch_scoped' => true, 'record_count' => count($records)],
        );
    }

    private function currentTenantAgreement(Property $property, int $branchId): ?TenantAgreement
    {
        return TenantAgreement::query()->forBranch($branchId)->whereIn('status', self::ACTIVE_TENANT_STATUSES)
            ->whereDate('end_date', '>=', now()->toDateString())
            ->whereHas('properties', fn ($query) => $query->where('properties.id', $property->id)->where('tenant_agreement_properties.branch_id', $branchId))
            ->with('tenant')->orderBy('start_date')->first();
    }

    private function currentOwnerAgreement(Property $property, int $branchId): ?OwnerAgreement
    {
        return OwnerAgreement::query()->forBranch($branchId)->whereIn('status', self::ACTIVE_OWNER_STATUSES)
            ->whereDate('start_date', '<=', now()->toDateString())->whereDate('end_date', '>=', now()->toDateString())
            ->whereHas('properties', fn ($query) => $query->where('properties.id', $property->id)->where('owner_agreement_properties.branch_id', $branchId))
            ->with('owner')->latest('id')->first();
    }

    private function propertyRecord(Property $property): array
    {
        return array_filter(['type' => 'property', 'id' => $property->id, 'property_code' => $property->property_code, 'unit_number' => $property->unit_number, 'name' => $property->name, 'property_type' => $property->property_type?->value, 'status' => $property->status, 'building_name' => $property->building_name, 'address_line_1' => $property->address_line_1, 'address_line_2' => $property->address_line_2, 'city' => $property->city, 'state_or_emirate' => $property->state_or_emirate], static fn ($value) => $value !== null && $value !== '');
    }

    private function customerRecord(string $type, $customer): array
    {
        return ['type' => $type, 'id' => $customer?->id, 'customer_code' => $customer?->customer_code, 'display_name' => $customer?->display_name];
    }

    private function agreementRecord($agreement, string $type, bool $financial): array
    {
        $record = ['type' => $type.'_agreement', 'id' => $agreement->id, 'agreement_no' => $agreement->agreement_no, $type => ['id' => $agreement->{$type}?->id, 'customer_code' => $agreement->{$type}?->customer_code, 'display_name' => $agreement->{$type}?->display_name], 'start_date' => $agreement->start_date?->format('Y-m-d'), 'end_date' => $agreement->end_date?->format('Y-m-d'), 'status' => $agreement->status];
        if ($financial) {
            $record['total_amount'] = $agreement->total_amount;
        }

        return $record;
    }

    private function financialMetrics(string $table, string $foreign, int $agreementId, string $prefix, int $branchId): array
    {
        $query = DB::table($table)->where('branch_id', $branchId)->where($foreign, $agreementId)->whereColumn('paid_amount', '<', 'amount');
        $outstanding = (float) (clone $query)->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total');
        $overdue = (float) (clone $query)->whereDate('due_date', '<', now()->toDateString())->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total');

        return [$prefix.'outstanding' => number_format($outstanding, 2, '.', ''), $prefix.'overdue' => number_format($overdue, 2, '.', '')];
    }

    private function appendNextInstallment(array &$records, $agreement, string $type): void
    {
        $line = DB::table($type.'_agreement_installments')->where('branch_id', $agreement->branch_id)->where($type.'_agreement_id', $agreement->id)->whereColumn('paid_amount', '<', 'amount')->orderBy('due_date')->first(['id', 'installment_no', 'due_date', 'amount', 'paid_amount']);
        if ($line) {
            $records[] = ['type' => 'next_'.$type.'_installment', 'id' => $line->id, 'installment_no' => $line->installment_no, 'due_date' => $line->due_date, 'amount' => $line->amount, 'paid_amount' => $line->paid_amount, 'balance' => number_format((float) $line->amount - (float) $line->paid_amount, 2, '.', '')];
        }
    }

    private function daysUntil($date): int
    {
        return max(0, now()->startOfDay()->diffInDays($date, false));
    }

    private function occupancyStatus(?TenantAgreement $tenant): string
    {
        if ($tenant === null) {
            return 'vacant';
        }

        return $tenant->start_date?->isFuture() ? 'future_occupied' : 'occupied';
    }

    private function source(string $type, ?int $id, ?string $label): ?array
    {
        return $id && $label ? ['type' => $type, 'id' => $id, 'label' => $label] : null;
    }
}
