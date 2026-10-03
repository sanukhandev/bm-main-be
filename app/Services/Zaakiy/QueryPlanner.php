<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiyCapability;
use App\Services\Zaakiy\DTOs\ZaakiyConversationContext;
use App\Services\Zaakiy\DTOs\ZaakiyQueryPlan;
use App\Services\Zaakiy\DTOs\ZaakiyQueryPlanStep;

final class QueryPlanner
{
    private const OPERATIONS = ['read', 'compare', 'trend', 'explain', 'anomaly', 'briefing'];

    private const MAX_STEPS = 8;

    private const MAX_CAPABILITIES = 3;

    public function __construct(private readonly CapabilityRegistry $registry) {}

    public function plan(IntentFrame $intent, ?ZaakiyConversationContext $context = null): ZaakiyQueryPlan
    {
        $compoundCapabilities = $this->compoundCapabilities($intent);
        if (count($compoundCapabilities) > 1) {
            return $this->compoundPlan($intent, $compoundCapabilities);
        }
        $capability = $this->capability($intent);
        if ($capability === null) {
            return $this->invalid('CAPABILITY_UNSUPPORTED', 'No registered read capability matches the resolved intent.');
        }

        $operation = $this->operation($intent, $capability);
        $warnings = [];
        if (! in_array($operation, self::OPERATIONS, true)) {
            $warnings[] = $this->warning('FEATURE_UNSUPPORTED', 'The requested operation is not supported.');
        }
        if ($this->isWriteRequest($intent->question)) {
            $warnings[] = $this->warning('WRITE_ACTION_UNSUPPORTED', 'Zaakiy planning is read-only.');
        }
        if ($this->isCompound($intent, $capability)) {
            $warnings[] = $this->warning('COMPOUND_QUERY_REQUIRED', 'This request requires more than one independent capability.');
        }

        $metric = $this->metric($intent, $context);
        $metricDefinition = $metric ? $capability->metrics[$metric] ?? null : null;
        if ($metric !== null && $metricDefinition === null) {
            $canonical = $this->registry->forMetric($metric);
            if ($canonical !== [] && $canonical[0]->id !== $capability->id) {
                $warnings[] = $this->warning('METRIC_OWNERSHIP_MISMATCH', 'The metric is owned by another registered capability.', ['provider' => $canonical[0]->id]);
            } else {
                $warnings[] = $this->warning('METRIC_UNSUPPORTED', 'The requested metric is not registered for this capability.');
            }
        }

        foreach ($this->filters($intent->filters) as $filter => $value) {
            if (! $capability->supportsFilter($filter)) {
                $warnings[] = $this->warning('FILTER_UNSUPPORTED', 'The requested filter is not supported by this capability.', ['filter' => $filter]);

                continue;
            }
            $definition = $capability->filters[$filter];
            if ($definition->allowedValues !== [] && is_string($value) && ! in_array($value, $definition->allowedValues, true)) {
                $warnings[] = $this->warning('FILTER_UNSUPPORTED', 'The requested filter value is not supported.', ['filter' => $filter]);
            }
        }

        $entity = $this->entity($intent, $context, $capability);
        if ($capability->entities !== [] && str_ends_with($capability->id, '_360') && $entity === []) {
            $warnings[] = $this->warning('ENTITY_REQUIRED', 'This capability requires an authorized entity reference.');
        }

        if ($metricDefinition !== null) {
            $feature = match ($operation) {
                'compare' => $metricDefinition->comparison,
                'trend' => $metricDefinition->trend,
                'explain' => $metricDefinition->explanation,
                'anomaly' => $metricDefinition->anomaly,
                default => true,
            };
            if (! $feature) {
                $code = in_array('HISTORICAL_SNAPSHOT_UNAVAILABLE', $metricDefinition->warnings, true)
                    ? 'HISTORICAL_SNAPSHOT_UNAVAILABLE'
                    : 'CAPABILITY_FEATURE_UNSUPPORTED';
                $warnings[] = $this->warning($code, 'The requested analytical operation is not supported for this metric.', ['metric' => $metric, 'operation' => $operation]);
            }
            if (in_array($operation, ['compare', 'explain'], true) && $intent->comparisonPeriod === null) {
                $warnings[] = $this->warning('TIME_RANGE_REQUIRED', 'A comparison range is required for this operation.');
            }
            if ($operation === 'trend' && $this->futureOnly($intent->timeRange) && ! $metricDefinition->futureSupported) {
                $warnings[] = $this->warning('FUTURE_METRIC_UNSUPPORTED', 'This metric cannot be evaluated for a future range.');
            }
        }
        if ($operation === 'trend') {
            $granularity = $intent->filters['trend_granularity'] ?? 'auto';
            if ($granularity !== 'auto' && ! in_array($granularity, ['day', 'week', 'month', 'quarter'], true)) {
                $warnings[] = $this->warning('FEATURE_UNSUPPORTED', 'The requested trend granularity is unsupported.');
            }
        } else {
            $granularity = null;
        }

        $steps = $this->steps($operation, $capability, $metric, $intent);
        if (count($steps) > self::MAX_STEPS) {
            $warnings[] = $this->warning('PLAN_TOO_COMPLEX', 'The requested plan exceeds the supported step bound.');
        }

        return new ZaakiyQueryPlan(
            valid: $warnings === [],
            capability: $capability->id,
            domain: $capability->domain,
            operation: $operation,
            metric: $metric,
            filters: $this->filters($intent->filters),
            entity: $entity,
            timeRange: $intent->timeRange,
            comparisonRange: $intent->comparisonPeriod,
            granularity: $granularity,
            steps: $steps,
            warnings: $warnings,
            meta: ['read_only' => $capability->readOnly, 'composite' => $capability->composite, 'required_permissions' => $capability->requiredPermissions, 'max_steps' => self::MAX_STEPS],
        );
    }

