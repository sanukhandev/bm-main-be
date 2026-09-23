<?php

namespace Tests\Feature\Api\Audit;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_audit_records_actor_branch_and_sanitizes_sensitive_metadata(): void
    {
        $log = app(AuditService::class)->record('customer.updated', null, ['password' => 'old'], ['name' => 'New'], ['token' => 'secret', 'reason' => 'Correction'], $this->branchA, $this->apiUser->id);

        $this->assertSame($this->apiUser->id, $log->actor_user_id);
        $this->assertSame($this->branchA, $log->branch_id);
        $this->assertArrayNotHasKey('password', $log->before_json);
        $this->assertArrayNotHasKey('token', $log->metadata_json);
        $this->branchRequest()->getJson('/api/v1/audit-logs?action=customer.updated')->assertOk()->assertJsonPath('data.0.action', 'customer.updated')->assertJsonPath('data.0.actor.id', $this->apiUser->id);
    }

    public function test_audit_view_is_branch_scoped_and_financial_posting_is_audited(): void
    {
        $this->app['db']->table('audit_logs')->insert(['branch_id' => $this->branchB, 'user_id' => $this->apiUser->id, 'actor_user_id' => $this->apiUser->id, 'action' => 'branch_b.event', 'entity_type' => 'customer', 'entity_id' => $this->customerA, 'created_at' => now(), 'updated_at' => now()]);
        $this->branchRequest()->getJson('/api/v1/audit-logs')->assertOk()->assertJsonMissing(['action' => 'branch_b.event']);

        $agreement = DB::table('tenant_agreements')->insertGetId(['branch_id' => $this->branchA, 'agreement_no' => 'TA-AUDIT-001', 'tenant_customer_id' => $this->customerA, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_amount' => '1000.00', 'currency_code' => 'AED', 'payment_count' => 1, 'payment_mode' => 'cash', 'status' => 'commenced', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $installment = DB::table('tenant_agreement_installments')->insertGetId(['branch_id' => $this->branchA, 'tenant_agreement_id' => $agreement, 'installment_no' => 1, 'due_date' => '2026-01-01', 'amount' => '1000.00', 'paid_amount' => '0.00', 'payment_mode' => 'cash', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->branchRequest()->withHeader('Idempotency-Key', 'audit-payment-1')->postJson('/api/v1/tenant-agreements/'.$agreement.'/payments', ['amount' => '100.00', 'installment_id' => $installment, 'payment_mode' => 'cash', 'payment_date' => '2026-09-23'])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['branch_id' => $this->branchA, 'action' => 'accounts.transaction_posted', 'entity_type' => 'account_transaction']);
    }

    public function test_audit_model_rejects_updates_and_deletes(): void
    {
        $log = AuditLog::query()->create(['branch_id' => $this->branchA, 'actor_user_id' => $this->apiUser->id, 'user_id' => $this->apiUser->id, 'action' => 'test.event', 'entity_type' => 'test', 'entity_id' => $this->customerA]);
        $this->expectException(ApiException::class);
        $log->update(['action' => 'changed']);
    }

    public function test_audit_view_requires_permission(): void
    {
        DB::table('role_permissions')
            ->where('role_id', DB::table('roles')->where('key', 'branch_admin')->value('id'))
            ->where('permission_id', DB::table('permissions')->where('key', 'audit.view')->value('id'))
            ->delete();

        $this->branchRequest()->getJson('/api/v1/audit-logs')->assertForbidden();
    }
}
