<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ZaakiyActionProposal;
use App\Models\ZaakiyMessage;
use App\Models\ZaakiyToolRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZaakiyMetricsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->isSuperAdmin()) {
            throw new ApiException('FORBIDDEN', 'Only super administrators can access Zaakiy telemetry metrics.', 403);
        }

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $since = now()->subDays($days);

        $messagesQuery = ZaakiyMessage::query()
            ->where('role', 'model')
            ->where('created_at', '>=', $since)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));

        $messages = $messagesQuery->get(['branch_id', 'prompt_tokens', 'candidates_tokens', 'latency_ms', 'feedback']);
        $latencies = $messages->pluck('latency_ms')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v)->sort()->values()->all();

        $toolRuns = ZaakiyToolRun::query()
            ->where('created_at', '>=', $since)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['branch_id', 'tool_name', 'status', 'latency_ms']);

        $proposals = ZaakiyActionProposal::query()
            ->where('created_at', '>=', $since)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['branch_id', 'status']);

        $totalToolRuns = $toolRuns->count();
        $toolErrors = $toolRuns->where('status', 'error')->count();
        $byTool = $toolRuns
            ->groupBy('tool_name')
            ->map(function ($group, string $toolName): array {
                $calls = $group->count();

                return [
                    'tool_name' => $toolName,
                    'calls' => $calls,
                    'errors' => $group->where('status', 'error')->count(),
                    'avg_latency_ms' => $calls > 0 ? (int) round((float) $group->avg('latency_ms')) : 0,
                ];
            })
            ->sortByDesc('calls')
            ->values()
            ->all();

        $executed = $proposals->where('status', 'executed')->count();
        $rejected = $proposals->where('status', 'rejected')->count();
        $decided = $executed + $rejected;

        $branches = Branch::query()
            ->when($branchId !== null, fn ($q) => $q->where('id', $branchId))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $byBranch = $branches->map(function (Branch $branch) use ($messages, $toolRuns, $proposals): array {
            $bProposals = $proposals->where('branch_id', $branch->id);

            return [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'branch_name' => $branch->name,
                'turns' => $messages->where('branch_id', $branch->id)->count(),
                'tool_calls' => $toolRuns->where('branch_id', $branch->id)->count(),
                'proposals_total' => $bProposals->count(),
                'proposals_executed' => $bProposals->where('status', 'executed')->count(),
                'proposals_rejected' => $bProposals->where('status', 'rejected')->count(),
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'window_days' => $days,
                'since' => $since->toIso8601String(),
                'branch_id' => $branchId,
                'turns' => [
                    'total_model_turns' => $messages->count(),
                    'prompt_tokens' => (int) $messages->sum('prompt_tokens'),
                    'candidates_tokens' => (int) $messages->sum('candidates_tokens'),
                    'avg_latency_ms' => $latencies !== [] ? (int) round(array_sum($latencies) / count($latencies)) : 0,
                    'p95_latency_ms' => $this->percentile($latencies, 95),
                ],
                'feedback' => [
                    'helpful' => $messages->where('feedback', 'helpful')->count(),
                    'unhelpful' => $messages->where('feedback', 'unhelpful')->count(),
                ],
                'tool_runs' => [
                    'total' => $totalToolRuns,
                    'errors' => $toolErrors,
                    'error_rate_pct' => $totalToolRuns > 0 ? round(($toolErrors / $totalToolRuns) * 100, 2) : 0.0,
                    'by_tool' => $byTool,
                ],
                'proposals' => [
                    'total' => $proposals->count(),
                    'pending' => $proposals->where('status', 'pending')->count(),
                    'executed' => $executed,
                    'rejected' => $rejected,
                    'expired' => $proposals->where('status', 'expired')->count(),
                    'failed' => $proposals->where('status', 'failed')->count(),
                    'confirmation_rate_pct' => $decided > 0 ? round(($executed / $decided) * 100, 2) : 0.0,
                ],
                'by_branch' => $byBranch,
            ],
        ]);
    }

    /** @param array<int, int> $sorted */
    private function percentile(array $sorted, int $p): int
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0;
        }
        $index = (int) ceil(($p / 100) * $count) - 1;
        $clamped = max(0, min($count - 1, $index));

        return $sorted[$clamped];
    }
}