    /** @param array<int, ZaakiyCapability> $capabilities */
    private function compoundPlan(IntentFrame $intent, array $capabilities): ZaakiyQueryPlan
    {
        $warnings = [];
        if (count($capabilities) > self::MAX_CAPABILITIES) {
            return $this->invalid('PLAN_TOO_COMPLEX', 'The request exceeds the supported compound capability bound.');
        }
        if ($this->isWriteRequest($intent->question)) {
            $warnings[] = $this->warning('WRITE_ACTION_UNSUPPORTED', 'Zaakiy planning is read-only.');
        }
        $strategy = $this->mergeStrategy($intent->question);
        $correlation = $strategy === 'parallel_sections' ? null : $this->correlationType($intent->question);
        if ($strategy !== 'parallel_sections' && $correlation === null) {
            $warnings[] = $this->warning('COMPOUND_QUERY_REQUIRED', 'The requested intersection has no supported semantic reference type.');
        }
        $operation = $this->childOperation($intent);
        if ($operation === 'explain') {
            $warnings[] = $this->warning('PLAN_TOO_COMPLEX', 'Compound explanations are limited to one capability.');
        }
        $steps = [];
        foreach ($capabilities as $index => $capability) {
            $metric = $this->metricForCapability($intent, $capability->id);
            $filters = $this->filtersForCapability($intent->filters, $capability);
            if ($operation !== 'briefing' && $metric !== null && ! isset($capability->metrics[$metric])) {
                $warnings[] = $this->warning('METRIC_OWNERSHIP_MISMATCH', 'A compound metric is not owned by the selected capability.', ['capability' => $capability->id, 'metric' => $metric]);
            }
            if (count($steps) >= self::MAX_STEPS - 1) {
                $warnings[] = $this->warning('PLAN_TOO_COMPLEX', 'The compound plan exceeds the step bound.');
                break;
            }
            $steps[] = new ZaakiyQueryPlanStep(
                'domain_'.$index,
                'compound_domain',
                $capability->id,
                $metric,
                input: ['filters' => $filters, 'time_range' => $intent->timeRange, 'comparison_range' => $intent->comparisonPeriod],
                outputKey: $capability->id,
                meta: ['operation' => $operation],
            );
        }
        $steps[] = new ZaakiyQueryPlanStep('merge', 'merge', 'compound', dependsOn: array_map(fn (int $index): string => 'domain_'.$index, range(0, count($steps) - 1)), input: ['strategy' => $strategy, 'correlation' => $correlation], outputKey: 'result');

        return new ZaakiyQueryPlan(
            valid: $warnings === [],
            capability: 'compound',
            domain: 'compound',
            operation: 'compound',
            filters: $intent->filters,
            timeRange: $intent->timeRange,
            comparisonRange: $intent->comparisonPeriod,
            granularity: $intent->filters['trend_granularity'] ?? null,
            steps: $steps,
            warnings: $warnings,
            meta: ['read_only' => true, 'max_steps' => self::MAX_STEPS, 'max_capabilities' => self::MAX_CAPABILITIES],
            capabilities: array_map(fn (ZaakiyCapability $capability): string => $capability->id, $capabilities),
            mergeStrategy: $strategy,
            correlation: $correlation,
        );
    }

