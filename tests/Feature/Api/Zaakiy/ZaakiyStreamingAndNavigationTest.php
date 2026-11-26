<?php

namespace Tests\Feature\Api\Zaakiy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ZaakiyStreamingAndNavigationTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->actingAs($this->apiUser, 'web');
        Config::set('services.gemini.key', 'test-gemini-key');
        Config::set('services.gemini.model', 'gemini-3.1-flash-lite');
        Config::set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
    }

    public function test_chat_stream_emits_initial_status_all_deduplicated_navigations_and_strips_navigations_from_prompt(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'tenant');
        $ownerId = $this->customer($this->branchA, 'OWN-A-01');
        $this->assignCustomerRole($this->branchA, $ownerId, 'owner');

        $propertyId = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA,
            'owner_customer_id' => $ownerId,
            'property_code' => 'P-A-101',
            'property_type' => 'apartment',
            'name' => 'Flat 101',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownerAgreementId = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'OA-A-001',
            'owner_customer_id' => $ownerId,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'total_amount' => 100000,
            'currency_code' => 'AED',
            'payment_count' => 4,
            'payment_mode' => 'cheque',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('owner_agreement_properties')->insert([
            'branch_id' => $this->branchA,
            'owner_agreement_id' => $ownerAgreementId,
            'property_id' => $propertyId,
            'owner_customer_id' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantAgreementId = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'TA-A-001',
            'tenant_customer_id' => $this->customerA,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'total_amount' => 60000,
            'currency_code' => 'AED',
            'payment_count' => 4,
            'payment_mode' => 'cheque',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_agreement_properties')->insert([
            'branch_id' => $this->branchA,
            'tenant_agreement_id' => $tenantAgreementId,
            'property_id' => $propertyId,
            'source_owner_agreement_id' => $ownerAgreementId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            '*streamGenerateContent*' => Http::response(
                "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Tenant A-001 is active on Flat 101.\"}]}}]}\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $response = $this->branchRequest()->postJson('/api/v1/ai/zaakiy/chat', [
            'message' => 'Show tenant A-001.',
        ]);

        $response->assertOk();
        $stream = $response->streamedContent();

        $statusPos = strpos($stream, "event: status\n");
        $summaryPos = strpos($stream, "event: summary\n");
        $tokenPos = strpos($stream, "event: token\n");

        $this->assertNotFalse($statusPos, 'Expected an initial status SSE event.');
        $this->assertNotFalse($summaryPos, 'Expected a summary SSE event.');
        $this->assertNotFalse($tokenPos, 'Expected a token SSE event.');
        $this->assertLessThan($summaryPos, $statusPos, 'Status event must flush before structured blocks.');
        $this->assertLessThan($tokenPos, $summaryPos);

        $this->assertStringContainsString('/app/customers/'.$this->customerA, $stream);
        $this->assertStringContainsString('/app/tenant-agreements/'.$tenantAgreementId, $stream);
        $this->assertStringContainsString('/app/properties/'.$propertyId, $stream);
        $this->assertStringContainsString('/app/reports/tenant-outstanding', $stream);

        Http::assertSent(function ($request): bool {
            $body = (string) ($request->data()['contents'][0]['parts'][0]['text'] ?? '');

            return ! str_contains($body, '"navigations"');
        });
    }
}
