<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiySkillResult;

class ZaakiyContextBuilder
{
    public function __construct(private readonly SensitiveDataFilter $filter) {}

    /** @param array<int, ZaakiySkillResult> $results */
    public function build(IntentFrame $intent, ZaakiyExecutionContext $execution, array $results): array
    {
        $evidence = array_map(static fn ($result): array => $result->toArray(), $results);
        $navigation = null;
        foreach ($evidence as $item) {
            $target = $item['navigation'][0] ?? null;
            if (is_array($target)) {
                $navigation = [
                    'label' => $target['label'] ?? 'Open in Baithul Madeena',
                    'url' => $target['route'] ?? null,
                ];
                if (isset($target['query'])) {
                    $navigation['query'] = $target['query'];
                }
                break;
            }
        }

        return $this->filter->clean([
            'question' => $intent->question,
            'resolved_intent' => $intent->toArray(),
            'branch' => ['name' => $execution->branch->branch()->name],
            'period' => $intent->timeRange,
            'evidence' => $evidence,
            'navigation' => $navigation,
            'constraints' => ['read_only' => true, 'backend_values_are_authoritative' => true],
        ]);
    }
}
