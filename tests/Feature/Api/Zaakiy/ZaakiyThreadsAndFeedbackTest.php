<?php

namespace Tests\Feature\Api\Zaakiy;

use App\Models\User;
use App\Models\ZaakiyMessage;
use App\Models\ZaakiyThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class ZaakiyThreadsAndFeedbackTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_threads_and_feedback_are_strictly_scoped_to_authenticated_user_and_active_branch(): void
    {
        $otherUser = User::query()->create([
            'name' => 'Other User',
            'email' => 'other-'.Str::uuid().'@example.com',
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->where('id', $otherUser->id)->update(['status' => 'active']);

        $ownThread = (new ZaakiyThread(['title' => 'Dubai collections']))->forceFill([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branchA,
            'user_id' => $this->apiUser->id,
            'last_message_at' => now(),
        ]);
        $ownThread->save();

        $otherUsersThread = (new ZaakiyThread(['title' => 'Other user thread']))->forceFill([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branchA,
            'user_id' => $otherUser->id,
            'last_message_at' => now(),
        ]);
        $otherUsersThread->save();

        $branchBThread = (new ZaakiyThread(['title' => 'Sharjah collections']))->forceFill([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branchB,
            'user_id' => $this->apiUser->id,
            'last_message_at' => now(),
        ]);
        $branchBThread->save();

        $modelMessage = (new ZaakiyMessage(['content' => 'Collections are up 12%.']))->forceFill([
            'branch_id' => $this->branchA,
            'thread_id' => $ownThread->id,
            'role' => 'model',
        ]);
        $modelMessage->save();

        $otherMessage = (new ZaakiyMessage(['content' => 'Private response']))->forceFill([
            'branch_id' => $this->branchA,
            'thread_id' => $otherUsersThread->id,
            'role' => 'model',
        ]);
        $otherMessage->save();

        $this->branchRequest()->getJson('/api/v1/ai/zaakiy/threads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $ownThread->uuid);

        $this->branchRequest()->getJson('/api/v1/ai/zaakiy/threads/'.$ownThread->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $ownThread->uuid)
            ->assertJsonCount(1, 'data.messages');

        $this->branchRequest()->getJson('/api/v1/ai/zaakiy/threads/'.$otherUsersThread->uuid)->assertNotFound();
        $this->branchRequest()->getJson('/api/v1/ai/zaakiy/threads/'.$branchBThread->uuid)->assertNotFound();

        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/messages/'.$otherMessage->id.'/feedback', [
            'feedback' => 'helpful',
        ])->assertNotFound();

        $this->branchRequest()->postJson('/api/v1/ai/zaakiy/messages/'.$modelMessage->id.'/feedback', [
            'feedback' => 'helpful',
            'note' => 'Clear summary',
        ])->assertOk()->assertJsonPath('data.feedback', 'helpful');

        $this->assertDatabaseHas('zaakiy_messages', [
            'id' => $modelMessage->id,
            'feedback' => 'helpful',
            'feedback_note' => 'Clear summary',
        ]);

        $this->branchRequest()->deleteJson('/api/v1/ai/zaakiy/threads/'.$otherUsersThread->uuid)->assertNotFound();
        $this->branchRequest()->deleteJson('/api/v1/ai/zaakiy/threads/'.$ownThread->uuid)->assertNoContent();
        $this->assertDatabaseMissing('zaakiy_threads', ['id' => $ownThread->id]);
    }
}
