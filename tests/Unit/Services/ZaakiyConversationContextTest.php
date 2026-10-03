<?php

namespace Tests\Unit\Services;

use App\Models\Branch;
use App\Services\Zaakiy\DTOs\ZaakiyConversationContext;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyConversationContextResolver;
use App\Support\Branch\BranchContext;
use PHPUnit\Framework\TestCase;

class ZaakiyConversationContextTest extends TestCase
{
    public function test_context_serializes_bounded_structured_state(): void
    {
        $context = ZaakiyConversationContext::fromArray([
            'intent' => 'collections.summary',
            'domain' => 'accounts',
            'metric' => 'inward_collections',
            'entities' => [['type' => 'customer', 'id' => 42, 'label' => 'Ahmed Hassan']],
            'time_range' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'label' => 'September 2026'],
            'filters' => ['status' => 'overdue'],
            'result_references' => [['type' => 'tenant_agreement', 'id' => 7, 'label' => 'TA-007']],
            'branch_context' => ['branch_id' => 5],
            'meta' => ['ignored' => true],
        ]);

        $this->assertSame('accounts', $context->toArray()['domain']);
        $this->assertSame('September 2026', $context->toArray()['time_range']['label']);
        $this->assertSame('TA-007', $context->toArray()['result_references'][0]['label']);
        $this->assertSame([], $context->meta);
    }

    public function test_follow_up_replaces_time_range_and_inherits_metric(): void
    {
        $branch = $this->branch(5);
        $previous = [
            'intent' => 'collections.summary',
            'domain' => 'accounts',
            'metric' => 'inward_collections',
            'time_range' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'branch_context' => ['branch_id' => 5],
        ];
        $current = new IntentFrame(
            'general.help',
            ['general'],
            'explain',
            timeRange: ['from' => '2026-09-01', 'to' => '2026-09-30'],
        );

        $resolved = (new ZaakiyConversationContextResolver)->resolve($previous, $current, 'What about last month?', $branch);

        $this->assertSame('collections.summary', $resolved->intent);
        $this->assertSame('inward_collections', $resolved->metric);
        $this->assertSame('2026-09-01', $resolved->timeRange['from']);
    }

    public function test_filter_follow_up_keeps_overdue_and_adds_amount(): void
    {
        $branch = $this->branch(5);
        $current = new IntentFrame('general.help', ['general'], 'explain');
        $resolved = (new ZaakiyConversationContextResolver)->resolve([
            'domain' => 'accounts',
            'intent' => 'overdue.receivables',
            'filters' => ['status' => 'overdue'],
            'branch_context' => ['branch_id' => 5],
        ], $current, 'Only above AED 10,000.', $branch);

        $this->assertSame('overdue', $resolved->filters['status']);
        $this->assertSame(10000.0, $resolved->filters['outstanding_gt']);
    }

    public function test_topic_change_clears_previous_branch_entities(): void
    {
        $branch = $this->branch(5);
        $current = new IntentFrame('maintenance.open', ['maintenance'], 'list');
        $resolved = (new ZaakiyConversationContextResolver)->resolve([
            'domain' => 'agreements',
            'entities' => [['type' => 'customer', 'id' => 42, 'label' => 'Ahmed']],
            'result_references' => [['type' => 'tenant_agreement', 'id' => 7, 'label' => 'TA-007']],
            'branch_context' => ['branch_id' => 5],
        ], $current, 'Show open maintenance work orders.', $branch);

        $this->assertSame('maintenance', $resolved->domain);
        $this->assertSame([], $resolved->entities);
        $this->assertSame([], $resolved->resultReferences);
    }

    public function test_branch_change_clears_branch_sensitive_context(): void
    {
        $branch = $this->branch(6);
        $current = new IntentFrame('general.help', ['general'], 'explain');
        $resolved = (new ZaakiyConversationContextResolver)->resolve([
            'domain' => 'properties',
            'entities' => [['type' => 'property', 'id' => 42, 'label' => 'P-042']],
            'result_references' => [['type' => 'property', 'id' => 42, 'label' => 'P-042']],
            'filters' => ['status' => 'vacant'],
            'branch_context' => ['branch_id' => 5],
        ], $current, 'Show those.', $branch);

        $this->assertSame(6, $resolved->branchContext['branch_id']);
        $this->assertSame([], $resolved->entities);
        $this->assertSame([], $resolved->resultReferences);
        $this->assertSame([], $resolved->filters);
    }

    public function test_resolved_explicit_entity_replaces_previous_entity(): void
    {
        $branch = $this->branch(5);
        $context = new ZaakiyConversationContext(
            entities: [['type' => 'customer', 'id' => 1, 'label' => 'Ahmed']],
            resultReferences: [['type' => 'customer', 'id' => 1, 'label' => 'Ahmed']],
            branchContext: ['branch_id' => 5],
        );
        $result = new ZaakiySkillResult(
            intent: 'entity_resolution',
            records: [['entity_type' => 'customer', 'fields' => ['id' => 2, 'customer_code' => 'CUS-002']]],
        );

        $resolved = (new ZaakiyConversationContextResolver)->complete(
            $context,
            new IntentFrame('entity_resolution', ['customers'], 'search'),
            [$result],
            $branch,
        );

        $this->assertSame(2, $resolved->entities[0]['id']);
        $this->assertSame('CUS-002', $resolved->entities[0]['label']);
    }

    public function test_agreement_risk_follow_up_preserves_range_and_adds_type(): void
    {
        $branch = $this->branch(5);
        $resolved = (new ZaakiyConversationContextResolver)->resolve([
            'intent' => 'agreement_expiry',
            'domain' => 'agreements',
            'time_range' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'branch_context' => ['branch_id' => 5],
        ], new IntentFrame('general.help', ['general'], 'explain'), 'Only tenant agreements.', $branch);

        $this->assertSame('2026-10-01', $resolved->timeRange['from']);
        $this->assertSame('tenant', $resolved->filters['agreement_type']);
    }

    public function test_renewal_follow_up_preserves_range_and_adds_type(): void
    {
        $branch = $this->branch(5);
        $first = new IntentFrame('renewal_intelligence', ['renewal_intelligence'], 'search', timeRange: ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $context = (new ZaakiyConversationContextResolver)->resolve(null, $first, 'Which agreements need renewal next month?', $branch);
        $second = new IntentFrame('general.help', ['general'], 'explain', comparisonPeriod: ['from' => '2026-09-01', 'to' => '2026-09-30'], comparisonRequested: true);
        $resolved = (new ZaakiyConversationContextResolver)->resolve($context->toArray(), $second, 'Only tenant agreements.', $branch);

        $this->assertSame('renewal_intelligence', $resolved->intent);
        $this->assertSame('tenant', $resolved->filters['agreement_type']);
        $this->assertSame($context->timeRange['from'], $resolved->timeRange['from']);
        $this->assertSame($context->timeRange['to'], $resolved->timeRange['to']);
    }

    public function test_vacancy_follow_up_preserves_property_type_filter(): void
    {
        $branch = $this->branch(5);
        $resolver = new ZaakiyConversationContextResolver;
        $first = new IntentFrame('vacancy_analysis', ['vacancy_analysis'], 'search');
        $context = $resolver->resolve(null, $first, 'Show vacant properties.', $branch);
        $second = new IntentFrame('general.help', ['general'], 'explain');
        $resolved = $resolver->resolve($context->toArray(), $second, 'Only apartments.', $branch);

        $this->assertSame('vacancy_analysis', $resolved->intent);
        $this->assertSame('apartment', $resolved->filters['property_type']);
    }

    public function test_maintenance_follow_up_preserves_mode_and_adds_filters(): void
    {
        $branch = $this->branch(5);
        $resolver = new ZaakiyConversationContextResolver;
        $first = new IntentFrame('maintenance_aging', ['maintenance_intelligence'], 'search');
        $context = $resolver->resolve(null, $first, 'Show the oldest open work orders.', $branch);
        $second = new IntentFrame('general.help', ['general'], 'explain');
        $resolved = $resolver->resolve($context->toArray(), $second, 'Only high priority apartments older than 30 days.', $branch);

        $this->assertSame('maintenance_aging', $resolved->intent);
        $this->assertSame('high', $resolved->filters['priority']);
        $this->assertSame('apartment', $resolved->filters['property_type']);
        $this->assertSame(30, $resolved->filters['age_gt']);
    }

    public function test_owner_maintenance_follow_up_retains_owner_entity(): void
    {
        $branch = $this->branch(5);
        $resolved = (new ZaakiyConversationContextResolver)->resolve([
            'intent' => 'owner_360',
            'domain' => 'customers',
            'entities' => [['type' => 'owner', 'id' => 42, 'label' => 'Ahmed Hassan']],
            'branch_context' => ['branch_id' => 5],
        ], new IntentFrame('maintenance_backlog', ['maintenance_intelligence'], 'search', question: 'Which properties have open maintenance?'), 'Which properties have open maintenance?', $branch);

        $this->assertSame('maintenance_backlog', $resolved->intent);
        $this->assertSame(42, $resolved->entities[0]['id']);
    }

    public function test_compare_follow_up_keeps_primary_period_and_adds_comparison_period(): void
    {
        $branch = $this->branch(5);
        $resolver = new ZaakiyConversationContextResolver;
        $first = new IntentFrame('collections_summary', ['collections_health'], 'aggregate', timeRange: ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $context = $resolver->resolve(null, $first, 'How much did we collect this month?', $branch);
        $second = new IntentFrame('general.help', ['general'], 'explain', comparisonPeriod: ['from' => '2026-09-01', 'to' => '2026-09-30'], comparisonRequested: true);
        $resolved = $resolver->resolve($context->toArray(), $second, 'Compare with last month.', $branch);

        $this->assertSame('2026-10-01', $resolved->timeRange['from']);
        $this->assertSame('2026-09-01', $resolved->comparisonRange['from']);
    }

    private function branch(int $id): BranchContext
    {
        $context = new BranchContext;
        $context->set((new Branch)->setRawAttributes(['id' => $id, 'code' => 'B'.$id, 'name' => 'Branch '.$id]));

        return $context;
    }
}
