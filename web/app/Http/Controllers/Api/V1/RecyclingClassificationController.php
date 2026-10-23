<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassifyRequest;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Services\Recycling\ClassificationException;
use App\Services\Recycling\ClassificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * POST /api/v1/recycling/classify
 * Auth: same reader identity as the tap endpoint (the station that owns
 * the event). multipart: event_id + image.
 *
 * Points are awarded here (after classification) — never at tap time.
 * The image is persisted (storage disk 'local', recycling-captures/) and
 * its path rides the deposit; a driver failure deletes it, so no orphan
 * accumulates. A tap older than the classify window is rejected.
 */
class RecyclingClassificationController extends Controller
{
    public function __construct(
        private readonly ClassificationService $classification,
    ) {}

    public function store(ClassifyRequest $request): JsonResponse
    {
        /** @var Reader $reader */
        $reader = $request->attributes->get('reader');

        /** @var PresenceEvent $event */
        $event = PresenceEvent::findOrFail($request->validated('event_id'));

        if ((int) $event->reader_id !== (int) $reader->id) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.event_not_owned_by_reader'),
            ], 403);
        }

        // The card-first timeout: a tap event must be classified within
        // the configurable window.
        $window = max(0, (int) config('ecostation.capture.classify_window_seconds'));
        if ($window > 0 && $event->occurred_at->lt(now()->subSeconds($window))) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.event_expired'),
            ], 422);
        }

        // Persist the image BEFORE classification: the deposit (and only
        // the deposit) references the stored path.
        $storedPath = $request->file('image')->store('recycling-captures', 'local');
        $absolutePath = (string) Storage::disk('local')->path($storedPath);

        try {
            $result = $this->classification->classifyAndAward($reader, $event, $absolutePath, $storedPath);
        } catch (ClassificationException $e) {
            // Driver-level failure — devices may retry; no points moved.
            Storage::disk('local')->delete($storedPath);

            return response()->json([
                'status' => 'error',
                'message' => __('api.classifier_unavailable'),
                'detail' => $e->getMessage(),
            ], 503);
        } catch (\Throwable $e) {
            // Any other classification-path failure must not leave the
            // newly stored image behind; the error still surfaces.
            Storage::disk('local')->delete($storedPath);

            throw $e;
        }

        if ($result['duplicate']) {
            // The already-stored image from the FIRST call remains the
            // deposit's record; the retry's copy is redundant.
            Storage::disk('local')->delete($storedPath);
        }

        $deposit = $result['deposit'];

        return response()->json([
            'status' => 'ok',
            'already_classified' => $result['duplicate'],
            'material_class' => $deposit->material_class->value,
            'confidence' => (float) $deposit->confidence,
            'is_bottle' => (bool) $deposit->is_bottle,
            'is_recyclable' => (bool) $deposit->is_recyclable,
            'points_awarded' => (int) $deposit->points_awarded,
            'new_balance' => $result['new_balance'],
        ]);
    }
}
