<?php

namespace App\Services\Zaakiy;

class ZaakiyContextBuilder
{
    public function __construct(private readonly SensitiveDataFilter $filter) {}

    public function build(IntentFrame $intent, ZaakiyExecutionContext $execution, array $evidence): array
    {
        $navigation = null;
        foreach ($evidence as $item) {
            if ($item['navigation'] ?? null) {
                $navigation = $item['navigation'];
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
