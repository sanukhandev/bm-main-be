<?php

namespace Tests\Feature\Api\Zaakiy;

use App\Models\User;
use App\Models\ZaakiyActionProposal;
use App\Models\ZaakiyMessage;
use App\Models\ZaakiyThread;
use App\Models\ZaakiyToolRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ZaakiyAdminMetricsTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
    }

    public function test_non_super_admin_cannot_access_zaakiy_metrics_and_super_admin_receives_exact_aggregates(): void
    {
        // Branch admin (not super admin) must receive 403.
        $this->actingAs($this->apiUser, 'web')
            ->getJson('/api/v1/admin/zaakiy/metrics')
            ->assertForbidden();

        // Seed threads, messages, tool runs, and proposals across Branch A and Branch B.
        $threadA = new ZaakiyThread;
        $threadA->forceFill([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branchA,
            'user_id' => $this->apiUser->id,
            'title' => 'Collections check',
            'last_message_at' => now(),
        ])->save();

        $threadB = new ZaakiyThread;
        $threadB->forceFill([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branchB,
            'user_id' => $this->apiUser->id,
            'title' => 'Vacancy check',
            'last_message_at' => now(),
        ])->save();

        $msg1 = new ZaakiyMessage;
        $msg1->forceFill([
            'branch_id' => $this->branchA,
            'thread_id' => $threadA->id,
            'role' => 'model',
            'content' => 'Answer 1',
            'model' => 'gemini-flash-latest',
            'prompt_tokens' => 400,
            'candidates_tokens' => 60,
            'latency_ms' => 1200,
            'feedback' => 'helpful',
        ])->save();

        $msg2 = new ZaakiyMessage;
        $msg2->forceFill([
            'branch_id' => $this->branchB,
            'thread_id' => $threadB->id,
            'role' => 'model',
            'content' => 'Answer 2',
            'model' => 'gemini-flash-latest',
            'prompt_tokens' => 300,
            'candidates_tokens' => 40,
            'latency_ms' => 2400,
            'feedback' => 'unhelpful',
        ])->save();

        // User role message must not count toward model turn totals.
        $userMsg = new ZaakiyMessage;
        $userMsg->forceFill([
            'branch_id' => $this->branchA,
            'thread_id' => $threadA->id,
            'role' => 'user',
            'content' => 'Question 1',
        ])->save();

        foreach ([
            [$this->branchA, $threadA->id, $msg1->id, 'lookup_entities', 'ok', 45, null],
            [$this->branchA, $threadA->id, $msg1->id, 'get_collections_health', 'ok', 115, null],
            [$this->branchB, $threadB->id, $msg2->id, 'get_vacancy_analysis', 'error', 90, 'FORBIDDEN'],
        ] as [$branchId, $threadId, $msgId, $tool, $status, $latency, $errCode]) {
            $run = new ZaakiyToolRun;
            $run->forceFill([
                'branch_id' => $branchId,
                'thread_id' => $threadId,
                'message_id' => $msgId,
                'user_id' => $this->apiUser->id,
                'tool_name' => $tool,
                'status' => $status,
                'latency_ms' => $latency,
                'error_code' => $errCode,
            ])->save();
        }

        foreach ([
            [$this->branchA, $threadA->id, 'create_work_order', 'executed'],
            [$this->branchA, $threadA->id, 'create_petty_cash_entry', 'rejected'],
            [$this->branchB, $threadB->id, 'create_customer_draft', 'pending'],
        ] as [$branchId, $threadId, $actionType, $status]) {
            $prop = new ZaakiyActionProposal;
            $prop->forceFill([
                'uuid' => (string) Str::uuid(),
                'branch_id' => $branchId,
                'user_id' => $this->apiUser->id,
                'thread_id' => $threadId,
                'action_type' => $actionType,
                'summary_text' => 'Summary',
                'payload_json' => [],
                'required_permission' => 'maintenance.manage',
                'idempotency_key' => 'key-'.Str::uuid(),
                'status' => $status,
                'expires_at' => now()->addMinutes(15),
            ])->save();
        }

        $superAdmin = $this->createSuperAdmin();
        auth()->forgetGuards();

        $res = $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/zaakiy/metrics?days=30')
            ->assertOk();

        $res->assertJsonPath('data.window_days', 30);
        $res->assertJsonPath('data.turns.total_model_turns', 2);
        $res->assertJsonPath('data.turns.prompt_tokens', 700);
        $res->assertJsonPath('data.turns.candidates_tokens', 100);
        $res->assertJsonPath('data.turns.p95_latency_ms', 2400);
        $res->assertJsonPath('data.feedback.helpful', 1);
        $res->assertJsonPath('data.feedback.unhelpful', 1);
        $res->assertJsonPath('data.tool_runs.total', 3);
        $res->assertJsonPath('data.tool_runs.errors', 1);
        $res->assertJsonPath('data.proposals.total', 3);
        $res->assertJsonPath('data.proposals.executed', 1);
        $res->assertJsonPath('data.proposals.rejected', 1);
        $res->assertJsonPath('data.proposals.pending', 1);
        $res->assertJsonPath('data.proposals.confirmation_rate_pct', 50);

        // Optional branch_id filter scopes cleanly to Branch A only.
        $filtered = $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/zaakiy/metrics?days=30&branch_id='.$this->branchA)
            ->assertOk();
        $filtered->assertJsonPath('data.turns.total_model_turns', 1);
        $filtered->assertJsonPath('data.tool_runs.total', 2);
        $filtered->assertJsonPath('data.proposals.total', 2);
    }

    private function createSuperAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Super Admin',
            'email' => 'super-'.Str::uuid().'@example.com',
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->where('id', $user->id)->update(['status' => 'active']);
        $user->refresh();

        $superRoleId = DB::table('roles')->where('key', 'super_admin')->value('id');
        DB::table('user_global_roles')->insert([
            'user_id' => $user->id,
            'role_id' => $superRoleId,
            'created_at' => now(),
        ]);

        return $user;
    }
}
