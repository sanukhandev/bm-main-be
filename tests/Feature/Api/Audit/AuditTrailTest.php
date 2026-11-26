<?php

namespace Tests\Feature\Api\Audit;

use App\Exceptions\ApiException;
use App\Jobs\RecordUserActivity;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Role;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $log = app(AuditService::class)->record('customer.updated', null, ['password' => 'old'], ['name' => 'New'], ['token' => 'secret', 'reason' => 'Correction', 'page' => '/app/customers/1?identity_no=secret', 'nested' => ['emirates_id' => '784-1990-1234567-1']], $this->branchA, $this->apiUser->id);

        $this->assertSame($this->apiUser->id, $log->actor_user_id);
        $this->assertSame($this->branchA, $log->branch_id);
        $this->assertArrayNotHasKey('password', $log->before_json);
        $this->assertArrayNotHasKey('token', $log->metadata_json);
        $this->assertSame('***-****-****567-1', $log->metadata_json['nested']['emirates_id']);
        $this->assertSame('/app/customers/1', $log->metadata_json['page']);
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

    public function test_user_activity_is_queued_without_blocking_the_request(): void
    {
        Queue::fake();

        $this->branchRequest()->postJson('/api/v1/activity-logs', [
            'action' => 'opened',
            'page' => '/app/customers/1',
            'metadata' => ['view_mode' => 'compact'],
        ])->assertStatus(202);

        Queue::assertPushedOn('activity', RecordUserActivity::class, fn ($job) => $job->action === 'activity.opened'
            && $job->branchId === $this->branchA
            && $job->actorId === $this->apiUser->id
            && $job->metadata['page'] === '/app/customers/1');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'activity.opened']);
    }

    public function test_user_activity_rejects_mutation_verbs_and_reserved_metadata_keys(): void
    {
        $this->branchRequest()->postJson('/api/v1/activity-logs', [
            'action' => 'deleted',
            'page' => '/app/customers/1',
        ])->assertUnprocessable();

        $this->branchRequest()->postJson('/api/v1/activity-logs', [
            'action' => 'viewed',
            'page' => '/app/customers/1',
            'metadata' => ['page' => '/forged', 'bad.key' => 'x'],
        ])->assertUnprocessable();
    }

    public function test_only_super_admin_can_request_all_branch_activity(): void
    {
        $this->app['db']->table('audit_logs')->insert([
            'branch_id' => $this->branchA,
            'actor_user_id' => $this->apiUser->id,
            'user_id' => $this->apiUser->id,
            'action' => 'activity.opened',
            'metadata_json' => json_encode(['page' => '/app/dashboard']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->branchRequest()->getJson('/api/v1/audit-logs')->assertOk()->assertJsonMissing(['action' => 'activity.opened']);

        $this->app['db']->table('audit_logs')->insert([
            'branch_id' => $this->branchB,
            'actor_user_id' => $this->apiUser->id,
            'user_id' => $this->apiUser->id,
            'action' => 'activity.viewed',
            'metadata_json' => json_encode(['page' => '/app/dashboard']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branchRequest()->getJson('/api/v1/audit-logs?all_branches=1')->assertForbidden();

        DB::table('user_global_roles')->insert([
            'user_id' => $this->apiUser->id,
            'role_id' => DB::table('roles')->where('key', 'super_admin')->value('id'),
        ]);

        $this->branchRequest()->getJson('/api/v1/audit-logs?all_branches=true')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'activity.viewed')
            ->assertJsonPath('data.0.branch.id', $this->branchB);
    }

    public function test_system_roles_cannot_be_updated(): void
    {
        DB::table('user_global_roles')->insert([
            'user_id' => $this->apiUser->id,
            'role_id' => DB::table('roles')->where('key', 'super_admin')->value('id'),
        ]);

        $roleId = DB::table('roles')->where('key', 'super_admin')->value('id');
        $this->branchRequest()->patchJson('/api/v1/admin/roles/'.$roleId, [
            'name' => 'Super Admin',
            'description' => 'Updated description',
            'permission_keys' => ['audit.view'],
        ])->assertStatus(422)->assertJsonPath('code', 'SYSTEM_ROLE_IMMUTABLE');
    }

    public function test_role_mass_assignment_cannot_escalate_scope_or_system_status(): void
    {
        $role = new Role(['key' => 'forged', 'name' => 'Forged', 'scope' => 'global', 'is_system' => true]);

        $this->assertNull($role->scope);
        $this->assertNull($role->is_system);
    }

    public function test_account_transaction_mass_assignment_cannot_set_posting_metadata(): void
    {
        $transaction = new AccountTransaction([
            'branch_id' => $this->branchA,
            'status' => 'posted',
            'created_by' => $this->apiUser->id,
            'posted_by' => $this->apiUser->id,
            'idempotency_key' => 'forged',
        ]);

        $this->assertNull($transaction->branch_id);
        $this->assertNull($transaction->status);
        $this->assertNull($transaction->created_by);
        $this->assertNull($transaction->idempotency_key);
    }

    public function test_audit_identity_backfill_redacts_nested_values_and_fails_on_malformed_json(): void
    {
        $id = DB::table('audit_logs')->insertGetId([
            'branch_id' => $this->branchA, 'action' => 'customer.updated',
            'after_json' => json_encode(['nested' => ['identity_no' => '784-1990-1234567-1']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_11_26_000003_redact_identity_numbers_in_audit_logs.php');
        $migration->up();
        $stored = json_decode(DB::table('audit_logs')->where('id', $id)->value('after_json'), true);
        $this->assertSame('***-****-****567-1', $stored['nested']['identity_no']);

        if (DB::getDriverName() === 'sqlite') {
            DB::table('audit_logs')->insert(['branch_id' => $this->branchA, 'action' => 'bad.json', 'after_json' => '{', 'created_at' => now(), 'updated_at' => now()]);
            $this->expectException(\RuntimeException::class);
            $migration->up();
        } else {
            $this->expectException(QueryException::class);
            DB::table('audit_logs')->insert(['branch_id' => $this->branchA, 'action' => 'bad.json', 'after_json' => '{', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
