<?php

namespace Tests\Feature\Api\Zaakiy;

use App\Models\ZaakiyMessage;
use App\Models\ZaakiyThread;
use App\Models\ZaakiyToolRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ZaakiyAgentLoopTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->actingAs($this->apiUser, 'web');
        Config::set('services.gemini.key', 'test-gemini-key');
        Config::set('services.gemini.model', 'gemini-flash-latest');
        Config::set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        Config::set('services.zaakiy.agentic_enabled', true);
        Config::set('services.zaakiy.max_tool_turns', 3);
        Config::set('services.zaakiy.max_tool_calls', 5);
    }

    public function test_agent_loop_chains_two_tools_echoes_thought_signature_persists_thread_and_streams_answer(): void
    {
        $ownerId = $this->customer($this->branchA, 'OWN-LOOP-1');
        $this->assignCustomerRole($this->branchA, $ownerId, 'owner');
        $propertyId = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA,
            'owner_customer_id' => $ownerId,
            'property_code' => 'P-LOOP-101',
            'property_type' => 'apartment',
            'name' => 'Flat 101',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fakeSequence('*')
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'lookup_entities',
                                'args' => ['query' => 'Flat 101', 'entity_type' => 'property'],
                            ],
                            'thoughtSignature' => 'sig_turn_1',
                        ]],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 210, 'candidatesTokenCount' => 22],
            ], 200)
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'get_property_360',
                                'args' => ['property_id' => $propertyId],
                            ],
                            'thoughtSignature' => 'sig_turn_2',
                        ]],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 420, 'candidatesTokenCount' => 20],
            ], 200)
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [[
                            'text' => 'Flat 101 is currently vacant with no open work orders.',
                        ]],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 580, 'candidatesTokenCount' => 14],
            ], 200);

        $response = $this->branchRequest()->postJson('/api/v1/ai/zaakiy/chat', [
            'message' => 'What is happening with Flat 101?',
        ]);

        $response->assertOk();
        $stream = $response->streamedContent();

        $this->assertStringContainsString('event: status', $stream);
        $this->assertStringContainsString('Searching branch records...', $stream);
        $this->assertStringContainsString('Loading property 360 profile...', $stream);
        $this->assertStringContainsString('/app/properties/'.$propertyId, $stream);
        $this->assertStringContainsString('Flat 101 is currently vacant with no open work orders.', $stream);
        $this->assertStringContainsString('"thread_id":', $stream);

        $this->assertDatabaseCount('zaakiy_threads', 1);
        $this->assertDatabaseCount('zaakiy_messages', 2);
        $this->assertDatabaseCount('zaakiy_tool_runs', 2);

        $thread = ZaakiyThread::query()->firstOrFail();
        $modelMsg = ZaakiyMessage::query()->where('thread_id', $thread->id)->where('role', 'model')->firstOrFail();
        $this->assertSame('Flat 101 is currently vacant with no open work orders.', $modelMsg->content);
        $this->assertGreaterThan(0, $modelMsg->prompt_tokens);

        $recorded = Http::recorded();
        $this->assertCount(3, $recorded);
        $secondPayload = $recorded[1][0]->data();
        $this->assertStringContainsString('sig_turn_1', json_encode($secondPayload['contents']));
        $thirdPayload = $recorded[2][0]->data();
        $this->assertStringContainsString('sig_turn_2', json_encode($thirdPayload['contents']));
    }

    public function test_agent_loop_enforces_max_tool_turns_and_streams_final_response(): void
    {
        $toolResponse = [
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [[
                        'functionCall' => ['name' => 'search_erp_faq', 'args' => ['topic' => 'cheques']],
                        'thoughtSignature' => 'sig_loop',
                    ]],
                ],
            ]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 10],
        ];

        Http::fakeSequence('*')
            ->push($toolResponse, 200)
            ->push($toolResponse, 200)
            ->push($toolResponse, 200)
            ->push(
                "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Cheques move from received to deposited and cleared.\"}]}}],\"usageMetadata\":{\"promptTokenCount\":350,\"candidatesTokenCount\":12}}\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            );

        $stream = $this->branchRequest()->postJson('/api/v1/ai/zaakiy/chat', [
            'message' => 'Explain the cheque lifecycle.',
        ])->assertOk()->streamedContent();

        $this->assertSame(3, ZaakiyToolRun::query()->count());
        $this->assertStringContainsString('Cheques move from received to deposited and cleared.', $stream);
    }

    public function test_agent_loop_continues_existing_thread_and_rejects_foreign_branch_thread_id(): void
    {
        Http::fakeSequence('*')
            ->push([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'First answer.']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 5],
            ], 200)
            ->push([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Follow-up answer.']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 6],
            ], 200);

        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/chat', [
            'message' => 'Hello Zaakiy',
        ])->assertOk()->streamedContent();

        $thread = ZaakiyThread::query()->firstOrFail();

        $followUpStream = $this->branchRequest()->postJson('/api/v1/ai/zaakiy/chat', [
            'message' => 'Follow up question',
            'thread_id' => $thread->uuid,
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('Follow-up answer.', $followUpStream);
        $this->assertDatabaseCount('zaakiy_threads', 1);
        $this->assertDatabaseCount('zaakiy_messages', 4);

        $recorded = Http::recorded();
        $secondContents = $recorded[1][0]->data()['contents'];
        $this->assertCount(3, $secondContents);
        $this->assertSame('Hello Zaakiy', $secondContents[0]['parts'][0]['text']);
        $this->assertSame('First answer.', $secondContents[1]['parts'][0]['text']);
        $this->assertSame('Follow up question', $secondContents[2]['parts'][0]['text']);

        // Cross-branch thread_id must be rejected cleanly with 404.
        $this->branchRequest($this->branchB)->postJson('/api/v1/ai/zaakiy/chat', [
            'message' => 'Trying to read branch A thread',
            'thread_id' => $thread->uuid,
        ])->assertNotFound();
    }
}
