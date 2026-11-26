<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Zaakiy\ConfirmZaakiyActionRequest;
use App\Models\ZaakiyActionProposal;
use App\Models\ZaakiyMessage;
use App\Services\Zaakiy\Agent\ZaakiyActionProposalService;
use App\Support\Branch\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZaakiyActionController extends Controller
{
    public function confirm(
        ConfirmZaakiyActionRequest $request,
        string $proposal,
        ZaakiyActionProposalService $service,
        BranchContext $branch,
    ): JsonResponse {
        $record = ZaakiyActionProposal::query()
            ->forBranch($branch->id())
            ->where('uuid', $proposal)
            ->firstOrFail();

        $result = $service->confirm(
            $record,
            $request->input('overrides', []),
            $request->user(),
            $branch,
        );

        $this->appendThreadMessage(
            $record,
            sprintf('Confirmed: %s.', $result['label']),
            [['label' => 'Open '.$result['label'], 'url' => $result['route']]],
        );

        return response()->json(['data' => $result]);
    }

    public function reject(
        Request $request,
        string $proposal,
        ZaakiyActionProposalService $service,
        BranchContext $branch,
    ): JsonResponse {
        $record = ZaakiyActionProposal::query()
            ->forBranch($branch->id())
            ->where('uuid', $proposal)
            ->firstOrFail();

        $result = $service->reject($record, $request->user(), $branch);

        $this->appendThreadMessage(
            $record,
            sprintf('Cancelled proposed action: %s.', $record->summary_text),
            null,
        );

        return response()->json(['data' => $result]);
    }

    /** @param array<int, array<string, mixed>>|null $navigation */
    private function appendThreadMessage(ZaakiyActionProposal $proposal, string $content, ?array $navigation): void
    {
        $thread = $proposal->thread;
        if ($thread === null) {
            return;
        }

        $message = new ZaakiyMessage;
        $message->forceFill([
            'branch_id' => $proposal->branch_id,
            'thread_id' => $thread->id,
            'role' => 'model',
            'content' => $content,
            'navigation_json' => $navigation,
        ])->save();

        $thread->forceFill(['last_message_at' => now()])->save();
    }
}
