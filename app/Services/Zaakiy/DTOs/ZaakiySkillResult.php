<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiySkillResult implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $intent,
        public ?string $subject = null,
        public array $summaryMetrics = [],
        public array $records = [],
        public array $breakdowns = [],
        public array $comparisons = [],
        public array $warnings = [],
        public array $sources = [],
        public array $navigation = [],
        public array $suggestedFollowups = [],
        public ?array $timeRange = null,
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'subject' => $this->subject,
            'summary_metrics' => $this->summaryMetrics,
            'records' => $this->records,
            'breakdowns' => $this->breakdowns,
            'comparisons' => $this->comparisons,
            'warnings' => $this->warnings,
            'sources' => $this->sources,
            'navigation' => $this->navigation,
            'suggested_followups' => $this->suggestedFollowups,
            'time_range' => $this->timeRange,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
