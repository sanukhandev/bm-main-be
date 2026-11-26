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
        Config::set('services.gemini.fallback_models', []);
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

    public function test_empty_function_call_args_and_response_serialize_as_json_objects(): void
    {
        Http::fakeSequence('*')
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [[
                            'functionCall' => ['name' => 'get_management_briefing', 'args' => []],
                            'thoughtSignature' => 'sig_empty_args',
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Briefing complete.']]],
                ]],
            ], 200);

        $client = app(GeminiToolClient::class);
        $turn1 = $client->planTurn('System', [['role' => 'user', 'parts' => [['text' => 'Briefing']]]], []);

        $this->assertStringContainsString('"args":{}', json_encode($turn1->rawModelContent, JSON_THROW_ON_ERROR));

        $turn2 = $client->planTurn('System', [
            ['role' => 'user', 'parts' => [['text' => 'Briefing']]],
            $turn1->rawModelContent,
            ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'get_management_briefing', 'response' => []]]]],
        ], []);

        $this->assertSame('Briefing complete.', $turn2->text);
        $recorded = Http::recorded();
        $secondBody = $recorded[1][0]->body();
        $this->assertStringContainsString('"args":{}', $secondBody);
        $this->assertStringContainsString('"response":{}', $secondBody);
    }

    public function test_plan_turn_retries_429_three_times_and_succeeds_on_third_retry(): void
    {
        Config::set('services.gemini.rate_limit_retry_delay_ms', 0);

        Http::fakeSequence('*')
            ->push(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429)
            ->push(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429)
            ->push(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429)
            ->push([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Recovered after retries.']]],
                ]],
            ], 200);

        $turn = app(GeminiToolClient::class)->planTurn(
            'System',
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            [],
        );

        $this->assertSame('Recovered after retries.', $turn->text);
        $this->assertCount(4, Http::recorded());
    }

    public function test_plan_turn_throws_try_again_error_after_all_three_429_retries_fail(): void
    {
        Config::set('services.gemini.rate_limit_retry_delay_ms', 0);

        Http::fakeSequence('*')
            ->push(['error' => ['code' => 429]], 429)
            ->push(['error' => ['code' => 429]], 429)
            ->push(['error' => ['code' => 429]], 429)
            ->push(['error' => ['code' => 429]], 429);

        try {
            app(GeminiToolClient::class)->planTurn(
                'System',
                [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
                [],
            );
            $this->fail('Expected RuntimeException after 3 retries on 429.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(429, $exception->getCode());
            $this->assertStringContainsString('try again', strtolower($exception->getMessage()));
            $this->assertCount(4, Http::recorded());
        }
    }

    public function test_plan_turn_fails_over_to_configured_fallback_model_on_429_and_503(): void
    {
        Config::set('services.gemini.model', 'gemini-3.5-flash-lite');
        Config::set('services.gemini.fallback_models', ['gemini-flash-lite-latest', 'gemini-3.1-flash-lite']);
        Config::set('services.gemini.rate_limit_retry_delay_ms', 0);

        Http::fakeSequence('*')
            ->push(['error' => ['code' => 429]], 429)
            ->push(['error' => ['code' => 503]], 503)
            ->push([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Served by third fallback model.']]],
                ]],
            ], 200);

        $turn = app(GeminiToolClient::class)->planTurn(
            'System',
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            [],
        );

        $this->assertSame('Served by third fallback model.', $turn->text);
        $this->assertSame('gemini-3.1-flash-lite', $turn->model);

        $recorded = Http::recorded();
        $this->assertCount(3, $recorded);
        $this->assertStringContainsString('models/gemini-3.5-flash-lite:generateContent', $recorded[0][0]->url());
        $this->assertStringContainsString('models/gemini-flash-lite-latest:generateContent', $recorded[1][0]->url());
        $this->assertStringContainsString('models/gemini-3.1-flash-lite:generateContent', $recorded[2][0]->url());
    }

    public function test_stream_final_fails_over_to_fallback_model_on_503(): void
    {
        Config::set('services.gemini.model', 'gemini-3.5-flash-lite');
        Config::set('services.gemini.fallback_models', ['gemini-flash-lite-latest']);

        Http::fakeSequence('*')
            ->push(['error' => ['code' => 503]], 503)
            ->push(
                "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Streamed from fallback.\"}]}}]}\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            );

        $chunks = [];
        $result = app(GeminiToolClient::class)->streamFinal(
            'System',
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            function (string $delta) use (&$chunks): void {
                $chunks[] = $delta;
            },
        );

        $this->assertSame(['Streamed from fallback.'], $chunks);
        $this->assertSame('gemini-flash-lite-latest', $result['model']);
    }
}