    public function planDrilldown(string $fromCapability, string $referenceType, IntentFrame $intent, ?ZaakiyConversationContext $context = null): ZaakiyQueryPlan
    {
        $source = $this->registry->skill($fromCapability);
        if ($source === null) {
            return $this->invalid('CAPABILITY_UNSUPPORTED', 'The source capability is not registered.');
        }
        foreach ($source->drilldowns as $target) {
            $candidate = $this->registry->skill($target);
            if ($candidate && in_array($referenceType, $candidate->entities, true)) {
                return $this->plan($intent, $context);
            }
        }

        return $this->invalid('DRILLDOWN_UNSUPPORTED', 'No registered drill-down accepts this reference type.');
    }

    private function capability(IntentFrame $intent): ?ZaakiyCapability
    {
        $matches = $this->registry->forIntent($intent->intent);

        return $matches[0] ?? null;
    }

    private function operation(IntentFrame $intent, ZaakiyCapability $capability): string
    {
        if ($capability->composite) {
            return 'briefing';
        }
        if ($intent->anomalyRequested) {
            return 'anomaly';
        }
        if ($intent->explanationRequested) {
            return 'explain';
        }
        if ($intent->comparisonRequested) {
            return 'compare';
        }
        if (($intent->filters['trend'] ?? false) === true) {
            return 'trend';
        }

        return 'read';
    }

    private function childOperation(IntentFrame $intent): string
    {
        return match (true) {
            $intent->anomalyRequested => 'anomaly',
            $intent->explanationRequested => 'explain',
            $intent->comparisonRequested => 'compare',
            ($intent->filters['trend'] ?? false) === true => 'trend',
            default => 'read',
        };
    }

    /** @return array<int, ZaakiyCapability> */
    private function compoundCapabilities(IntentFrame $intent): array
    {
        if ($intent->intent === 'management_briefing' || preg_match('/management briefing|executive summary|business summary|branch summary/i', $intent->question) === 1) {
            return [];
        }
        if (preg_match('/vacan(?:t|cy).*maintenance|maintenance.*vacan(?:t|cy)/i', $intent->question) === 1 && ! preg_match('/collections?|renew|agreement|occupancy.*and/i', $intent->question)) {
            return [];
        }
        $ids = [];
        foreach ($intent->modules as $module) {
            if ($this->registry->skill($module) !== null) {
                $ids[] = $module;
            }
        }
        $question = $intent->question;
        $tokens = [
            'collections_health' => '/collections?|collected|overdue|outstanding|owe(?:s|d)?/i',
            'vacancy_analysis' => '/vacan(?:t|cy)|occup(?:ied|ancy)|available/i',
            'maintenance_intelligence' => '/maintenance|work orders?|repairs?/i',
            'renewal_intelligence' => '/renewals?|renewing/i',
            'agreement_risk' => '/agreements?.*(?:attention|expir|coverage)|expiring agreements?/i',
        ];
        foreach ($tokens as $id => $pattern) {
            if (preg_match($pattern, $question) === 1) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) <= 1) {
            return [];
        }

