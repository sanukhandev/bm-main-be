<?php

namespace Tests\Feature\Api\Zaakiy;

use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\OwnerAgreement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\ZaakiyActionProposal;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\ZaakiyToolRegistry;
use App\Support\Branch\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ZaakiyActionProposalHitlTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->switchUser($this->apiUser);
        Config::set('services.gemini.key', 'test-gemini-key');
        Config::set('services.gemini.model', 'gemini-flash-latest');
        Config::set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        Config::set('services.zaakiy.agentic_enabled', true);
    }

    public function test_proposal_tools_never_mutate_erp_tables_enforce_forbidden_actions_and_cap_proposals_per_turn(): void
    {
        $propertyId = $this->seedProperty($this->branchA, 'P-HITL-101', 'Flat 101');
        $ownerAgreementId = $this->seedDraftOwnerAgreement($this->branchA, $propertyId);

        Http::fakeSequence('*')
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'propose_work_order',
                                'args' => [
                                    'property_id' => $propertyId,
                                    'title' => 'AC leaking in Flat 101',
                                    'priority' => 'high',
                                    'description' => 'Tenant reported water drip from main duct.',
                                ],
                            ],
                            'thoughtSignature' => 'sig_prop_1',
                        ]],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 30],
            ], 200)
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [[
                            'text' => 'I prepared a work order proposal for Flat 101. Please review and confirm.',
                        ]],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 320, 'candidatesTokenCount' => 20],
            ], 200);

        $stream = $this->branchRequest()->postJson('/api/v1/ai/zaakiy/chat', [
            'message' => 'Create a high priority work order for Flat 101 for an AC leak',
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: action_proposal', $stream);
        $this->assertStringContainsString('AC leaking in Flat 101', $stream);

        // Zero direct mutations to ERP tables during agent execution.
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('zaakiy_action_proposals', 1);
        $proposal = ZaakiyActionProposal::query()->firstOrFail();
        $this->assertSame('create_work_order', $proposal->action_type);
        $this->assertSame('pending', $proposal->status);
        $this->assertSame($this->branchA, (int) $proposal->branch_id);
        $this->assertNotNull($proposal->message_id);
        $this->assertTrue($proposal->expires_at->gt(now()->addMinutes(10)));

        // Forbidden destructive agreement lifecycle actions are strictly rejected.
        $branchContext = new BranchContext;
        $branchContext->set(Branch::query()->findOrFail($this->branchA));
        $thread = ZaakiyThread::query()->firstOrFail();

        $terminate = app(ZaakiyToolRegistry::class)->execute(
            'propose_agreement_lifecycle',
            ['agreement_type' => 'owner', 'agreement_id' => $ownerAgreementId, 'action' => 'terminate'],
            $this->apiUser,
            $branchContext,
            $thread,
        );
        $this->assertSame('ACTION_FORBIDDEN', $terminate->errorCode);

        $cancel = app(ZaakiyToolRegistry::class)->execute(
            'propose_agreement_lifecycle',
            ['agreement_type' => 'owner', 'agreement_id' => $ownerAgreementId, 'action' => 'cancel'],
            $this->apiUser,
            $branchContext,
            $thread,
        );
        $this->assertSame('ACTION_FORBIDDEN', $cancel->errorCode);

        // Prefilled navigation returns safe navigation CTA without writing to action proposals.
        $nav = app(ZaakiyToolRegistry::class)->execute(
            'propose_prefilled_navigation',
            ['target' => 'new_tenant_agreement', 'prefill' => ['property_id' => $propertyId]],
            $this->apiUser,
            $branchContext,
            $thread,
        );
        $this->assertNull($nav->errorCode);
        $this->assertSame('/app/tenant-agreements/new', $nav->skillResult?->navigation[0]['route'] ?? null);
        $this->assertDatabaseCount('zaakiy_action_proposals', 1);
    }

    public function test_agent_loop_enforces_max_two_proposals_per_turn(): void
    {
        $propertyId = $this->seedProperty($this->branchA, 'P-CAP-101', 'Flat Cap');

        Http::fakeSequence('*')
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['functionCall' => ['name' => 'propose_work_order', 'args' => ['property_id' => $propertyId, 'title' => 'Task 1', 'priority' => 'low']]],
                            ['functionCall' => ['name' => 'propose_work_order', 'args' => ['property_id' => $propertyId, 'title' => 'Task 2', 'priority' => 'low']]],
                            ['functionCall' => ['name' => 'propose_work_order', 'args' => ['property_id' => $propertyId, 'title' => 'Task 3', 'priority' => 'low']]],
                        ],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 25],
            ], 200)
            ->push([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Prepared 2 proposals; the 3rd exceeded the per-turn limit.']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 180, 'candidatesTokenCount' => 15],
            ], 200);

        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/chat', [
            'message' => 'Create three work orders for Flat Cap',
        ])->assertOk()->streamedContent();

        $this->assertDatabaseCount('zaakiy_action_proposals', 2);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_confirm_executes_once_validates_overrides_and_enforces_replay_expiry_ownership_and_permissions(): void
    {
        $propertyA = $this->seedProperty($this->branchA, 'P-A-201', 'Villa 201');
        $propertyB = $this->seedProperty($this->branchB, 'P-B-901', 'Sharjah Unit');

        $branchContext = new BranchContext;
        $branchContext->set(Branch::query()->findOrFail($this->branchA));
        $thread = $this->seedThread($this->branchA, $this->apiUser->id);

        $registry = app(ZaakiyToolRegistry::class);

        // 1. Propose work order
        $woOut = $registry->execute('propose_work_order', [
            'property_id' => $propertyA,
            'title' => 'Water pipe leak',
            'priority' => 'normal',
        ], $this->apiUser, $branchContext, $thread);
        $woProposal = ZaakiyActionProposal::query()->where('uuid', $woOut->actionProposal['proposal_id'])->firstOrFail();

        // Cross-branch property_id override must fail 422 and keep proposal pending.
        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/actions/'.$woProposal->uuid.'/confirm', [
            'overrides' => ['property_id' => $propertyB],
        ])->assertUnprocessable();
        $this->assertDatabaseHas('zaakiy_action_proposals', ['id' => $woProposal->id, 'status' => 'pending']);
        $this->assertDatabaseCount('work_orders', 0);

        // Another user in the same branch cannot confirm someone else's proposal.
        $otherUser = $this->createBranchUser($this->branchA, ['maintenance.manage', 'accounts.post', 'customers.manage', 'agreements.manage']);
        $this->switchUser($otherUser);
        $this->branchRequest()
            ->postJson('/api/v1/ai/zaakiy/actions/'.$woProposal->uuid.'/confirm')
            ->assertForbidden();

        // Valid confirm with override executes work order once and records HITL audit trail.
        $this->switchUser($this->apiUser);
        $confirmRes = $this->branchRequest()->postJson('/api/v1/ai/zaakiy/actions/'.$woProposal->uuid.'/confirm', [
            'overrides' => ['priority' => 'urgent'],
        ])->assertOk();
        $confirmRes->assertJsonPath('data.status', 'executed');
        $confirmRes->assertJsonPath('data.entity_type', 'work_order');

        $createdOrder = WorkOrder::query()->firstOrFail();
        $this->assertSame('urgent', $createdOrder->priority);
        $this->assertSame($propertyA, (int) $createdOrder->property_id);
        $this->assertTrue(AuditLog::query()->where('action', 'zaakiy.action_confirmed')->exists());

        // Replay must return 409 PROPOSAL_ALREADY_PROCESSED and NOT create a second work order.
        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/actions/'.$woProposal->uuid.'/confirm')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PROPOSAL_ALREADY_PROCESSED');
        $this->assertDatabaseCount('work_orders', 1);

        // 2. Work order status update proposal + confirm
        $statusOut = $registry->execute('propose_work_order_status', [
            'work_order_id' => $createdOrder->id,
            'status' => 'completed',
        ], $this->apiUser, $branchContext, $thread);
        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/actions/'.$statusOut->actionProposal['proposal_id'].'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');
        $this->assertSame('completed', $createdOrder->fresh()->status);

        // 3. Customer draft proposal + confirm (must NEVER set identity_verified_at)
        $custOut = $registry->execute('propose_customer_draft', [
            'customer_type' => 'individual',
            'display_name' => 'Mariam Al Suwaidi',
            'phone' => '+971501234567',
            'roles' => ['tenant'],
        ], $this->apiUser, $branchContext, $thread);
        $custConfirm = $this->branchRequest()->postJson('/api/v1/ai/zaakiy/actions/'.$custOut->actionProposal['proposal_id'].'/confirm')
            ->assertOk();
        $createdCustomer = Customer::query()->findOrFail($custConfirm->json('data.entity_id'));
        $this->assertSame('Mariam Al Suwaidi', $createdCustomer->display_name);
        $this->assertNull($createdCustomer->identity_verified_at);

        // 4. Petty cash proposal: revoked permission returns 403; active permission posts transaction
        $limitedUser = $this->createBranchUser($this->branchA, ['accounts.post']);
        $limitedThread = $this->seedThread($this->branchA, $limitedUser->id);
        $pcOut = $registry->execute('propose_petty_cash_entry', [
            'transaction_date' => now()->toDateString(),
            'direction' => 'outward',
            'amount' => '185.50',
            'particulars' => 'Office stationery',
        ], $limitedUser, $branchContext, $limitedThread);
        $pcUuid = $pcOut->actionProposal['proposal_id'];

        // Revoke accounts.post before confirming
        DB::table('branch_user_roles')->where('user_id', $limitedUser->id)->delete();
        $this->switchUser($limitedUser);
        $this->branchRequest()
            ->postJson('/api/v1/ai/zaakiy/actions/'.$pcUuid.'/confirm')
            ->assertForbidden();
        $this->assertDatabaseCount('account_transactions', 0);

        // Confirm petty cash with authorized user
        $this->switchUser($this->apiUser);
        $pcFullOut = $registry->execute('propose_petty_cash_entry', [
            'transaction_date' => now()->toDateString(),
            'direction' => 'outward',
            'amount' => '185.50',
            'particulars' => 'Office stationery',
        ], $this->apiUser, $branchContext, $thread);
        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/actions/'.$pcFullOut->actionProposal['proposal_id'].'/confirm')
            ->assertOk()
            ->assertJsonPath('data.entity_type', 'account_transaction');
        $this->assertDatabaseCount('account_transactions', 1);
        $tx = AccountTransaction::query()->firstOrFail();
        $this->assertSame('185.50', (string) $tx->amount);

        // 5. Expired proposal returns 409 PROPOSAL_EXPIRED and persists status='expired'
        $expOut = $registry->execute('propose_work_order', [
            'property_id' => $propertyA,
            'title' => 'Expired check',
            'priority' => 'low',
        ], $this->apiUser, $branchContext, $thread);
        $expProposal = ZaakiyActionProposal::query()->where('uuid', $expOut->actionProposal['proposal_id'])->firstOrFail();
        $expProposal->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/actions/'.$expProposal->uuid.'/confirm')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PROPOSAL_EXPIRED');
        $this->assertSame('expired', $expProposal->fresh()->status);

        // 6. Reject endpoint marks status='rejected' and writes audit log
        $rejOut = $registry->execute('propose_work_order', [
            'property_id' => $propertyA,
            'title' => 'Discard this task',
            'priority' => 'low',
        ], $this->apiUser, $branchContext, $thread);
        $rejUuid = $rejOut->actionProposal['proposal_id'];
        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/actions/'.$rejUuid.'/reject')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('zaakiy_action_proposals', ['uuid' => $rejUuid, 'status' => 'rejected']);
        $this->assertTrue(AuditLog::query()->where('action', 'zaakiy.action_rejected')->exists());

        // 7. Agreement lifecycle safe transition (submit draft owner agreement -> pending_approval)
        $ownerAgreementId = $this->seedDraftOwnerAgreement($this->branchA, $propertyA);
        $agrOut = $registry->execute('propose_agreement_lifecycle', [
            'agreement_type' => 'owner',
            'agreement_id' => $ownerAgreementId,
            'action' => 'submit',
        ], $this->apiUser, $branchContext, $thread);
        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/actions/'.$agrOut->actionProposal['proposal_id'].'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');
        $this->assertSame('pending_approval', OwnerAgreement::query()->findOrFail($ownerAgreementId)->status);
    }

    private function seedProperty(int $branchId, string $code, string $name): int
    {
        $ownerId = $this->customer($branchId, 'OWN-'.$code);
        $this->assignCustomerRole($branchId, $ownerId, 'owner');

        return DB::table('properties')->insertGetId([
            'branch_id' => $branchId,
            'owner_customer_id' => $ownerId,
            'property_code' => $code,
            'property_type' => 'apartment',
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedDraftOwnerAgreement(int $branchId, int $propertyId): int
    {
        $ownerId = (int) DB::table('properties')->where('id', $propertyId)->value('owner_customer_id');
        $agreementId = DB::table('owner_agreements')->insertGetId([
            'branch_id' => $branchId,
            'agreement_no' => 'OA-HITL-'.random_int(100, 999),
            'owner_customer_id' => $ownerId,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'total_amount' => 120000,
            'currency_code' => 'AED',
            'payment_count' => 1,
            'payment_mode' => 'cheque',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('owner_agreement_properties')->insert([
            'branch_id' => $branchId,
            'owner_agreement_id' => $agreementId,
            'property_id' => $propertyId,
            'owner_customer_id' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('owner_agreement_installments')->insert([
            'branch_id' => $branchId,
            'owner_agreement_id' => $agreementId,
            'installment_no' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'amount' => 120000,
            'paid_amount' => 0,
            'payment_mode' => 'cheque',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $agreementId;
    }

    private function seedThread(int $branchId, int $userId): ZaakiyThread
    {
        $thread = new ZaakiyThread;
        $thread->forceFill([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $branchId,
            'user_id' => $userId,
            'title' => 'HITL Test Thread',
            'last_message_at' => now(),
        ])->save();

        return $thread;
    }

    /** @param array<int, string> $permissions */
    private function createBranchUser(int $branchId, array $permissions): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'key' => 'hitl_role_'.Str::random(6),
            'name' => 'HITL Role',
            'scope' => 'branch',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($permissions as $permKey) {
            $permId = DB::table('permissions')->where('key', $permKey)->value('id');
            if ($permId) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
            }
        }

        $user = User::query()->create([
            'name' => 'HITL User',
            'email' => 'hitl-'.Str::uuid().'@example.com',
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->where('id', $user->id)->update(['status' => 'active']);
        $user->refresh();

        DB::table('branch_user')->insert([
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'status' => 'active',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('branch_user_roles')->insert([
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
        ]);

        return $user;
    }

    private function switchUser(User $user): void
    {
        auth()->forgetGuards();
        $this->actingAs($user, 'web');
    }
}
