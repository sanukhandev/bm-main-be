<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiyConversationContext;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;

class ZaakiyContextBuilder
{
    public function __construct(private readonly SensitiveDataFilter $filter) {}

    /** @param array<int, ZaakiySkillResult> $results */
    public function build(IntentFrame $intent, ZaakiyExecutionContext $execution, array $results, ?ZaakiyConversationContext $conversation = null): array
    {
        $conversation ??= new ZaakiyConversationContext;
        $evidence = array_map(static fn ($result): array => $result->toArray(), $results);
        $navigations = [];
        $seen = [];
        foreach ($evidence as $item) {
            foreach ((array) ($item['navigation'] ?? []) as $target) {
                if (! is_array($target) || empty($target['route'])) {
                    continue;
                }
                $query = isset($target['query']) && is_array($target['query']) && $target['query'] !== [] ? $target['query'] : null;
                $key = $target['route'].'|'.json_encode($query);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $nav = [
                    'label' => $target['label'] ?? 'Open in Baithul Madeena',
                    'url' => $target['route'],
                ];
                if ($query !== null) {
                    $nav['query'] = $query;
                }
                $navigations[] = $nav;
                if (count($navigations) >= 5) {
                    break 2;
                }
            }
        }
        $navigation = $navigations[0] ?? null;

        return $this->filter->clean([
            'question' => $intent->question,
            'resolved_intent' => $intent->toArray(),
            'branch' => ['name' => $execution->branch->branch()->name],
            'period' => $intent->timeRange,
            'evidence' => $evidence,
            'navigation' => $navigation,
            'navigations' => $navigations,
            'conversation_context' => $conversation->toArray(),
            'constraints' => ['read_only' => true, 'backend_values_are_authoritative' => true],
        ]);
    }
}
