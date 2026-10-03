<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyMetricTrendPoint implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $periodStart,
        public string $periodEnd,
        public string $label,
        public int|float|null $value,
        public ?string $asOf = null,
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'label' => $this->label,
            'value' => $this->value,
            'as_of' => $this->asOf,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
