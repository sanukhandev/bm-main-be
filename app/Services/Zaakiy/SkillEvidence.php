<?php

namespace App\Services\Zaakiy;

final readonly class SkillEvidence
{
    public function __construct(
        public string $source,
        public string $purpose,
        public array $summary = [],
        public array $records = [],
        public array $warnings = [],
        public bool $truncated = false,
        public ?array $period = null,
        public ?array $navigation = null,
    ) {}

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'purpose' => $this->purpose,
            'summary' => $this->summary,
            'records' => $this->records,
            'warnings' => $this->warnings,
            'truncated' => $this->truncated,
            'period' => $this->period,
            'navigation' => $this->navigation,
        ];
    }
}
