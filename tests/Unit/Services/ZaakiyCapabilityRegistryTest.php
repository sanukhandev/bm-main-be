<?php

namespace Tests\Unit\Services;

use App\Services\Zaakiy\CapabilityRegistry;
use Tests\TestCase;

class ZaakiyCapabilityRegistryTest extends TestCase
{
    public function test_registry_is_valid_and_contains_major_capabilities(): void
    {
        $registry = app(CapabilityRegistry::class);

        $this->assertSame([], $registry->validate());
        $this->assertNotNull($registry->skill('management_briefing'));
        $this->assertCount(10, $registry->all());
    }

    public function test_metric_lookup_has_one_canonical_provider_and_features(): void
    {
        $registry = app(CapabilityRegistry::class);
        $capabilities = $registry->forMetric('collections.collected_amount');
        $metric = $registry->metricCapability('collections.collected_amount');

        $this->assertCount(1, $capabilities);
        $this->assertSame('collections_health', $capabilities[0]->id);
        $this->assertTrue($metric->comparison);
        $this->assertTrue($metric->trend);
        $this->assertTrue($metric->explanation);
        $this->assertSame(['accounts.view'], $metric->requiredPermissions);
    }

    public function test_snapshot_limitations_and_filter_metadata_are_explicit(): void
    {
        $registry = app(CapabilityRegistry::class);
        $backlog = $registry->metricCapability('maintenance.open_work_order_count');
        $vacancy = $registry->skill('vacancy_analysis');

        $this->assertFalse($backlog->trend);
        $this->assertContains('HISTORICAL_SNAPSHOT_UNAVAILABLE', $backlog->warnings);
        $this->assertTrue($registry->supportsFilter('maintenance_intelligence', 'priority'));
        $this->assertSame(['high', 'urgent'], $registry->skill('maintenance_intelligence')->filters['priority']->allowedValues);
        $this->assertSame(['property_360', 'owner_360', 'agreement_360'], $vacancy->drilldowns);
    }

    public function test_management_briefing_is_composite_and_read_only(): void
    {
        $capability = app(CapabilityRegistry::class)->skill('management_briefing');

        $this->assertTrue($capability->composite);
        $this->assertTrue($capability->readOnly);
        $this->assertContains('collections_health', $capability->dependencies);
        $this->assertNotContains('management_briefing', array_keys($capability->metrics));
    }
}
