<?php

namespace App\Services\Zaakiy\Agent;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiToolClient
{
    private const MAX_LINE_BYTES = 65536;

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<int, array<string, mixed>>  $toolDeclarations
     */
    public function planTurn(
        string $systemInstruction,
        array $contents,
        array $toolDeclarations,
        ?string $model = null,
    ): GeminiTurnResponse {
        $key = $this->apiKey();
        $resolvedModel = $this->resolveModel($model);
        $url = $this->endpoint($resolvedModel, 'generateContent');

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 1200],
        ];
        if ($toolDeclarations !== []) {
            $payload['tools'] = [['functionDeclarations' => array_values($toolDeclarations)]];
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }

        $response = $this->postWithRetry($url, $payload, $key, false);
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Zaakiy received an invalid response from the AI service.');
        }

        // Preserve the full raw candidate content verbatim (including thoughtSignature on parts).
        $rawContent = is_array($data['candidates'][0]['content'] ?? null)
            ? $data['candidates'][0]['content']
            : ['role' => 'model', 'parts' => []];
        $parts = is_array($rawContent['parts'] ?? null) ? $rawContent['parts'] : [];

        $functionCalls = [];
        $textParts = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            if (is_array($part['functionCall'] ?? null) && is_string($part['functionCall']['name'] ?? null)) {
                $args = $part['functionCall']['args'] ?? [];
                $functionCalls[] = [
                    'name' => $part['functionCall']['name'],
                    'args' => is_array($args) ? $args : [],
                ];
            }
            if (is_string($part['text'] ?? null) && $part['text'] !== '' && ! ($part['thought'] ?? false)) {
                $textParts[] = $part['text'];
            }
        }

        return new GeminiTurnResponse(
            rawModelContent: $rawContent,
            functionCalls: $functionCalls,
            text: $textParts === [] ? null : implode('', $textParts),
            promptTokens: (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
            candidatesTokens: (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            model: $resolvedModel,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @return array{text: string, prompt_tokens: int, candidates_tokens: int, model: string}
     */
    public function streamFinal(
        string $systemInstruction,
        array $contents,
        callable $onToken,
        ?string $model = null,
    ): array {
        $key = $this->apiKey();
        $resolvedModel = $this->resolveModel($model);
        $url = $this->endpoint($resolvedModel, 'streamGenerateContent').'?alt=sse';

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 1400],
        ];

        $response = $this->postWithRetry($url, $payload, $key, true);
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $fullText = '';
        $promptTokens = 0;
        $candidatesTokens = 0;

        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            if (strlen($buffer) > self::MAX_LINE_BYTES && ! str_contains($buffer, "\n")) {
                throw new RuntimeException('Zaakiy stream exceeded maximum line length.');
            }
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $chunk = json_decode(trim(substr($line, 5)), true);
                if (! is_array($chunk)) {
                    continue;
                }
                if (isset($chunk['usageMetadata']['promptTokenCount'])) {
                    $promptTokens = (int) $chunk['usageMetadata']['promptTokenCount'];
                }
                if (isset($chunk['usageMetadata']['candidatesTokenCount'])) {
                    $candidatesTokens = (int) $chunk['usageMetadata']['candidatesTokenCount'];
                }
                foreach ((array) ($chunk['candidates'][0]['content']['parts'] ?? []) as $part) {
                    if (is_array($part) && is_string($part['text'] ?? null) && $part['text'] !== '' && ! ($part['thought'] ?? false)) {
                        $fullText .= $part['text'];
                        $onToken($part['text']);
                    }
                }
            }
        }

        return [
            'text' => $fullText,
            'prompt_tokens' => $promptTokens,
            'candidates_tokens' => $candidatesTokens,
            'model' => $resolvedModel,
        ];
    }

    private function postWithRetry(string $url, array $payload, string $key, bool $stream)
    {
        $response = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $request = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout(30)
                ->connectTimeout(10)
                ->acceptJson();
            if ($stream) {
                $request = $request->withOptions(['stream' => true]);
            }
            $response = $request->post($url, $payload);

            if (! in_array($response->status(), [429, 500, 502, 503, 504], true) || $attempt === 2) {
                break;
            }
            usleep($attempt * 400_000);
        }

        if ($response === null) {
            throw new RuntimeException('Zaakiy is temporarily unavailable. Please try again shortly.');
        }

        if ($response->failed()) {
            $message = $response->status() === 429
                ? 'Zaakiy is temporarily unavailable because the AI service rate limit was reached.'
                : 'Zaakiy is temporarily unavailable. Please try again shortly.';

            throw new RuntimeException($message);
        }

        return $response;
    }

    private function apiKey(): string
    {
        $key = (string) config('services.gemini.key');
        if ($key === '') {
            throw new RuntimeException('Zaakiy is not configured. Add GEMINI_API_KEY to the backend environment.');
        }

        return $key;
    }

    private function resolveModel(?string $model): string
    {
        $resolved = trim((string) ($model ?: config('services.gemini.model', 'gemini-flash-latest')));

        return $resolved !== '' ? $resolved : 'gemini-flash-latest';
    }

    private function endpoint(string $model, string $method): string
    {
        return rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/')
            .'/models/'.rawurlencode($model).':'.$method;
    }
}
