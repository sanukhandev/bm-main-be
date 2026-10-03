<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiyCapability;
use App\Services\Zaakiy\DTOs\ZaakiyFilterCapability;
use App\Services\Zaakiy\DTOs\ZaakiyMetricCapability;
use LogicException;

final class CapabilityRegistry
{
    /** @return array<int, ZaakiyCapability> */
    public function all(): array
    {
        $money = ['accounts.view'];
        $propertyTypes = ['apartment', 'villa', 'shop', 'office', 'space', 'labor_camp', 'warehouse', 'land'];

        return [
            new ZaakiyCapability('property_360', 'Property360Skill', 'properties', 'Read-only property detail', ['property_360'], entities: ['property'], resultReferences: ['property', 'owner', 'tenant_agreement', 'work_order'], drilldowns: ['owner_360', 'agreement_360', 'tenant_360'], features: ['supports_navigation' => true, 'supports_followups' => true]),
            new ZaakiyCapability('tenant_360', 'Tenant360Skill', 'customers.tenants', 'Read-only tenant detail', ['tenant_360'], entities: ['tenant'], resultReferences: ['tenant', 'tenant_agreement', 'property', 'payment'], drilldowns: ['agreement_360', 'property_360'], optionalFeaturePermissions: ['financial' => $money], features: ['supports_navigation' => true, 'supports_followups' => true]),
            new ZaakiyCapability('owner_360', 'Owner360Skill', 'customers.owners', 'Read-only owner detail', ['owner_360'], entities: ['owner'], resultReferences: ['owner', 'owner_agreement', 'property', 'payment'], drilldowns: ['agreement_360', 'property_360'], optionalFeaturePermissions: ['financial' => $money], features: ['supports_navigation' => true, 'supports_followups' => true]),
            new ZaakiyCapability('agreement_360', 'Agreement360Skill', 'agreements', 'Read-only agreement detail', ['agreement_360'], entities: ['agreement'], resultReferences: ['agreement', 'tenant', 'owner', 'property', 'installment', 'payment'], drilldowns: ['tenant_360', 'owner_360', 'property_360'], optionalFeaturePermissions: ['financial' => $money], features: ['supports_navigation' => true, 'supports_followups' => true]),
            new ZaakiyCapability('collections_health', 'CollectionsHealthSkill', 'accounts', 'Read-only inward collections health', ['collections_health', 'collections_summary', 'outstanding_receivables', 'overdue_receivables', 'upcoming_collections', 'collection_cheques'], metrics: $this->metrics([
                $this->metric('collections.collected_amount', 'collections_health', 'AED', 'period', comparison: true, trend: true, explanation: true, anomaly: true, requiredPermissions: $money),
                $this->metric('collections.payment_count', 'collections_health', 'count', 'period', comparison: true, trend: true, requiredPermissions: $money),
                $this->metric('collections.unique_tenant_count', 'collections_health', 'count', 'period', comparison: true, trend: true, requiredPermissions: $money),
                $this->metric('collections.outstanding_amount', 'collections_health', 'AED', 'snapshot', requiredPermissions: $money, warnings: ['HISTORICAL_SNAPSHOT_UNAVAILABLE']),
                $this->metric('collections.overdue_amount', 'collections_health', 'AED', 'snapshot', requiredPermissions: $money, warnings: ['HISTORICAL_SNAPSHOT_UNAVAILABLE']),
            ]), filters: $this->filters(['tenant', 'agreement', 'property', 'threshold', 'time_range'], $money), time: ['supports_time_range' => true, 'supports_as_of' => false, 'supports_future_range' => true, 'supports_comparison_range' => true, 'supports_trend_range' => true], requiredPermissions: $money, entities: ['tenant', 'agreement', 'property'], resultReferences: ['tenant', 'agreement', 'property', 'payment'], drilldowns: ['tenant_360', 'agreement_360', 'property_360'], warnings: ['FINANCIAL_DATA_RESTRICTED']),
            new ZaakiyCapability('agreement_risk', 'AgreementRiskSkill', 'agreements', 'Deterministic agreement attention conditions', ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention'], metrics: $this->metrics([
                $this->metric('agreements.attention_count', 'agreement_risk', 'count', 'snapshot', anomaly: true, asOfSupported: true),
                $this->metric('agreements.expiring_count', 'agreement_risk', 'count', 'period', comparison: true, trend: true),
            ]), filters: $this->filters(['agreement_type', 'financial_state', 'coverage_issue', 'threshold', 'time_range'], $money), time: ['supports_time_range' => true, 'supports_as_of' => true, 'supports_future_range' => true, 'supports_comparison_range' => true, 'supports_trend_range' => true], optionalFeaturePermissions: ['financial' => $money], entities: ['agreement', 'tenant', 'owner', 'property'], resultReferences: ['agreement', 'tenant', 'owner', 'property'], drilldowns: ['agreement_360', 'tenant_360', 'owner_360', 'property_360'], warnings: ['FINANCIAL_DATA_RESTRICTED']),
            new ZaakiyCapability('renewal_intelligence', 'RenewalIntelligenceSkill', 'agreements', 'Deterministic renewal candidates', ['renewal_intelligence'], metrics: $this->metrics([
                $this->metric('agreements.renewal_candidate_count', 'renewal_intelligence', 'count', 'period', comparison: true, trend: true, futureSupported: true),
                $this->metric('agreements.tenant_renewal_count', 'renewal_intelligence', 'count', 'period', comparison: true, trend: true, futureSupported: true),
                $this->metric('agreements.owner_renewal_count', 'renewal_intelligence', 'count', 'period', comparison: true, trend: true, futureSupported: true),
            ]), filters: $this->filters(['agreement_type', 'financial_state', 'coverage_issue', 'occupied_properties_only', 'threshold', 'time_range'], $money), time: ['supports_time_range' => true, 'supports_as_of' => false, 'supports_future_range' => true, 'supports_comparison_range' => true, 'supports_trend_range' => true], optionalFeaturePermissions: ['financial' => $money], entities: ['agreement', 'tenant', 'owner', 'property'], resultReferences: ['agreement', 'tenant', 'owner', 'property'], drilldowns: ['agreement_360', 'tenant_360', 'owner_360', 'property_360'], warnings: ['FINANCIAL_DATA_RESTRICTED']),
            new ZaakiyCapability('vacancy_analysis', 'VacancyAnalysisSkill', 'properties', 'Property-level occupancy and vacancy analysis', ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], metrics: $this->metrics([
                $this->metric('properties.occupied_count', 'vacancy_analysis', 'count', 'snapshot', comparison: true, trend: true, historicalReconstruction: true, asOfSupported: true),
                $this->metric('properties.vacant_count', 'vacancy_analysis', 'count', 'snapshot', comparison: true, trend: true, historicalReconstruction: true, asOfSupported: true, anomaly: true),
                $this->metric('properties.occupancy_rate', 'vacancy_analysis', 'percent', 'snapshot', comparison: true, trend: true, historicalReconstruction: true, asOfSupported: true),
                $this->metric('properties.vacancy_rate', 'vacancy_analysis', 'percent', 'snapshot', comparison: true, trend: true, historicalReconstruction: true, asOfSupported: true),
            ]), filters: $this->filters(['property_type' => ['type' => 'enum', 'values' => $propertyTypes], 'owner', 'maintenance', 'occupancy', 'time_range']), time: ['supports_time_range' => true, 'supports_as_of' => true, 'supports_future_range' => true, 'supports_comparison_range' => true, 'supports_trend_range' => true], entities: ['property', 'owner'], resultReferences: ['property', 'owner', 'tenant_agreement', 'work_order'], drilldowns: ['property_360', 'owner_360', 'agreement_360']),
            new ZaakiyCapability('maintenance_intelligence', 'MaintenanceIntelligenceSkill', 'maintenance', 'Deterministic maintenance backlog and completion intelligence', ['maintenance_intelligence', 'maintenance_backlog', 'maintenance_aging', 'maintenance_priority', 'maintenance_completion', 'maintenance_property_summary'], metrics: $this->metrics([
                $this->metric('maintenance.open_work_order_count', 'maintenance_intelligence', 'count', 'snapshot', anomaly: true, warnings: ['HISTORICAL_SNAPSHOT_UNAVAILABLE']),
                $this->metric('maintenance.completed_work_order_count', 'maintenance_intelligence', 'count', 'period', comparison: true, trend: true, explanation: true, anomaly: true),
            ]), filters: $this->filters(['priority' => ['type' => 'enum', 'values' => ['high', 'urgent']], 'property_type' => ['type' => 'enum', 'values' => $propertyTypes], 'owner', 'occupancy', 'minimum_age', 'time_range']), time: ['supports_time_range' => true, 'supports_as_of' => false, 'supports_future_range' => false, 'supports_comparison_range' => true, 'supports_trend_range' => true], entities: ['property', 'owner', 'work_order'], resultReferences: ['work_order', 'property', 'owner'], drilldowns: ['property_360', 'owner_360'], warnings: ['MAINTENANCE_OVERDUE_UNAVAILABLE', 'HISTORICAL_SNAPSHOT_UNAVAILABLE']),
            new ZaakiyCapability('management_briefing', 'ManagementBriefingSkill', 'management', 'Bounded cross-domain management briefing', ['management_briefing'], filters: $this->filters(['time_range', 'attention_only', 'financial']), time: ['supports_time_range' => true, 'supports_as_of' => true, 'supports_future_range' => false, 'supports_comparison_range' => true, 'supports_trend_range' => false], optionalFeaturePermissions: ['financial' => $money], features: ['supports_comparison' => true, 'supports_navigation' => true, 'supports_followups' => true], resultReferences: ['tenant', 'owner', 'agreement', 'property', 'work_order', 'payment'], drilldowns: ['collections_health', 'agreement_risk', 'renewal_intelligence', 'vacancy_analysis', 'maintenance_intelligence'], warnings: ['FINANCIAL_DATA_RESTRICTED'], composite: true, dependencies: ['collections_health', 'vacancy_analysis', 'agreement_risk', 'renewal_intelligence', 'maintenance_intelligence', 'anomaly_detection']),
        ];
    }

    public function skill(string $skill): ?ZaakiyCapability
    {
        foreach ($this->all() as $capability) {
            if ($capability->id === $skill || $capability->skill === $skill) {
                return $capability;
            }
        }

        return null;
    }

    /** @return array<int, ZaakiyCapability> */
    public function forIntent(string $intent): array
    {
        return array_values(array_filter($this->all(), fn (ZaakiyCapability $capability): bool => in_array($intent, $capability->intents, true)));
    }

    /** @return array<int, ZaakiyCapability> */
    public function forDomain(string $domain): array
    {
        return array_values(array_filter($this->all(), fn (ZaakiyCapability $capability): bool => $capability->domain === $domain));
    }

    /** @return array<int, ZaakiyCapability> */
    public function forMetric(string $metric): array
    {
        return array_values(array_filter($this->all(), fn (ZaakiyCapability $capability): bool => $capability->supportsMetric($metric)));
    }

    public function metricCapability(string $metric): ?ZaakiyMetricCapability
    {
        foreach ($this->forMetric($metric) as $capability) {
            return $capability->metrics[$metric];
        }

        return null;
    }

    public function supportsMetric(string $skill, string $metric): bool
    {
        return $this->skill($skill)?->supportsMetric($metric) ?? false;
    }

    public function supportsFilter(string $skill, string $filter): bool
    {
        return $this->skill($skill)?->supportsFilter($filter) ?? false;
    }

    /** @return array<int, string> */
    public function validate(): array
    {
        $errors = [];
        $capabilities = $this->all();
        $ids = [];
        $metricOwners = [];
        foreach ($capabilities as $capability) {
            if (isset($ids[$capability->id])) {
                $errors[] = 'duplicate_skill_id:'.$capability->id;
            }
            $ids[$capability->id] = true;
            foreach ($capability->metrics as $metric => $definition) {
                if (isset($metricOwners[$metric])) {
                    $errors[] = 'duplicate_metric_owner:'.$metric;
                }
                $metricOwners[$metric] = $capability->id;
                if ($definition->provider !== $capability->id) {
                    $errors[] = 'metric_provider_mismatch:'.$metric;
                }
            }
        }
        foreach ($capabilities as $capability) {
            foreach ($capability->drilldowns as $target) {
                if (! isset($ids[$target])) {
                    $errors[] = 'unknown_drilldown_target:'.$capability->id.':'.$target;
                }
            }
            foreach ($capability->dependencies as $dependency) {
                if (! isset($ids[$dependency]) && $dependency !== 'anomaly_detection') {
                    $errors[] = 'unknown_dependency:'.$capability->id.':'.$dependency;
                }
            }
        }

        return $errors;
    }

    public function assertValid(): void
    {
        $errors = $this->validate();
        if ($errors !== []) {
            throw new LogicException(implode('; ', $errors));
        }
    }

    private function metric(string $id, string $provider, string $unit, string $timeSemantics, bool $comparison = false, bool $trend = false, bool $explanation = false, bool $anomaly = false, bool $historicalReconstruction = false, bool $asOfSupported = false, bool $futureSupported = false, array $requiredPermissions = [], array $warnings = []): ZaakiyMetricCapability
    {
        return new ZaakiyMetricCapability($id, $provider, $unit, $timeSemantics, $comparison, $trend, $explanation, $anomaly, $historicalReconstruction, $asOfSupported, $futureSupported, $requiredPermissions, $warnings);
    }

    /** @param array<int, ZaakiyMetricCapability> $metrics */
    private function metrics(array $metrics): array
    {
        $result = [];
        foreach ($metrics as $metric) {
            $result[$metric->id] = $metric;
        }

        return $result;
    }

    private function filters(array $filters, array $permissions = []): array
    {
        $result = [];
        foreach ($filters as $id => $definition) {
            if (is_int($id)) {
                $id = $definition;
                $definition = [];
            }
            $result[$id] = new ZaakiyFilterCapability($id, $definition['type'] ?? 'structured', $definition['values'] ?? [], $definition['permissions'] ?? $permissions);
        }

        return $result;
    }
}
