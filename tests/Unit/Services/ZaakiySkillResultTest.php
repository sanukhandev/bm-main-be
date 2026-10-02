<?php

namespace Tests\Unit\Services;

use App\Models\Branch;
use App\Models\User;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\LegacySkillAdapter;
use App\Services\Zaakiy\SensitiveDataFilter;
use App\Services\Zaakiy\ZaakiyContextBuilder;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;
use PHPUnit\Framework\TestCase;

class ZaakiySkillResultTest extends TestCase
{
    public function test_result_serializes_all_contract_fields(): void
    {
        $result = new ZaakiySkillResult(
            intent: 'property.availability',
            subject: 'Properties',
            summaryMetrics: ['property_count' => 10],
            records: [['type' => 'property', 'id' => 1]],
            breakdowns: ['type' => ['apartment' => 4]],
            comparisons: ['previous' => ['property_count' => 8]],
            warnings: ['Results are limited.'],
            sources: [['type' => 'property', 'id' => 1, 'label' => 'P-001']],
            navigation: [['label' => 'View properties', 'route' => '/app/properties', 'query' => []]],
            suggestedFollowups: ['Which have open maintenance issues?'],
            timeRange: ['from' => '2026-09-01', 'to' => '2026-09-30'],
            meta: ['record_count' => 10],
        );

        $this->assertSame('property.availability', $result->toArray()['intent']);
        $this->assertSame('Properties', $result->toArray()['subject']);
        $this->assertSame(10, $result->toArray()['summary_metrics']['property_count']);
        $this->assertSame('P-001', $result->jsonSerialize()['sources'][0]['label']);
        $this->assertSame('/app/properties', $result->toArray()['navigation'][0]['route']);
        $this->assertSame('2026-09-30', $result->toArray()['time_range']['to']);
    }

    public function test_legacy_skill_adapter_returns_standard_result_and_safe_navigation(): void
    {
        $skill = new class implements ZaakiySkill
        {
            public function matches(string $message): bool
            {
                return true;
            }

            public function run(string $message, User $user, BranchContext $branch): array
            {
                return [
                    'skill' => 'properties',
                    'data' => ['items' => [['type' => 'property', 'reference' => 'P-001']], 'available' => 1],
                    'navigation' => ['label' => 'View properties', 'url' => '/app/properties'],
                ];
            }
        };
        $branch = new BranchContext;
        $branch->set((new Branch)->setRawAttributes(['id' => 5, 'name' => 'Dubai']));
        $intent = new IntentFrame('property.availability', ['properties'], 'aggregate', limit: 10, question: 'available properties');

        $result = (new LegacySkillAdapter('properties', $skill))->execute(new ZaakiyExecutionContext(new User, $branch, $intent, now()->toIso8601String()));

        $this->assertInstanceOf(ZaakiySkillResult::class, $result);
        $this->assertSame(1, $result->summaryMetrics['available']);
        $this->assertSame('/app/properties', $result->navigation[0]['route']);
        $this->assertSame('P-001', $result->records[0]['reference']);
    }

    public function test_context_builder_filters_sensitive_result_fields(): void
    {
        $branch = new BranchContext;
        $branch->set((new Branch)->setRawAttributes(['id' => 5, 'name' => 'Dubai']));
        $intent = new IntentFrame('general.help', ['general'], 'explain', question: 'help');
        $execution = new ZaakiyExecutionContext(new User, $branch, $intent, now()->toIso8601String());
        $result = new ZaakiySkillResult('general.help', meta: ['token' => 'hidden', 'record_count' => 1]);

        $context = (new ZaakiyContextBuilder(new SensitiveDataFilter))->build($intent, $execution, [$result]);

        $this->assertArrayNotHasKey('token', $context['evidence'][0]['meta']);
        $this->assertSame(1, $context['evidence'][0]['meta']['record_count']);
    }
}
