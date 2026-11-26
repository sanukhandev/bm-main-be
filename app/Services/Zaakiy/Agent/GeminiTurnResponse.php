<?php

namespace App\Services\Zaakiy\Agent;

final readonly class GeminiTurnResponse
{
    /**
     * @param  array<string, mixed>  $rawModelContent
     * @param  array<int, array{name: string, args: array<string, mixed>}>  $functionCalls
     */
    public function __construct(
        public array $rawModelContent,
        public array $functionCalls,
        public ?string $text,
        public int $promptTokens,
        public int $candidatesTokens,
        public string $model,
    ) {}
}
