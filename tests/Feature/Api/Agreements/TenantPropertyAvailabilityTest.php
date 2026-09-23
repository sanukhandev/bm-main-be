<?php

namespace Tests\Feature\Api\Agreements;

use App\Exceptions\ApiException;
use App\Services\PropertyAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class TenantPropertyAvailabilityTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    private int $tenantId;

    private int $ownerAgreementId;

    private int $propertyA;

    private int $propertyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->tenantId = $this->customer($this->branchA, 'A-TENANT');
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->assignCustomerRole($this->branchA, $this->tenantId, 'tenant');
        $this->propertyA = $this->property('A-PROP-001');
        $this->propertyB = $this->property('A-PROP-002');
        $this->ownerAgreementId = $this->ownerAgreement('2026-01-01', '2026-12-31', [$this->propertyA, $this->propertyB]);
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_inclusive_boundaries_and_containment_are_unavailable(): void
    {
        $this->tenantAgreement('approved', '2026-04-01', '2026-05-31', $this->propertyA);
        $service = app(PropertyAvailabilityService::class);

        foreach ([
            ['2026-01-01', '2026-03-31'],
            ['2026-06-01', '2026-12-31'],
        ] as [$start, $end]) {
            $service->assertAvailable($this->branchA, [$this->propertyA], CarbonImmutable::parse($start), CarbonImmutable::parse($end));
        }

        foreach ([
            ['2026-05-31', '2026-06-30'],
            ['2026-03-01', '2026-04-01'],
            ['2026-04-15', '2026-05-15'],
            ['2026-01-01', '2026-12-31'],
            ['2026-04-01', '2026-05-31'],
        ] as [$start, $end]) {
            try {
                $service->assertAvailable($this->branchA, [$this->propertyA], CarbonImmutable::parse($start), CarbonImmutable::parse($end));
                $this->fail('Expected an overlap for '.$start.' to '.$end);
            } catch (ApiException $exception) {
                $this->assertSame('PROPERTY_NOT_AVAILABLE', $exception->errorCode);
            }
        }
    }

    public function test_draft_and_terminal_tenant_agreements_do_not_block(): void
    {
        $service = app(PropertyAvailabilityService::class);
        foreach (['draft', 'cancelled', 'terminated', 'expired'] as $status) {
            $this->tenantAgreement($status, '2026-04-01', '2026-05-31', $this->propertyA);
        }

        $service->assertAvailable($this->branchA, [$this->propertyA], CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-05-31'));
        $this->assertTrue(true);
    }

    public function test_blocking_statuses_block_and_update_excludes_itself(): void
    {
        foreach (['pending_approval', 'approved', 'commenced', 'on_hold'] as $status) {
            $this->tenantAgreement($status, '2026-04-01', '2026-05-31', $this->propertyA);
        }

        $service = app(PropertyAvailabilityService::class);
        try {
            $service->assertAvailable($this->branchA, [$this->propertyA], CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-05-31'));
            $this->fail('Expected a blocking tenant agreement conflict.');
        } catch (ApiException $exception) {
            $this->assertSame('PROPERTY_NOT_AVAILABLE', $exception->errorCode);
        }
    }

    public function test_available_endpoint_is_date_validated_and_branch_scoped(): void
    {
        $this->branchRequest()->getJson('/api/v1/properties/available?start_date=2026-02-01&end_date=2026-02-28&source_owner_agreement_id='.$this->ownerAgreementId)
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->propertyA);

        $this->branchRequest()->getJson('/api/v1/properties/available?start_date=2026-03-01&end_date=2026-02-01')
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_overlapping_create_fails_and_multi_property_create_is_atomic(): void
    {
        $this->tenantAgreement('approved', '2026-04-01', '2026-05-31', $this->propertyA);
        $payload = [
            'tenant_customer_id' => $this->tenantId,
            'properties' => [
                ['property_id' => $this->propertyA, 'source_owner_agreement_id' => $this->ownerAgreementId],
                ['property_id' => $this->propertyB, 'source_owner_agreement_id' => $this->ownerAgreementId],
            ],
            'start_date' => '2026-04-15', 'end_date' => '2026-05-15', 'total_amount' => '24000.00',
            'currency_code' => 'AED', 'payment_count' => 12, 'payment_mode' => 'cash',
        ];

        $this->branchRequest()->postJson('/api/v1/tenant-agreements', $payload)
            ->assertStatus(409)->assertJsonPath('code', 'PROPERTY_NOT_AVAILABLE');
        $this->assertDatabaseMissing('tenant_agreements', ['tenant_customer_id' => $this->tenantId, 'start_date' => '2026-04-15']);
    }

    public function test_owner_coverage_and_dates_are_enforced(): void
    {
        $payload = [
            'tenant_customer_id' => $this->tenantId,
            'properties' => [['property_id' => $this->propertyA, 'source_owner_agreement_id' => $this->ownerAgreementId]],
            'start_date' => '2025-12-01', 'end_date' => '2026-02-01', 'total_amount' => '1000.00',
            'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash',
        ];
        $this->branchRequest()->postJson('/api/v1/tenant-agreements', $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'OWNER_AGREEMENT_DATE_COVERAGE_REQUIRED');
    }

    public function test_multi_property_create_succeeds_when_all_properties_are_free(): void
    {
        $response = $this->branchRequest()->postJson('/api/v1/tenant-agreements', [
            'tenant_customer_id' => $this->tenantId,
            'properties' => [
                ['property_id' => $this->propertyA, 'source_owner_agreement_id' => $this->ownerAgreementId],
                ['property_id' => $this->propertyB, 'source_owner_agreement_id' => $this->ownerAgreementId],
            ],
            'start_date' => '2026-02-01', 'end_date' => '2026-03-31', 'total_amount' => '24000.00',
            'currency_code' => 'AED', 'payment_count' => 2, 'payment_mode' => 'cash',
        ])->assertCreated();

        $this->assertCount(2, $response->json('data.properties'));
    }

    public function test_same_agreement_is_excluded_when_rechecking_an_update(): void
    {
        $agreementId = $this->tenantAgreement('approved', '2026-04-01', '2026-05-31', $this->propertyA);

        app(PropertyAvailabilityService::class)->assertAvailable(
            $this->branchA,
            [$this->propertyA],
            CarbonImmutable::parse('2026-04-01'),
            CarbonImmutable::parse('2026-05-31'),
            $agreementId,
        );
        $this->assertTrue(true);
    }

    private function property(string $code): int
    {
        return DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => $code,
            'property_type' => 'apartment', 'name' => $code, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ownerAgreement(string $start, string $end, array $properties): int
    {
        $id = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA, 'agreement_no' => 'OA-'.uniqid(), 'owner_customer_id' => $this->customerA,
            'start_date' => $start, 'end_date' => $end, 'total_amount' => '12000.00', 'currency_code' => 'AED',
            'payment_count' => 12, 'payment_mode' => 'cash', 'status' => 'approved', 'lock_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($properties as $propertyId) {
            DB::table('owner_agreement_properties')->insert([
                'branch_id' => $this->branchA, 'owner_agreement_id' => $id, 'property_id' => $propertyId,
                'owner_customer_id' => $this->customerA, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function tenantAgreement(string $status, string $start, string $end, int $propertyId): int
    {
        $id = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA, 'agreement_no' => 'TA-'.uniqid(), 'tenant_customer_id' => $this->tenantId,
            'start_date' => $start, 'end_date' => $end, 'total_amount' => '12000.00', 'currency_code' => 'AED',
            'payment_count' => 12, 'payment_mode' => 'cash', 'status' => $status, 'lock_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tenant_agreement_properties')->insert([
            'branch_id' => $this->branchA, 'tenant_agreement_id' => $id, 'property_id' => $propertyId,
            'source_owner_agreement_id' => $this->ownerAgreementId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