        return array_values(array_filter(array_map(fn (string $id): ?ZaakiyCapability => $this->registry->skill($id), array_slice($ids, 0, self::MAX_CAPABILITIES))));
    }

    private function mergeStrategy(string $question): string
    {
        return preg_match('/\bboth\b|in both|each.*and|ones?.*and|with .* and .*expir|owe.*and.*expir/i', $question) === 1
            ? (preg_match('/maintenance.*(?:their|linked|related).*properties|agreements?.*maintenance/i', $question) === 1 ? 'enrich_by_reference' : 'intersection_by_reference')
            : 'parallel_sections';
    }

    private function correlationType(string $question): ?string
    {
        return preg_match('/tenant|owe|overdue tenants?/i', $question) === 1 ? 'tenant'
            : (preg_match('/owner/i', $question) === 1 ? 'owner'
                : (preg_match('/propert|vacan|maintenance/i', $question) === 1 ? 'property' : null));
    }

    private function metricForCapability(IntentFrame $intent, string $capability): ?string
    {
        return match ($capability) {
            'collections_health' => preg_match('/overdue|outstanding|owe/i', $intent->question) === 1 ? 'collections.overdue_amount' : 'collections.collected_amount',
            'vacancy_analysis' => preg_match('/occupancy rate|occupancy/i', $intent->question) === 1 ? 'properties.occupancy_rate' : 'properties.vacant_count',
            'maintenance_intelligence' => preg_match('/completed/i', $intent->question) === 1 ? 'maintenance.completed_work_order_count' : 'maintenance.open_work_order_count',
            'renewal_intelligence' => 'agreements.renewal_candidate_count',
            'agreement_risk' => 'agreements.attention_count',
            default => null,
        };
    }

    private function filtersForCapability(array $filters, ZaakiyCapability $capability): array
    {
        $result = [];
        foreach ($this->filters($filters) as $filter => $value) {
            if ($capability->supportsFilter($filter)) {
                $result[$filter] = $value;
            }
        }

        return $result;
    }

    private function metric(IntentFrame $intent, ?ZaakiyConversationContext $context): ?string
    {
        $candidate = $context?->metric ?? $intent->metrics[0] ?? null;
        if (is_string($candidate) && str_contains($candidate, '.')) {
            return $candidate;
        }

        return match ($intent->intent) {
            'collections_health', 'collections_summary' => 'collections.collected_amount',
            'outstanding_receivables' => 'collections.outstanding_amount',
            'overdue_receivables' => 'collections.overdue_amount',
            'occupancy_summary' => 'properties.occupancy_rate',
            'vacancy_analysis', 'vacant_properties', 'property_availability', 'upcoming_vacancy' => 'properties.vacant_count',
            'maintenance_completion' => 'maintenance.completed_work_order_count',
            'maintenance_intelligence', 'maintenance_backlog', 'maintenance_aging', 'maintenance_priority', 'maintenance_property_summary' => 'maintenance.open_work_order_count',
            'renewal_intelligence' => 'agreements.renewal_candidate_count',
            'agreement_expiry' => 'agreements.expiring_count',
            'agreement_risk', 'agreement_attention', 'agreement_financial_attention' => 'agreements.attention_count',
            default => null,
        };
    }

    private function entity(IntentFrame $intent, ?ZaakiyConversationContext $context, ZaakiyCapability $capability): array
    {
        foreach ($context?->entities ?? [] as $entity) {
            if (is_array($entity) && isset($entity['type']) && in_array($this->normalizeEntity((string) $entity['type']), $capability->entities, true)) {
                return $entity;
            }
        }
        foreach ($intent->entities as $entity) {
            if (is_string($entity) && in_array($this->normalizeEntity($entity), $capability->entities, true)) {
                return ['type' => $this->normalizeEntity($entity)];
            }
        }

        return [];
    }

    private function normalizeEntity(string $entity): string
    {
        return match ($entity) {
            'tenant_agreement', 'owner_agreement' => 'agreement',
            'customer' => 'tenant',
            default => $entity,
        };
    }

    private function filters(array $filters): array
    {
        return array_filter($filters, fn ($value, $key): bool => ! in_array($key, ['trend', 'trend_granularity', 'as_of'], true), ARRAY_FILTER_USE_BOTH);
    }

    private function steps(string $operation, ZaakiyCapability $capability, ?string $metric, IntentFrame $intent): array
    {
        $input = ['filters' => $this->filters($intent->filters), 'time_range' => $intent->timeRange, 'comparison_range' => $intent->comparisonPeriod];
        if ($operation === 'read' || $operation === 'briefing' || $operation === 'anomaly') {
            return [new ZaakiyQueryPlanStep('domain', 'domain_read', $capability->id, $metric, input: $input, outputKey: 'result')];
        }
        if ($operation === 'trend') {
            return [new ZaakiyQueryPlanStep('trend', 'trend', $capability->id, $metric, input: $input + ['granularity' => $intent->filters['trend_granularity'] ?? 'auto'], outputKey: 'trend')];
        }

        return [
            new ZaakiyQueryPlanStep('primary', 'domain_metric', $capability->id, $metric, input: ['range' => $intent->timeRange], outputKey: 'primary'),
            new ZaakiyQueryPlanStep('comparison', 'domain_metric', $capability->id, $metric, input: ['range' => $intent->comparisonPeriod], outputKey: 'comparison'),
            new ZaakiyQueryPlanStep('analysis', $operation, $capability->id, $metric, dependsOn: ['primary', 'comparison'], outputKey: $operation),
        ];
    }

    private function futureOnly(?array $range): bool
    {
        return $range !== null && $range['from'] > now(config('app.timezone'))->toDateString();
    }

    private function isCompound(IntentFrame $intent, ZaakiyCapability $capability): bool
    {
        if ($capability->composite) {
            return false;
        }

        return count(array_unique($intent->modules)) > 1 || preg_match('/collections?.*\band\b.*(?:vacan|occup|maintenance)|(?:vacan|occup).*\band\b.*collections?/i', $intent->question) === 1;
    }

    private function isWriteRequest(string $question): bool
    {
        return preg_match('/\b(?:create|update|approve|delete|post|void|assign|renew)\b/i', $question) === 1;
    }

    private function invalid(string $code, string $message, array $meta = []): ZaakiyQueryPlan
    {
        return new ZaakiyQueryPlan(false, warnings: [$this->warning($code, $message, $meta)], meta: ['read_only' => true, 'max_steps' => self::MAX_STEPS]);
    }

    private function warning(string $code, string $message, array $meta = []): array
    {
        return ['code' => $code, 'message' => $message, 'meta' => $meta];
    }
}
