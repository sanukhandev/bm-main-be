<?php

namespace App\Services\Zaakiy\Agent;

use App\Services\Zaakiy\DTOs\ZaakiySkillResult;

final readonly class ZaakiyToolOutput
{
    /**
     * @param  array<string, mixed>  $modelPayload
     * @param  array<string, mixed>|null  $disambiguation
     * @param  array<string, mixed>|null  $actionProposal
     */
    public function __construct(
        public array $modelPayload,
        public ?ZaakiySkillResult $skillResult = null,
        public ?array $disambiguation = null,
        public ?array $actionProposal = null,
        public ?string $errorCode = null,
    ) {}
}
