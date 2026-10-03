<?php

namespace App\Services\Zaakiy\Skills;

use App\Enums\PropertyType;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\EntityResolver;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class VacancyAnalysisSkill implements ZaakiyReadSkill
{
    private const ACTIVE_STATUSES = ['approved', 'commenced', 'on_hold'];

    private const MAX_RECORDS = 25;

    public function __construct(private readonly EntityResolver $entities) {}

    public function supports(IntentFrame $intent): bool
    {
        return in_array($intent->intent, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true)
            || in_array('vacancy_analysis', $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $today = isset($context->intent->filters['as_of'])
            ? CarbonImmutable::parse($context->intent->filters['as_of'], config('app.timezone'))->startOfDay()
            : CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $range = $context->intent->timeRange;
        $ownerIds = collect($context->conversation?->entities ?? [])->filter(fn (array $entity): bool => ($entity['type'] ?? null) === 'owner')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($ownerIds === [] && preg_match('/\bowner\b|\'s\s+properties\b/i', $context->intent->question) === 1) {
            $owners = $this->entities->resolveOwners($context->intent->question, $context);
            if (count($owners) !== 1) {
                return new ZaakiySkillResult(
                    intent: $context->intent->intent,
                    subject: 'Property vacancy',
                    warnings: [[
                        'code' => $owners === [] ? 'OWNER_NOT_FOUND' : 'OWNER_AMBIGUOUS',
                        'message' => $owners === [] ? 'No authorized owner matched the request.' : 'More than one authorized owner matched; no owner was selected.',
                    ]],
                    meta: ['result_type' => 'vacancy_analysis', 'branch_scoped' => true],
                );
            }
            $ownerIds = [(int) $owners[0]->id];
        }
        $properties = $this->properties($context, $today, $context->intent->filters, $ownerIds);
        $rows = $properties->map(fn ($property): array => $this->propertyRecord($property, $today))->values()->all();
        $mode = $context->intent->intent;

        if ($mode === 'upcoming_vacancy' || $this->hasUpcomingRange($context->intent->question)) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['next_vacancy_date'] !== null
                && $row['next_vacancy_date'] >= ($range['from'] ?? $today->toDateString())
                && $row['next_vacancy_date'] <= ($range['to'] ?? $today->addDays(30)->toDateString())
                && $row['occupancy_status'] !== 'future_occupied'
                && ($row['next_tenant_agreement_start'] === null || $row['next_tenant_agreement_start'] > CarbonImmutable::parse($row['next_vacancy_date'])->addDay()->toDateString())));
        } elseif (in_array($mode, ['vacant_properties', 'property_availability', 'vacancy_analysis'], true)) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['occupancy_status'] === 'vacant'));
            if ($mode === 'property_availability') {
                $rows = array_values(array_filter($rows, fn (array $row): bool => $row['available_for_leasing']));
            }
            if (($context->intent->filters['maintenance'] ?? false) === true) {
                $rows = array_values(array_filter($rows, fn (array $row): bool => $row['open_work_order_count'] > 0));
            }
        }

        if (($context->intent->filters['longest_vacant'] ?? false) === true) {
            usort($rows, fn (array $a, array $b): int => [
                $b['vacancy_days'] === null ? -1 : $b['vacancy_days'],
                $a['property_code'],
            ] <=> [
                $a['vacancy_days'] === null ? -1 : $a['vacancy_days'],
                $b['property_code'],
            ]);
        } else {
            usort($rows, fn (array $a, array $b): int => $a['property_code'] <=> $b['property_code']);
        }

        $allRows = $this->properties($context, $today, $context->intent->filters, $ownerIds)
            ->map(fn ($property): array => $this->propertyRecord($property, $today))->values()->all();
        $eligible = count($allRows);
        $occupied = count(array_filter($allRows, fn (array $row): bool => $row['occupancy_status'] === 'occupied'));
        $future = count(array_filter($allRows, fn (array $row): bool => $row['occupancy_status'] === 'future_occupied'));
        $vacant = count(array_filter($allRows, fn (array $row): bool => $row['occupancy_status'] === 'vacant'));
        $metrics = [
            'eligible_property_count' => $eligible,
            'occupied_property_count' => $occupied,
            'vacant_property_count' => $vacant,
            'future_occupied_property_count' => $future,
            'occupancy_rate' => $eligible > 0 ? round(($occupied / $eligible) * 100, 2) : 0,
            'vacancy_rate' => $eligible > 0 ? round(($vacant / $eligible) * 100, 2) : 0,
        ];
        if ($mode === 'upcoming_vacancy') {
            $metrics['upcoming_vacancy_count'] = count($rows);
        }
        $maintenanceRows = array_filter($allRows, fn (array $row): bool => $row['occupancy_status'] === 'vacant' && $row['open_work_order_count'] > 0);
        $metrics['vacant_properties_with_open_work_orders'] = count($maintenanceRows);
        $metrics['open_work_orders_on_vacant_properties'] = array_sum(array_map(fn (array $row): int => $row['open_work_order_count'], $maintenanceRows));

        $typeCounts = [];
        $ownerCounts = [];
        foreach ($allRows as $row) {
            $type = $row['property_type'];
            $typeCounts[$type] ??= ['property_type' => $type, 'total' => 0, 'occupied' => 0, 'vacant' => 0];
            $typeCounts[$type]['total']++;
            $typeCounts[$type][$row['occupancy_status'] === 'occupied' ? 'occupied' : 'vacant']++;
            $ownerKey = (string) ($row['owner_id'] ?? '');
            $ownerCounts[$ownerKey] ??= ['type' => 'owner_vacancy_summary', 'owner_id' => $row['owner_id'], 'owner' => $row['owner']['display_name'], 'total_property_count' => 0, 'occupied_property_count' => 0, 'vacant_property_count' => 0];
            $ownerCounts[$ownerKey]['total_property_count']++;
            $ownerCounts[$ownerKey][$row['occupancy_status'] === 'occupied' ? 'occupied_property_count' : 'vacant_property_count']++;
        }
        $duration = [];
        foreach ($allRows as $row) {
            $bucket = $row['vacancy_days'] === null ? 'unknown' : match (true) {
                $row['vacancy_days'] <= 30 => '0-30',
                $row['vacancy_days'] <= 60 => '31-60',
                $row['vacancy_days'] <= 90 => '61-90',
                default => '90+',
            };
            $duration[$bucket] = ($duration[$bucket] ?? 0) + 1;
        }

        $sources = [];
        foreach (array_slice($rows, 0, self::MAX_RECORDS) as $row) {
            $sources[] = ['type' => 'property', 'id' => $row['id'], 'label' => $row['property_code']];
            if ($row['owner_id'] && ($row['owner']['customer_code'] ?? null)) {
                $sources[] = ['type' => 'customer', 'id' => $row['owner_id'], 'label' => $row['owner']['customer_code']];
            }
        }

        $records = array_slice($rows, 0, self::MAX_RECORDS);

        return new ZaakiySkillResult(
            intent: $mode,
            subject: $mode === 'occupancy_summary' ? 'Property occupancy' : 'Property vacancy',
            summaryMetrics: $metrics,
            records: $records,
            breakdowns: [
                'occupancy_by_property_type' => array_values($typeCounts),
                'vacancy_duration' => array_map(fn (string $bucket, int $count): array => ['bucket' => $bucket, 'property_count' => $count], array_keys($duration), $duration),
                'vacancy_by_owner' => array_values(array_filter($ownerCounts, fn (array $row): bool => $row['vacant_property_count'] > 0)),
            ],
            sources: $sources,
            navigation: [['label' => 'View properties', 'route' => '/app/properties', 'query' => []]],
            suggestedFollowups: $this->suggestions(),
            timeRange: $range,
            meta: [
                'result_type' => 'vacancy_analysis',
                'branch_scoped' => true,
                'property_level' => true,
                'deterministic' => true,
                'read_only' => true,
                'record_count' => count($records),
                'truncated' => count($rows) > self::MAX_RECORDS,
            ],
        );
    }

    private function properties(ZaakiyExecutionContext $context, CarbonImmutable $today, array $filters, array $ownerIds)
    {
        $branchId = $context->branchId();
        $query = DB::table('properties as properties')
            ->leftJoin('customers as owners', function ($join) use ($branchId): void {
                $join->on('owners.id', '=', 'properties.owner_customer_id')->where('owners.branch_id', $branchId);
            })
            ->where('properties.branch_id', $branchId)
            ->where('properties.status', 'active')
            ->whereNull('properties.deleted_at')
            ->whereNull('owners.deleted_at')
            ->select('properties.*', 'owners.customer_code as owner_code', 'owners.display_name as owner_name')
            ->selectSub(fn ($sub) => $sub->from('tenant_agreement_properties as links')
                ->join('tenant_agreements as agreements', 'agreements.id', '=', 'links.tenant_agreement_id')
                ->whereColumn('links.property_id', 'properties.id')->where('links.branch_id', $branchId)->where('agreements.branch_id', $branchId)
                ->whereIn('agreements.status', self::ACTIVE_STATUSES)->where('agreements.start_date', '<=', $today->toDateString())
                ->where('agreements.end_date', '>=', $today->toDateString())->orderBy('agreements.start_date')->limit(1)->select('agreements.id'), 'current_tenant_id')
            ->selectSub(fn ($sub) => $sub->from('tenant_agreement_properties as links')
                ->join('tenant_agreements as agreements', 'agreements.id', '=', 'links.tenant_agreement_id')
                ->whereColumn('links.property_id', 'properties.id')->where('links.branch_id', $branchId)->where('agreements.branch_id', $branchId)
                ->whereIn('agreements.status', self::ACTIVE_STATUSES)->where('agreements.start_date', '<=', $today->toDateString())
                ->where('agreements.end_date', '>=', $today->toDateString())->orderBy('agreements.start_date')->limit(1)->select('agreements.end_date'), 'current_tenant_end')
            ->selectSub(fn ($sub) => $sub->from('tenant_agreement_properties as links')
                ->join('tenant_agreements as agreements', 'agreements.id', '=', 'links.tenant_agreement_id')
                ->whereColumn('links.property_id', 'properties.id')->where('links.branch_id', $branchId)->where('agreements.branch_id', $branchId)
                ->whereIn('agreements.status', self::ACTIVE_STATUSES)->where('agreements.start_date', '>', $today->toDateString())
                ->orderBy('agreements.start_date')->limit(1)->select('agreements.start_date'), 'next_tenant_start')
            ->selectSub(fn ($sub) => $sub->from('tenant_agreement_properties as links')
                ->join('tenant_agreements as agreements', 'agreements.id', '=', 'links.tenant_agreement_id')
                ->whereColumn('links.property_id', 'properties.id')->where('links.branch_id', $branchId)->where('agreements.branch_id', $branchId)
                ->whereNotIn('agreements.status', ['draft', 'cancelled', 'terminated'])->where('agreements.end_date', '<', $today->toDateString())
                ->orderByDesc('agreements.end_date')->limit(1)->select('agreements.end_date'), 'last_tenant_end')
            ->selectSub(fn ($sub) => $sub->from('work_orders as work_orders')
                ->whereColumn('work_orders.property_id', 'properties.id')->where('work_orders.branch_id', $branchId)
                ->whereNotIn('work_orders.status', ['completed', 'cancelled'])->selectRaw('COUNT(*)'), 'open_work_order_count')
            ->selectSub(fn ($sub) => $sub->from('owner_agreement_properties as links')
                ->join('owner_agreements as agreements', 'agreements.id', '=', 'links.owner_agreement_id')
                ->whereColumn('links.property_id', 'properties.id')->where('links.branch_id', $branchId)->where('agreements.branch_id', $branchId)
                ->whereIn('agreements.status', self::ACTIVE_STATUSES)->where('agreements.start_date', '<=', $today->toDateString())
                ->where('agreements.end_date', '>=', $today->toDateString())->selectRaw('COUNT(*)'), 'owner_coverage_count');

        if ($today->lt(CarbonImmutable::now(config('app.timezone'))->startOfDay())) {
            $query->whereDate('properties.created_at', '<=', $today->toDateString());
        }

        $type = $filters['property_type'] ?? null;
        if ($type !== null && in_array($type, array_column(PropertyType::cases(), 'value'), true)) {
            $query->where('properties.property_type', $type);
        }
        if ($ownerIds !== []) {
            $query->whereIn('properties.owner_customer_id', $ownerIds);
        }

        return $query->get();
    }

    private function propertyRecord($property, CarbonImmutable $today): array
    {
        $status = $property->current_tenant_id !== null ? 'occupied' : ($property->next_tenant_start !== null ? 'future_occupied' : 'vacant');
        $vacantSince = $status === 'vacant' ? $property->last_tenant_end : null;
        $days = $vacantSince ? (int) max(0, CarbonImmutable::parse($vacantSince, config('app.timezone'))->startOfDay()->diffInDays($today)) : null;

        return [
            'type' => 'property',
            'id' => (int) $property->id,
            'label' => $property->property_code,
            'property_code' => $property->property_code,
            'unit_number' => $property->unit_number,
            'name' => $property->name,
            'property_type' => $property->property_type,
            'owner_id' => (int) $property->owner_customer_id,
            'owner' => ['id' => $property->owner_customer_id, 'customer_code' => $property->owner_code, 'display_name' => $property->owner_name],
            'occupancy_status' => $status,
            'vacant_since' => $vacantSince,
            'vacancy_days' => $days,
            'next_tenant_agreement_start' => $property->next_tenant_start,
            'next_vacancy_date' => $property->current_tenant_end,
            'open_work_order_count' => (int) $property->open_work_order_count,
            'available_for_leasing' => $status === 'vacant' && (int) $property->owner_coverage_count > 0,
        ];
    }

    private function hasUpcomingRange(string $question): bool
    {
        return preg_match('/\b(?:become|becomes|expected|available)\b.*\b(?:next|month|days?|week)\b/i', $question) === 1;
    }

    private function suggestions(): array
    {
        return ['Which properties have been vacant longest?', 'Show vacant apartments.', 'Which vacant properties have maintenance issues?', 'Which properties become vacant next month?', 'Tell me about the first property.'];
    }
}
