<?php

namespace App\Services\Zaakiy\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ZaakiyMetricCapability implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $unit,
        public string $timeSemantics,
        public bool $comparison = false,
        public bool $trend = false,
        public bool $explanation = false,
        public bool $anomaly = false,
        public bool $historicalReconstruction = false,
        public bool $asOfSupported = false,
        public bool $futureSupported = false,
        public array $requiredPermissions = [],
        public array $warnings = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'unit' => $this->unit,
            'time_semantics' => $this->timeSemantics,
            'comparison' => $this->comparison,
            'trend' => $this->trend,
            'explanation' => $this->explanation,
            'anomaly' => $this->anomaly,
            'historical_reconstruction' => $this->historicalReconstruction,
            'as_of_supported' => $this->asOfSupported,
            'future_supported' => $this->futureSupported,
            'required_permissions' => $this->requiredPermissions,
            'warnings' => $this->warnings,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
