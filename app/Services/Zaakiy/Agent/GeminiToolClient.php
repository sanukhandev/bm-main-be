<?php

namespace App\Services\Zaakiy\Agent;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;

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
        $candidates = $this->candidateModels($model);

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => $this->normalizeContents($contents),
            'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 1200],
        ];
        if ($toolDeclarations !== []) {
            $payload['tools'] = [['functionDeclarations' => array_values($toolDeclarations)]];
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }

        ['response' => $response, 'model' => $resolvedModel] = $this->postWithFailoverAndRetry(
            $candidates,
            'generateContent',
            $payload,
            $key,
            false,
        );
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Zaakiy received an invalid response from the AI service.');
        }

        // Preserve the full raw candidate content verbatim (including thoughtSignature on parts),
        // ensuring empty JSON objects ({}) in functionCall.args do not round-trip as JSON arrays ([]).
        $rawContent = is_array($data['candidates'][0]['content'] ?? null)
            ? $this->normalizeContent($data['candidates'][0]['content'])
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
        $candidates = $this->candidateModels($model);

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => $this->normalizeContents($contents),
            'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 1400],
        ];

        ['response' => $response, 'model' => $resolvedModel] = $this->postWithFailoverAndRetry(
            $candidates,
            'streamGenerateContent',
            $payload,
            $key,
            true,
        );
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

    /**
     * Try primary and fallback models; if all models hit 429, retry up to 3 times with 3s delay.
     *
     * @param  array<int, string>  $models
     * @param  array<string, mixed>  $payload
     * @return array{response: Response, model: string}
     */
    private function postWithFailoverAndRetry(array $models, string $method, array $payload, string $key, bool $stream): array
    {
        $rateLimitRetries = 0;
        $serverRetries = 0;
        $rateLimitDelayMs = max(0, (int) config('services.gemini.rate_limit_retry_delay_ms', 3000));
        $lastResponse = null;
        $lastModel = $models[0] ?? 'gemini-flash-latest';
        $lastException = null;

        while (true) {
            $sawRateLimit = false;
            $sawTransientServerError = false;

            foreach ($models as $index => $candidateModel) {
                $lastModel = $candidateModel;
                $url = $this->endpoint($candidateModel, $method).($stream ? '?alt=sse' : '');

                try {
                    $request = Http::withHeaders(['x-goog-api-key' => $key])
                        ->timeout(30)
                        ->connectTimeout(10)
                        ->acceptJson();
                    if ($stream) {
                        $request = $request->withOptions(['stream' => true]);
                    }
                    $response = $request->post($url, $payload);
                    $lastResponse = $response;
                    $lastException = null;
                } catch (ConnectionException $exception) {
                    $lastException = $exception;
                    $lastResponse = null;
                    $sawTransientServerError = true;
                    Log::warning('Zaakiy Gemini API connection timeout/error.', [
                        'model' => $candidateModel,
                        'message' => $exception->getMessage(),
                        'has_fallback' => $index < count($models) - 1,
                    ]);

                    continue;
                }

                if ($response->successful()) {
                    return ['response' => $response, 'model' => $candidateModel];
                }

                $status = $response->status();
                if ($status === 429) {
                    $sawRateLimit = true;
                } elseif (in_array($status, [500, 502, 503, 504], true)) {
                    $sawTransientServerError = true;
                }

                // Fail over to the next configured fallback model on rate-limit, unavailable, or missing model errors.
                if (in_array($status, [404, 429, 500, 502, 503, 504], true) && $index < count($models) - 1) {
                    Log::info('Zaakiy Gemini API failing over to fallback model.', [
                        'failed_model' => $candidateModel,
                        'next_model' => $models[$index + 1],
                        'status' => $status,
                    ]);

                    continue;
                }

                // Non-retryable client error (e.g., 400/401/403): stop immediately.
                if (! in_array($status, [429, 500, 502, 503, 504], true)) {
                    break 2;
                }
            }

            if ($sawRateLimit && $rateLimitRetries < 3) {
                $rateLimitRetries++;
                Log::info('Zaakiy Gemini API rate limited (429); retrying.', [
                    'models' => $models,
                    'retry' => $rateLimitRetries,
                    'delay_ms' => $rateLimitDelayMs,
                ]);
                if ($rateLimitDelayMs > 0) {
                    usleep($rateLimitDelayMs * 1000);
                }

                continue;
            }

            if ($sawTransientServerError && $serverRetries < 1) {
                $serverRetries++;
                usleep(400_000);

                continue;
            }

            break;
        }

        if ($lastResponse === null) {
            if ($lastException !== null) {
                throw new RuntimeException('Zaakiy is temporarily unavailable. Please try again shortly.', 0, $lastException);
            }

            throw new RuntimeException('Zaakiy is temporarily unavailable. Please try again shortly.');
        }

        Log::warning('Zaakiy Gemini API request failed.', [
            'model' => $lastModel,
            'status' => $lastResponse->status(),
        ]);

        if ($lastResponse->status() === 429) {
            throw new RuntimeException(
                'Zaakiy is currently busy due to AI rate limits. Please try again in a moment.',
                429,
            );
        }

        throw new RuntimeException('Zaakiy is temporarily unavailable. Please try again shortly.');
    }

    /** @return array<int, string> */
    private function candidateModels(?string $model): array
    {
        $primary = $this->resolveModel($model);
        $fallbacks = config('services.gemini.fallback_models', []);
        if (is_string($fallbacks)) {
            $fallbacks = array_map('trim', explode(',', $fallbacks));
        }

        $models = [$primary];
        foreach ((array) $fallbacks as $fallback) {
            if (is_string($fallback)) {
                $trimmed = trim($fallback);
                if ($trimmed !== '' && ! in_array($trimmed, $models, true)) {
                    $models[] = $trimmed;
                }
            }
        }

        return $models;
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @return array<int, array<string, mixed>>
     */
    private function normalizeContents(array $contents): array
    {
        return array_map(fn (array $content): array => $this->normalizeContent($content), $contents);
    }

    /**
     * Ensure protobuf Struct fields (functionCall.args and functionResponse.response)
     * serialize as JSON objects ({}) rather than empty JSON arrays ([]).
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normalizeContent(array $content): array
    {
        if (! is_array($content['parts'] ?? null)) {
            return $content;
        }

        foreach ($content['parts'] as $index => $part) {
            if (! is_array($part)) {
                continue;
            }
            if (is_array($part['functionCall'] ?? null)) {
                $args = $part['functionCall']['args'] ?? [];
                if ($args === [] || $args === null) {
                    $part['functionCall']['args'] = new stdClass;
                }
            }
            if (is_array($part['functionResponse'] ?? null)) {
                $resp = $part['functionResponse']['response'] ?? [];
                if ($resp === [] || $resp === null) {
                    $part['functionResponse']['response'] = new stdClass;
                }
            }
            $content['parts'][$index] = $part;
        }

        return $content;
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
