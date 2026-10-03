<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\Skills\VacancyAnalysisSkill;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class VacancyAnalysisSkillTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_occupancy_summary_is_property_level_and_branch_scoped(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $occupied = $this->property('P-A-OCC', 'Occupied Flat');
        $vacant = $this->property('P-A-VAC', 'Vacant Flat');
        $future = $this->property('P-A-FUT', 'Future Flat');
        $this->tenantAgreement($occupied, now()->subMonth()->toDateString(), now()->addMonth()->toDateString(), 'TA-OCC');
        $this->tenantAgreement($future, now()->addDay()->toDateString(), now()->addMonths(2)->toDateString(), 'TA-FUT');
        $this->property('P-B-OTHER', 'Other Branch Property', $this->branchB, $this->customerB);

        $result = $this->executeSkill('occupancy_summary');

        $this->assertInstanceOf(ZaakiySkillResult::class, $result);
        $this->assertSame(3, $result->summaryMetrics['eligible_property_count']);
        $this->assertSame(1, $result->summaryMetrics['occupied_property_count']);
        $this->assertSame(1, $result->summaryMetrics['vacant_property_count']);
        $this->assertSame(1, $result->summaryMetrics['future_occupied_property_count']);
        $this->assertSame(33.33, $result->summaryMetrics['occupancy_rate']);
    }

    public function test_vacancy_duration_maintenance_and_availability_are_deterministic(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $property = $this->property('P-A-LONG', 'Long Vacancy');
        $this->ownerAgreement($property, now()->addMonths(6)->toDateString());
        $this->tenantAgreement($property, now()->subDays(91)->toDateString(), now()->subDays(31)->toDateString(), 'TA-OLD');
        $result = $this->executeSkill('vacancy_analysis');
        $record = $result->records[0];

        $this->assertSame('vacant', $record['occupancy_status']);
        $this->assertSame(31, $record['vacancy_days']);
        $this->assertSame(0, $record['open_work_order_count']);
        $this->assertTrue($record['available_for_leasing']);
        $this->assertSame(0, $result->summaryMetrics['vacant_properties_with_open_work_orders']);
        $this->assertSame('31-60', collect($result->breakdowns['vacancy_duration'])->firstWhere('bucket', '31-60')['bucket']);
    }

    public function test_upcoming_vacancy_excludes_continuous_future_occupancy(): void
    {
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $property = $this->property('P-A-UPCOMING', 'Upcoming Vacancy');
        $this->tenantAgreement($property, now()->subMonth()->toDateString(), now()->addDays(10)->toDateString(), 'TA-END');
        $this->tenantAgreement($property, now()->addDays(11)->toDateString(), now()->addMonths(2)->toDateString(), 'TA-CONTINUE');

        $result = $this->executeSkill('upcoming_vacancy', [], ['from' => now()->toDateString(), 'to' => now()->addDays(30)->toDateString()]);

        $this->assertSame(0, $result->summaryMetrics['upcoming_vacancy_count']);
        $this->assertSame([], $result->records);
    }

    private function executeSkill(string $intent, array $filters = [], ?array $range = null): ZaakiySkillResult
    {
        $branch = new BranchContext;
        $branch->set(Branch::query()->findOrFail($this->branchA));

        return app(VacancyAnalysisSkill::class)->execute(new ZaakiyExecutionContext(
            $this->apiUser,
            $branch,
            new IntentFrame($intent, ['vacancy_analysis'], 'search', filters: $filters, timeRange: $range, question: 'Show vacant properties'),
            now()->toIso8601String(),
        ));
    }

    private function property(string $code, string $name, ?int $branchId = null, ?int $ownerId = null): int
    {
        return DB::table('properties')->insertGetId([
            'branch_id' => $branchId ?? $this->branchA,
            'owner_customer_id' => $ownerId ?? $this->customerA,
            'property_code' => $code,
            'unit_number' => '204',
            'property_type' => 'apartment',
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ownerAgreement(int $propertyId, string $endDate): int
    {
        $id = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'OA-VAC-'.$propertyId,
            'owner_customer_id' => $this->customerA,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => $endDate,
            'total_amount' => 10000,
            'currency_code' => 'AED',
            'payment_count' => 1,
            'payment_mode' => 'bank_transfer',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('owner_agreement_properties')->insert(['branch_id' => $this->branchA, 'owner_agreement_id' => $id, 'property_id' => $propertyId, 'owner_customer_id' => $this->customerA, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function tenantAgreement(int $propertyId, string $start, string $end, string $number): int
    {
        $ownerId = DB::table('owner_agreement_properties')->where('branch_id', $this->branchA)->where('property_id', $propertyId)->value('owner_agreement_id');
        if ($ownerId === null) {
            $ownerId = $this->ownerAgreement($propertyId, now()->addYear()->toDateString());
        }
        $id = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => $number,
            'tenant_customer_id' => $this->customerA,
            'start_date' => $start,
            'end_date' => $end,
            'total_amount' => 10000,
            'currency_code' => 'AED',
            'payment_count' => 1,
            'payment_mode' => 'cash',
            'status' => 'commenced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_agreement_properties')->insert(['branch_id' => $this->branchA, 'tenant_agreement_id' => $id, 'property_id' => $propertyId, 'source_owner_agreement_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
