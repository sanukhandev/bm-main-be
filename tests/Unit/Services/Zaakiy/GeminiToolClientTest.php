<?php

namespace Tests\Unit\Services\Zaakiy;

use App\Services\Zaakiy\Agent\GeminiToolClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiToolClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.gemini.key', 'test-gemini-key');
        Config::set('services.gemini.model', 'gemini-flash-latest');
        Config::set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
    }

    public function test_plan_turn_uses_header_key_sends_function_declarations_and_preserves_thought_signature(): void
    {
        Http::fake([
            '*generateContent' => Http::response([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'get_collections_health',
                                'args' => ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'],
                            ],
                            'thoughtSignature' => 'enc_sig_abc123',
                        ]],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 310, 'candidatesTokenCount' => 28],
            ], 200),
        ]);

        $declarations = [[
            'name' => 'get_collections_health',
            'description' => 'Read inward collections health for the active branch.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'date_from' => ['type' => 'STRING'],
                    'date_to' => ['type' => 'STRING'],
                ],
            ],
        ]];

        $turn = app(GeminiToolClient::class)->planTurn(
            'System prompt',
            [['role' => 'user', 'parts' => [['text' => 'Show collections for October.']]]],
            $declarations,
        );

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ! str_contains($request->url(), 'key=')
                && $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && ($data['tools'][0]['functionDeclarations'][0]['name'] ?? null) === 'get_collections_health'
                && ($data['toolConfig']['functionCallingConfig']['mode'] ?? null) === 'AUTO';
        });

        $this->assertSame('enc_sig_abc123', $turn->rawModelContent['parts'][0]['thoughtSignature']);
        $this->assertCount(1, $turn->functionCalls);
        $this->assertSame('get_collections_health', $turn->functionCalls[0]['name']);
        $this->assertSame(['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], $turn->functionCalls[0]['args']);
        $this->assertSame(310, $turn->promptTokens);
        $this->assertSame(28, $turn->candidatesTokens);
    }

    public function test_stream_final_emits_tokens_and_captures_usage_metadata(): void
    {
        Http::fake([
            '*streamGenerateContent*' => Http::response(
                "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hello \"}]}}]}\n\n"
                ."data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Dubai Branch.\"}]}}],\"usageMetadata\":{\"promptTokenCount\":420,\"candidatesTokenCount\":14}}\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $chunks = [];
        $result = app(GeminiToolClient::class)->streamFinal(
            'System prompt',
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            function (string $delta) use (&$chunks): void {
                $chunks[] = $delta;
            },
        );

        $this->assertSame(['Hello ', 'Dubai Branch.'], $chunks);
        $this->assertSame('Hello Dubai Branch.', $result['text']);
        $this->assertSame(420, $result['prompt_tokens']);
        $this->assertSame(14, $result['candidates_tokens']);
        $this->assertSame('gemini-flash-latest', $result['model']);
    }
}
