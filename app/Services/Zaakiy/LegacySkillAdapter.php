<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiySkillResult;

final class LegacySkillAdapter implements ZaakiyReadSkill
{
    public function __construct(private readonly string $module, private readonly ZaakiySkill $skill) {}

    public function supports(IntentFrame $intent): bool
    {
        return in_array($this->module, $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult
    {
        $result = $this->skill->run($context->intent->question, $context->user, $context->branch);
        $data = json_decode(json_encode($result['data'] ?? [], JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        $recordKeys = ['items', 'expiring_agreements', 'expiring_with_outstanding', 'records'];
        $records = [];
        foreach ($recordKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $records = array_merge($records, $data[$key]);
                unset($data[$key]);
            }
        }
        $limit = max(1, min($context->intent->limit, 25));
        $truncated = count($records) > $limit;
        $navigation = $result['navigation'] ?? [];
        $navigation = $navigation === [] ? [] : (array_is_list($navigation) ? $navigation : [$navigation]);
        $navigation = array_map(static fn (array $target): array => [
            'label' => $target['label'] ?? 'Open in Baithul Madeena',
            'route' => $target['route'] ?? $target['url'] ?? null,
            'query' => $target['query'] ?? [],
        ], $navigation);
        $comparisons = [];
        if (isset($data['comparison'])) {
            $comparisons['comparison'] = $data['comparison'];
            unset($data['comparison']);
        }
        $timeRange = $context->intent->timeRange;
        if ($timeRange === null && isset($data['period']) && is_array($data['period'])) {
            $timeRange = $data['period'];
        }

        return new ZaakiySkillResult(
            intent: $context->intent->intent,
            subject: $this->module,
            summaryMetrics: $data,
            records: array_slice($records, 0, $limit),
            comparisons: $comparisons,
            navigation: $navigation,
            timeRange: $timeRange,
            meta: [
                'skill' => $this->module,
                'record_count' => count($records),
                'truncated' => $truncated,
                'limit' => $limit,
            ],
        );
    }
}
