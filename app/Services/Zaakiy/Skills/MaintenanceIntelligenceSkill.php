<?php

namespace App\Services\Zaakiy\Skills;

use App\Enums\PropertyType;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\EntityResolver;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class MaintenanceIntelligenceSkill implements ZaakiyReadSkill
{
    private const CLOSED_STATUSES = ['completed', 'cancelled'];

    private const MAX_RECORDS = 25;

    public function __construct(private readonly EntityResolver $entities) {}

    public function supports(IntentFrame $intent): bool
    {
        return in_array($intent->intent, [
            'maintenance_intelligence', 'maintenance_backlog', 'maintenance_aging',
            'maintenance_priority', 'maintenance_completion', 'maintenance_property_summary',
        ], true) || in_array('maintenance_intelligence', $intent->modules, true);
    }

    public function explanationDrivers(int $branchId, array $currentRange, array $comparisonRange, array $filters = []): array
    {
        $totals = [];
        foreach (['current' => $currentRange, 'comparison' => $comparisonRange] as $side => $range) {
            $totals[$side] = DB::table('work_orders')
                ->join('properties', function ($join) use ($branchId): void {
                    $join->on('properties.id', '=', 'work_orders.property_id')->where('properties.branch_id', $branchId)->whereNull('properties.deleted_at');
                })
                ->where('work_orders.branch_id', $branchId)
                ->where('work_orders.status', 'completed')
                ->whereNotNull('work_orders.completed_at')
                ->whereBetween('work_orders.completed_at', [$range['from'].' 00:00:00', $range['to'].' 23:59:59'])
                ->when(isset($filters['property_type']), fn ($query) => $query->where('properties.property_type', $filters['property_type']))
                ->selectRaw('properties.id as property_id, properties.property_code, properties.name, COUNT(work_orders.id) as amount')
                ->groupBy('properties.id', 'properties.property_code', 'properties.name')
                ->get()->keyBy('property_id')->all();
        }
        $keys = array_unique([...array_keys($totals['current']), ...array_keys($totals['comparison'])]);

        return array_map(function ($key) use ($totals): array {
            $row = $totals['current'][$key] ?? $totals['comparison'][$key];

            return ['dimension' => 'property', 'key' => (string) $key, 'label' => $row->name ?: $row->property_code, 'current' => (int) ($totals['current'][$key]->amount ?? 0), 'comparison' => (int) ($totals['comparison'][$key]->amount ?? 0), 'references' => [['type' => 'property', 'id' => (int) $key, 'label' => $row->property_code]]];
        }, $keys);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $filters = $context->intent->filters;
        $ownerIds = collect($context->conversation?->entities ?? [])->filter(fn (array $e): bool => ($e['type'] ?? null) === 'owner')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($ownerIds === [] && preg_match('/\bowner\b|\'s\s+properties\b/i', $context->intent->question) === 1) {
            $owners = $this->entities->resolveOwners($context->intent->question, $context);
            if (count($owners) !== 1) {
                return new ZaakiySkillResult($context->intent->intent, 'Maintenance', warnings: [[
                    'code' => $owners === [] ? 'OWNER_NOT_FOUND' : 'OWNER_AMBIGUOUS',
                    'message' => $owners === [] ? 'No authorized owner matched the request.' : 'More than one authorized owner matched; no owner was selected.',
                ]], meta: ['result_type' => 'maintenance_intelligence', 'branch_scoped' => true]);
            }
            $ownerIds = [(int) $owners[0]->id];
        }

        $query = $this->openQuery($context, $filters, $ownerIds);
        $openCount = (clone $query)->count('work_orders.id');
        $propertyCount = (clone $query)->distinct()->count('work_orders.property_id');
        $highCount = (clone $query)->whereIn('work_orders.priority', ['high', 'urgent'])->count('work_orders.id');
        $oldest = (clone $query)->min(DB::raw('COALESCE(work_orders.opened_at, work_orders.created_at)'));
        $oldestAge = $oldest ? CarbonImmutable::parse($oldest, config('app.timezone'))->startOfDay()->diffInDays($today) : null;

        $mode = $context->intent->intent;
        $records = $mode === 'maintenance_property_summary' || preg_match('/properties?\s+(?:have|with|most)|maintenance backlog by property/i', $context->intent->question) === 1
            ? $this->propertyRecords($query)
            : $this->workOrderRecords($query, $today, $filters);
        $metrics = [
            'open_work_order_count' => $openCount,
            'properties_with_open_work_orders' => $propertyCount,
            'high_priority_open_count' => $highCount,
        ];
        if ($oldestAge !== null) {
            $metrics['oldest_open_age_days'] = $oldestAge;
        }

        $range = $context->intent->timeRange;
        if ($mode === 'maintenance_completion' || preg_match('/\bcompleted\b/i', $context->intent->question) === 1) {
            $range ??= ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString()];
            $completed = DB::table('work_orders')->where('branch_id', $context->branchId())->where('status', 'completed')->whereNotNull('completed_at')->whereBetween('completed_at', [$range['from'].' 00:00:00', $range['to'].' 23:59:59'])->count();
            $metrics = ['completed_work_order_count' => $completed];
            $records = [];
        }

        $warnings = [['code' => 'MAINTENANCE_OVERDUE_UNAVAILABLE', 'message' => 'Maintenance overdue status is unavailable because work orders have no authoritative due-date or SLA field.']];
        $breakdowns = ['status' => $this->breakdown($query, 'work_orders.status', 'status'), 'maintenance_aging' => $this->agingBreakdown($query, $today)];
        if ($highCount > 0) {
            $breakdowns['priority'] = $this->breakdown($query, 'work_orders.priority', 'priority');
        }

        $sources = [];
        foreach (array_slice($records, 0, self::MAX_RECORDS) as $record) {
            $sources[] = ['type' => $record['type'] === 'work_order' ? 'work_order' : 'property', 'id' => $record['id'], 'label' => $record['label']];
            if (! empty($record['property_id'])) {
                $sources[] = ['type' => 'property', 'id' => $record['property_id'], 'label' => $record['property_code']];
            }
        }

        return new ZaakiySkillResult(
            intent: $context->intent->intent,
            subject: 'Maintenance intelligence',
            summaryMetrics: $metrics,
            records: array_slice($records, 0, self::MAX_RECORDS),
            breakdowns: $breakdowns,
            warnings: $warnings,
            sources: $sources,
            navigation: [['label' => 'View maintenance', 'route' => '/app/maintenance/work-orders', 'query' => []]],
            suggestedFollowups: ['Show the oldest open work orders.', 'Show high-priority maintenance.', 'Which properties have the most open work orders.', 'How many work orders were completed this month.', 'Tell me about the first property.'],
            timeRange: $range,
            meta: ['result_type' => 'maintenance_intelligence', 'branch_scoped' => true, 'deterministic' => true, 'read_only' => true, 'overdue_available' => false, 'record_count' => min(count($records), self::MAX_RECORDS), 'truncated' => count($records) > self::MAX_RECORDS],
        );
    }

    private function openQuery(ZaakiyExecutionContext $context, array $filters, array $ownerIds): Builder
    {
        $branchId = $context->branchId();
        $query = DB::table('work_orders')
            ->join('properties', function ($join) use ($branchId): void {
                $join->on('properties.id', '=', 'work_orders.property_id')->where('properties.branch_id', $branchId)->whereNull('properties.deleted_at');
            })
            ->leftJoin('customers as owners', function ($join) use ($branchId): void {
                $join->on('owners.id', '=', 'properties.owner_customer_id')->where('owners.branch_id', $branchId)->whereNull('owners.deleted_at');
            })
            ->where('work_orders.branch_id', $branchId)
            ->whereNotIn('work_orders.status', self::CLOSED_STATUSES);
        if ($ownerIds !== []) {
            $query->whereIn('properties.owner_customer_id', $ownerIds);
        }
        if (($type = $filters['property_type'] ?? null) && in_array($type, array_column(PropertyType::cases(), 'value'), true)) {
            $query->where('properties.property_type', $type);
        }
        if ($priority = $filters['priority'] ?? null) {
            $query->where('work_orders.priority', $priority);
        }
        if (($filters['vacancy_status'] ?? null) === 'vacant') {
            $query->whereNotExists(fn ($q) => $this->currentTenantSubquery($q, $branchId));
        }
        if (($filters['occupancy_status'] ?? null) === 'occupied') {
            $query->whereExists(fn ($q) => $this->currentTenantSubquery($q, $branchId));
        }
        if (isset($filters['age_gt']) || isset($filters['age_gte'])) {
            $days = (int) ($filters['age_gt'] ?? $filters['age_gte']);
            $query->where(function ($q) use ($days): void {
                $date = CarbonImmutable::now(config('app.timezone'))->startOfDay()->subDays($days)->toDateString();
                $q->where(function ($q) use ($date): void {
                    $q->whereNotNull('work_orders.opened_at')->whereDate('work_orders.opened_at', '<', $date);
                })
                    ->orWhere(function ($q) use ($date): void {
                        $q->whereNull('work_orders.opened_at')->whereDate('work_orders.created_at', '<', $date);
                    });
            });
        }

        return $query;
    }

    private function currentTenantSubquery($query, int $branchId): void
    {
        $query->from('tenant_agreement_properties as links')->join('tenant_agreements as agreements', function ($join) use ($branchId): void {
            $join->on('agreements.id', '=', 'links.tenant_agreement_id')->where('agreements.branch_id', $branchId);
        })->whereColumn('links.property_id', 'work_orders.property_id')->where('links.branch_id', $branchId)->whereIn('agreements.status', ['approved', 'commenced', 'on_hold'])->whereDate('agreements.start_date', '<=', CarbonImmutable::now(config('app.timezone'))->toDateString())->whereDate('agreements.end_date', '>=', CarbonImmutable::now(config('app.timezone'))->toDateString());
    }

    private function workOrderRecords(Builder $query, CarbonImmutable $today, array $filters): array
    {
        $rows = (clone $query)->select(['work_orders.id', 'work_orders.work_order_no', 'work_orders.title', 'work_orders.status', 'work_orders.priority', 'work_orders.opened_at', 'work_orders.created_at', 'properties.id as property_id', 'properties.property_code', 'properties.name as property_name', 'properties.property_type', 'owners.id as owner_id', 'owners.customer_code as owner_code', 'owners.display_name as owner_name'])->orderByRaw('COALESCE(work_orders.opened_at, work_orders.created_at) asc')->orderBy('work_orders.work_order_no')->limit(self::MAX_RECORDS)->get();
        $records = $rows->map(function ($row) use ($today): array {
            $opened = $row->opened_at ?: $row->created_at;

            return ['type' => 'work_order', 'id' => (int) $row->id, 'label' => $row->work_order_no, 'work_order_no' => $row->work_order_no, 'title' => $row->title, 'status' => $row->status, 'priority' => $row->priority, 'opened_at' => $opened, 'age_days' => (int) CarbonImmutable::parse($opened, config('app.timezone'))->startOfDay()->diffInDays($today), 'property_id' => (int) $row->property_id, 'property_code' => $row->property_code, 'property' => $row->property_name, 'property_type' => $row->property_type, 'owner_id' => $row->owner_id ? (int) $row->owner_id : null, 'owner' => $row->owner_name];
        })->all();
        usort($records, fn (array $a, array $b): int => [$b['age_days'], $a['work_order_no']] <=> [$a['age_days'], $b['work_order_no']]);

        return $records;
    }

    private function propertyRecords(Builder $query): array
    {
        return (clone $query)->select(['properties.id', 'properties.property_code', 'properties.name as property_name', 'properties.property_type', 'properties.owner_customer_id as owner_id', 'owners.customer_code as owner_code', 'owners.display_name as owner_name'])->selectRaw('COUNT(work_orders.id) as open_work_order_count')->selectRaw("SUM(CASE WHEN work_orders.priority IN ('high', 'urgent') THEN 1 ELSE 0 END) as high_priority_open_count")->selectRaw('MIN(COALESCE(work_orders.opened_at, work_orders.created_at)) as oldest_opened_at')->groupBy('properties.id', 'properties.property_code', 'properties.name', 'properties.property_type', 'properties.owner_customer_id', 'owners.customer_code', 'owners.display_name')->orderByDesc('open_work_order_count')->orderBy('properties.property_code')->limit(self::MAX_RECORDS)->get()->map(fn ($row): array => ['type' => 'property', 'id' => (int) $row->id, 'label' => $row->property_code, 'property_id' => (int) $row->id, 'property_code' => $row->property_code, 'property' => $row->property_name, 'property_type' => $row->property_type, 'owner_id' => $row->owner_id ? (int) $row->owner_id : null, 'owner' => $row->owner_name, 'open_work_order_count' => (int) $row->open_work_order_count, 'high_priority_open_count' => (int) $row->high_priority_open_count, 'oldest_open_age_days' => $row->oldest_opened_at ? CarbonImmutable::parse($row->oldest_opened_at, config('app.timezone'))->startOfDay()->diffInDays(CarbonImmutable::now(config('app.timezone'))->startOfDay()) : null])->all();
    }

    private function breakdown(Builder $query, string $column, string $key): array
    {
        return (clone $query)->selectRaw("{$column} as value, COUNT(*) as count")->groupBy($column)->orderBy($column)->get()->map(fn ($row): array => [$key => $row->value, 'count' => (int) $row->count])->all();
    }

    private function agingBreakdown(Builder $query, CarbonImmutable $today): array
    {
        $buckets = [['0-7', 0, 7], ['8-14', 8, 14], ['15-30', 15, 30], ['31-60', 31, 60], ['60+', 61, null]];

        return array_map(function (array $bucket) use ($query, $today): array {
            [$label, $min, $max] = $bucket;
            $q = clone $query;
            $from = $today->copy()->subDays($max ?? 100000)->toDateString();
            $to = $today->copy()->subDays($min)->toDateString();
            $q->where(function ($q) use ($from, $to): void {
                $q->where(function ($q) use ($from, $to): void {
                    $q->whereNotNull('work_orders.opened_at')->whereDate('work_orders.opened_at', '>=', $from)->whereDate('work_orders.opened_at', '<=', $to);
                })
                    ->orWhere(function ($q) use ($from, $to): void {
                        $q->whereNull('work_orders.opened_at')->whereDate('work_orders.created_at', '>=', $from)->whereDate('work_orders.created_at', '<=', $to);
                    });
            });

            return ['bucket' => $label, 'count' => $q->count('work_orders.id')];
        }, $buckets);
    }
}
