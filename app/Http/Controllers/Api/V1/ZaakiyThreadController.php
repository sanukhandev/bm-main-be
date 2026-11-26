<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Zaakiy\ZaakiyFeedbackRequest;
use App\Models\ZaakiyMessage;
use App\Models\ZaakiyThread;
use App\Support\Branch\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ZaakiyThreadController extends Controller
{
    public function index(Request $request, BranchContext $context): JsonResponse
    {
        $threads = ZaakiyThread::query()
            ->forBranch($context->id())
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'uuid', 'title', 'last_message_at', 'created_at']);

        return response()->json([
            'data' => $threads->map(fn (ZaakiyThread $thread): array => [
                'uuid' => $thread->uuid,
                'title' => $thread->title,
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
                'created_at' => $thread->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function show(Request $request, ZaakiyThread $thread, BranchContext $context): JsonResponse
    {
        abort_unless((int) $thread->branch_id === $context->id() && (int) $thread->user_id === (int) $request->user()->id, 404);

        $messages = $thread->messages()->get()->map(fn (ZaakiyMessage $message): array => [
            'id' => $message->id,
            'role' => $message->role,
            'text' => $message->content,
            'blocks' => $message->blocks_json ?? [],
            'actions' => $message->navigation_json ?? [],
            'feedback' => $message->feedback,
            'created_at' => $message->created_at?->toIso8601String(),
        ])->values()->all();

        return response()->json([
            'data' => [
                'uuid' => $thread->uuid,
                'title' => $thread->title,
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
                'messages' => $messages,
            ],
        ]);
    }

    public function destroy(Request $request, ZaakiyThread $thread, BranchContext $context): Response
    {
        abort_unless((int) $thread->branch_id === $context->id() && (int) $thread->user_id === (int) $request->user()->id, 404);

        $thread->delete();

        return response()->noContent();
    }

    public function feedback(ZaakiyFeedbackRequest $request, int $message, BranchContext $context): JsonResponse
    {
        $record = ZaakiyMessage::query()
            ->forBranch($context->id())
            ->where('role', 'model')
            ->whereHas('thread', fn ($query) => $query
                ->where('branch_id', $context->id())
                ->where('user_id', $request->user()->id))
            ->findOrFail($message);

        $record->fill([
            'feedback' => $request->validated('feedback'),
            'feedback_note' => $request->validated('note'),
        ])->save();

        return response()->json([
            'data' => [
                'id' => $record->id,
                'feedback' => $record->feedback,
                'feedback_note' => $record->feedback_note,
            ],
        ]);
    }
}
