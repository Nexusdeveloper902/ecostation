<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PairCardRequest;
use App\Models\Reader;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/admin/cards/pair — pair a scanned card (device side of the
 * arm-then-pair flow). Auth: Pulse-HMAC or the legacy Bearer reader key,
 * resolved by the reader.auth middleware.
 */
class CardPairingController extends Controller
{
    public function __construct(
        private readonly PairingService $pairings,
    ) {}

    public function store(PairCardRequest $request): JsonResponse
    {
        /** @var Reader $reader */
        $reader = $request->attributes->get('reader');

        $result = $this->pairings->pair(
            $reader,
            (string) $request->validated('credential_uid'),
            (string) ($request->validated('credential_kind') ?? 'physical'),
            $request->safe()->only(['hce_nonce', 'hce_mac', 'hce_key_wrapped', 'hce_key_nonce']),
        );

        if (! $result['ok']) {
            if ($result['reason'] === 'no_session') {
                return response()->json([
                    'status' => 'error',
                    'message' => __('api.pairing_no_active_session'),
                ], 409);
            }

            // The phone's proof did not verify under the key it handed over
            // (or was replayed). The window stays armed.
            if ($result['reason'] === 'hce_proof_invalid') {
                return response()->json([
                    'status' => 'error',
                    'reason' => 'hce_proof_invalid',
                    'message' => __('api.hce_credential_unverified'),
                ], 403);
            }

            // already_paired — never silently reassign a card.
            return response()->json([
                'status' => 'error',
                'message' => __('api.pairing_card_already_paired'),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'paired_student_name' => $result['student']->name,
            'student_id' => $result['student']->id,
            // True when an existing phone credential of this same student
            // received a fresh key (reinstall / lost key).
            'rekeyed' => $result['rekeyed'],
        ]);
    }
}
