<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ZaakiyChatRequest;
use App\Services\Zaakiy\ZaakiyPresentationBuilder;
use App\Services\ZaakiyContextService;
use App\Services\ZaakiyService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ZaakiyController extends Controller
{
    public function chat(ZaakiyChatRequest $request, ZaakiyContextService $context, ZaakiyService $zaakiy, ZaakiyPresentationBuilder $presentation): StreamedResponse
    {
        $message = $request->string('message')->toString();
        $history = $request->input('history', []);
        $conversationContext = $request->input('conversation_context');
        $verifiedContext = $context->build($message, $request->user(), $history, $conversationContext);
        $presentationEvents = $presentation->build($verifiedContext);

        return response()->stream(function () use ($message, $history, $verifiedContext, $zaakiy, $presentationEvents): void {
            try {
                if (isset($verifiedContext['navigation'])) {
                    echo "event: navigation\n";
                    echo 'data: '.json_encode($verifiedContext['navigation'], JSON_THROW_ON_ERROR)."\n\n";
                    flush();
                }
                foreach ($presentationEvents as $structured) {
                    echo 'event: '.$structured['event']."\n";
                    echo 'data: '.json_encode($structured['data'], JSON_THROW_ON_ERROR)."\n\n";
                    flush();
                }
                $zaakiy->stream($message, $history, $verifiedContext, function (string $event, array $data): void {
                    echo 'event: '.$event."\n";
                    echo 'data: '.json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                });
                echo 'event: done'."\n";
                echo 'data: '.json_encode(['context' => $verifiedContext['conversation_context'] ?? null], JSON_THROW_ON_ERROR)."\n\n";
            } catch (Throwable $exception) {
                report($exception);
                echo 'event: error'."\n";
                echo 'data: '.json_encode(['message' => 'Zaakiy is temporarily unavailable. Please try again shortly.'])."\n\n";
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
