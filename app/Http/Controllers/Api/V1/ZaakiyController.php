<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ZaakiyChatRequest;
use App\Services\Zaakiy\Agent\ZaakiyAgentLoop;
use App\Services\Zaakiy\ZaakiyPresentationBuilder;
use App\Services\ZaakiyContextService;
use App\Services\ZaakiyService;
use App\Support\Branch\BranchContext;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ZaakiyController extends Controller
{
    public function chat(
        ZaakiyChatRequest $request,
        ZaakiyContextService $context,
        ZaakiyService $zaakiy,
        ZaakiyPresentationBuilder $presentation,
        ZaakiyAgentLoop $agentLoop,
        BranchContext $branch,
    ): StreamedResponse {
        $message = $request->string('message')->toString();
        $history = $request->input('history', []);
        $conversationContext = $request->input('conversation_context');
        $user = $request->user();
        $agenticEnabled = (bool) config('services.zaakiy.agentic_enabled', true);
        $resolvedThread = $agenticEnabled
            ? $agentLoop->resolveThread($request->input('thread_id'), $user, $branch)
            : null;

        return response()->stream(function () use (
            $message,
            $history,
            $conversationContext,
            $user,
            $context,
            $zaakiy,
            $presentation,
            $agentLoop,
            $branch,
            $agenticEnabled,
            $resolvedThread,
        ): void {
            $emit = static function (string $event, array $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                if ($agenticEnabled) {
                    $agentLoop->run($message, $resolvedThread, $user, $branch, $emit, $history);

                    return;
                }

                $emit('status', ['label' => 'Analyzing branch data...']);

                $verifiedContext = $context->build($message, $user, $history, $conversationContext);
                $presentationEvents = $presentation->build($verifiedContext);

                $navigations = $verifiedContext['navigations'] ?? (isset($verifiedContext['navigation']) ? [$verifiedContext['navigation']] : []);
                foreach ($navigations as $nav) {
                    if (is_array($nav) && ! empty($nav['url'])) {
                        $emit('navigation', $nav);
                    }
                }
                foreach ($presentationEvents as $structured) {
                    $emit($structured['event'], $structured['data']);
                }
                $zaakiy->stream($message, $history, $verifiedContext, $emit);
                $emit('done', ['context' => $verifiedContext['conversation_context'] ?? null]);
            } catch (Throwable $exception) {
                report($exception);
                $errorMessage = $exception instanceof RuntimeException && $exception->getCode() === 429
                    ? $exception->getMessage()
                    : 'Zaakiy is temporarily unavailable. Please try again shortly.';
                $emit('error', ['message' => $errorMessage]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
