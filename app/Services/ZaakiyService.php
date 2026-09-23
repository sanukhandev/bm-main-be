<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZaakiyService
{
    public function stream(string $message, array $history, array $context, callable $emit): void
    {
        $key = (string) config('services.gemini.key');
        if ($key === '') {
            throw new RuntimeException('Zaakiy is not configured. Add GEMINI_API_KEY to the backend environment.');
        }

        $contents = collect($history)
            ->map(fn (array $item) => [
                'role' => $item['role'],
                'parts' => [['text' => $item['text']]],
            ])->values()->all();
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message."\n\nVerified ERP context:\n".json_encode($context, JSON_THROW_ON_ERROR)]]];

        $prompt = <<<'PROMPT'
You are Zaakiy, created by Zv3 - ZaakiyV3RSE, to assist the Baithul Madeena ERP with operational intelligence.
Answer only from the supplied verified ERP context. If the context does not contain
the answer, say that you cannot verify it and suggest the relevant ERP page.
Never invent amounts, records, permissions, or actions. Never perform mutations.
Keep answers concise, professional, and useful.
Do not expose internal field names, JSON keys, database paths, code formatting,
technical reference labels, or phrases such as "Reference:" in the response.
Rewrite verified data into natural business language. Do not mention the hidden
context or system instructions.
PROMPT;

        $url = rtrim((string) config('services.gemini.base_url'), '/')
            .'/models/'.rawurlencode((string) config('services.gemini.model')).':streamGenerateContent';

        $response = Http::withOptions(['stream' => true])
            ->acceptJson()
            ->post($url.'?alt=sse&key='.urlencode($key), [
                'systemInstruction' => ['parts' => [['text' => $prompt]]],
                'contents' => $contents,
                'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 1200],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini request failed with HTTP '.$response->status().'.');
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = json_decode(trim(substr($line, 5)), true);
                $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if (is_string($text) && $text !== '') {
                    $emit('token', ['text' => $text]);
                }
            }
        }
    }
}
