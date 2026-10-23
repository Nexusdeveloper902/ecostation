<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TapEventRequest;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Services\TapService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/events/tap — the core presence loop.
 *
 * Auth: Pulse-HMAC or the legacy Bearer reader key, resolved by the
 * reader.auth middleware. Client-supplied reader IDs are never trusted.
 */
class TapEventController extends Controller
{
    public function __construct(
        private readonly TapService $taps,
    ) {}

    public function store(TapEventRequest $request): JsonResponse
    {
        /** @var Reader $reader */
        $reader = $request->attributes->get('reader');

        $result = $this->taps->registerTap(
            $reader,
            (string) $request->validated('credential_uid'),
            $request->validated('client_timestamp'),
            $request->validated('hce_nonce'),
            $request->validated('hce_mac'),
        );

        if (! $result['ok']) {
            // Unknown/inactive cards stay 404 (nothing to act on); an HCE
            // proof failure is 403 — the card exists but was not proven.
            $status = match ($result['reason']) {
                'not_found', 'inactive' => 404,
                'hce_auth_failed' => 403,
                default => 422,
            };

            return response()->json([
                'status' => 'error',
                'reason' => $result['reason'],
                'message' => $result['message'],
            ], $status);
        }

        $event = $result['event'];

        return response()->json([
            'status' => 'ok',
            'event_id' => $event->id,
            'event_type' => PresenceEvent::TYPE,
            'student_first_name' => $event->card->student->firstName(),
            'duplicate' => false,
            'next_step' => 'awaiting_classification',
        ]);
    }
}
