<?php

namespace Tests\Unit\Services;

use App\Services\Zaakiy\IntentAnalyzer;
use App\Services\Zaakiy\SensitiveDataFilter;
use App\Services\Zaakiy\TimeRangeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZaakiyIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiry_and_outstanding_question_creates_a_multi_skill_frame(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Which tenant agreements expire in the next 30 days and still owe money?');

        $this->assertSame('agreement.expiring_with_outstanding', $frame->intent);
        $this->assertSame(['agreements', 'accounts'], $frame->modules);
        $this->assertSame(['from' => now()->toDateString(), 'to' => now()->addDays(30)->toDateString()], $frame->timeRange);
    }

    public function test_follow_up_uses_previous_question_only_for_intent_resolution(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('What about next month?', [
            ['role' => 'user', 'text' => 'Which tenant agreements expire this month?'],
        ]);

        $this->assertSame('agreement.expiring', $frame->intent);
        $this->assertContains('agreements', $frame->modules);
    }

    public function test_sensitive_evidence_fields_are_removed(): void
    {
        $clean = app(SensitiveDataFilter::class)->clean([
            'amount' => '100.00',
            'password' => 'hidden',
            'metadata' => ['api_token' => 'hidden', 'label' => 'safe'],
        ]);

        $this->assertSame(['amount' => '100.00', 'metadata' => ['label' => 'safe']], $clean);
    }

    public function test_property_detail_question_selects_property_360(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Tell me about Flat 204.');

        $this->assertSame('property_360', $frame->intent);
        $this->assertSame(['property_360'], $frame->modules);
    }

    public function test_property_360_follow_up_selects_the_same_read_skill(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Does it have outstanding payments?', [
            ['role' => 'user', 'text' => 'Tell me about Flat 204.'],
        ]);

        $this->assertSame('property_360', $frame->intent);
        $this->assertSame(['property_360'], $frame->modules);
    }

    public function test_tenant_summary_question_selects_tenant_360(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Tell me about Ahmed Ali.');

        $this->assertSame('tenant_360', $frame->intent);
        $this->assertSame(['tenant_360'], $frame->modules);
    }

    public function test_tenant_follow_up_selects_tenant_360(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('What property is he renting?', [
            ['role' => 'user', 'text' => 'Tell me about Ahmed Ali.'],
        ]);

        $this->assertSame('tenant_360', $frame->intent);
        $this->assertSame(['tenant_360'], $frame->modules);
    }

    public function test_owner_summary_question_selects_owner_360(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Tell me about owner Ahmed Hassan.');

        $this->assertSame('owner_360', $frame->intent);
        $this->assertSame(['owner_360'], $frame->modules);
    }

    public function test_owner_follow_up_selects_owner_360(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Which properties are vacant?', [
            ['role' => 'user', 'text' => 'Tell me about owner Ahmed Hassan.'],
        ]);

        $this->assertSame('owner_360', $frame->intent);
    }

    public function test_collections_questions_select_the_collections_skill(): void
    {
        $this->assertSame('collections_summary', app(IntentAnalyzer::class)->analyze('How much did we collect this month?')->intent);
        $this->assertSame('overdue_receivables', app(IntentAnalyzer::class)->analyze('Which tenants have overdue payments?')->intent);
        $this->assertSame('collection_cheques', app(IntentAnalyzer::class)->analyze('Any bounced cheques?')->intent);
    }

    public function test_owner_payables_do_not_select_collections(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('How much do we owe owners?');

        $this->assertNotSame('collections_summary', $frame->intent);
        $this->assertNotSame('overdue_receivables', $frame->intent);
    }

    public function test_agreement_attention_questions_select_agreement_risk(): void
    {
        $analyzer = app(IntentAnalyzer::class);

        $this->assertSame('agreement_risk', $analyzer->analyze('Which agreements need attention?')->intent);
        $this->assertSame('agreement_expiry', $analyzer->analyze('Which tenant agreements expire in the next 30 days?')->intent);
    }

    public function test_renewal_questions_select_renewal_intelligence(): void
    {
        $intent = app(IntentAnalyzer::class)->analyze('Which agreements need renewal next month?');
        $this->assertSame('renewal_intelligence', $intent->intent);
        $this->assertSame(['renewal_intelligence'], $intent->modules);

        $intent = app(IntentAnalyzer::class)->analyze('Which renewals have overdue balances?');
        $this->assertSame('renewal_intelligence', $intent->intent);
    }

    public function test_vacancy_questions_select_vacancy_analysis(): void
    {
        $analyzer = app(IntentAnalyzer::class);

        $this->assertSame('vacancy_analysis', $analyzer->analyze('How many properties are vacant?')->intent);
        $this->assertSame('occupancy_summary', $analyzer->analyze('What is our occupancy rate?')->intent);
        $this->assertSame('upcoming_vacancy', $analyzer->analyze('Which properties become vacant next month?')->intent);
    }

    public function test_maintenance_portfolio_questions_select_maintenance_intelligence(): void
    {
        $analyzer = app(IntentAnalyzer::class);

        $this->assertSame('maintenance_backlog', $analyzer->analyze('How many work orders are open?')->intent);
        $this->assertSame('maintenance_aging', $analyzer->analyze('Which work orders are the oldest?')->intent);
        $this->assertSame('maintenance_completion', $analyzer->analyze('How many work orders were completed this month?')->intent);
    }

    public function test_comparison_preserves_domain_and_resolves_both_ranges(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Compare collections this month with last month.');

        $this->assertSame('collections_summary', $frame->intent);
        $this->assertTrue($frame->comparisonRequested);
        $this->assertSame(['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()], $frame->timeRange);
        $this->assertSame(['from' => now()->subMonth()->startOfMonth()->toDateString(), 'to' => now()->subMonth()->endOfMonth()->toDateString()], $frame->comparisonPeriod);
    }

    public function test_time_range_resolver_supports_week_quarter_and_explicit_dates(): void
    {
        $resolver = app(TimeRangeResolver::class);

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31'], $resolver->resolve('from 2026-10-01 to 2026-10-31'));
        $this->assertNotNull($resolver->resolve('this week'));
        $this->assertNotNull($resolver->resolve('this quarter'));
    }

    public function test_trend_keeps_domain_and_metric_with_explicit_granularity(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Show monthly collections trend for the last 6 months.');

        $this->assertSame('collections_summary', $frame->intent);
        $this->assertTrue($frame->filters['trend']);
        $this->assertSame('month', $frame->filters['trend_granularity']);
        $this->assertSame('2026-05-01', $frame->timeRange['from']);
    }

    public function test_explanation_uses_previous_period_when_not_explicit(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Why were collections higher this month?');

        $this->assertSame('collections_summary', $frame->intent);
        $this->assertTrue($frame->explanationRequested);
        $this->assertTrue($frame->comparisonRequested);
        $this->assertSame(now()->subMonth()->startOfMonth()->toDateString(), $frame->comparisonPeriod['from']);
    }

    public function test_anomaly_request_keeps_domain_and_comparison_context(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Any collection anomalies?');

        $this->assertSame('collections_summary', $frame->intent);
        $this->assertTrue($frame->anomalyRequested);
        $this->assertTrue($frame->comparisonRequested);
        $this->assertNotNull($frame->comparisonPeriod);
    }

    public function test_time_range_resolver_supports_next_three_months(): void
    {
        $range = app(TimeRangeResolver::class)->resolve('renewals in the next 3 months', CarbonImmutable::parse('2026-10-03'));

        $this->assertSame('2026-10-03', $range['from']);
        $this->assertSame('2027-01-03', $range['to']);
    }
}
