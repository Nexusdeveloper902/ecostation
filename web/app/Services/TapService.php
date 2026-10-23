<?php

namespace App\Services;

use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Services\Hce\HceCredentialAuth;
use App\Services\Realtime\TapFeedback;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The core tap loop: identify the card, check HCE possession, and write
 * one event per tap. There is no same-day duplicate rule — every tap at
 * a station is a separate physical deposit awaiting classification.
 */
class TapService
{
    public function __construct(private readonly HceCredentialAuth $hce) {}

    /**
     * @return array{ok: true, event: PresenceEvent}|array{ok: false, reason: 'not_found'|'inactive'|'hce_auth_failed', message: string}
     */
    public function registerTap(
        Reader $reader,
        string $credentialUid,
        ?string $clientTimestamp = null,
        ?string $hceNonce = null,
        ?string $hceMac = null,
    ): array {
        $card = Card::where('credential_uid', $credentialUid)->first();

        if ($card === null) {
            TapFeedback::record($reader, 'rejected', 'not_found');

            return ['ok' => false, 'reason' => 'not_found', 'message' => __('api.card_not_recognized')];
        }

        if (! $card->isActive()) {
            TapFeedback::record($reader, 'rejected', 'inactive');

            return ['ok' => false, 'reason' => 'inactive', 'message' => __('api.card_not_active')];
        }

        // A phone credential is only as good as its proof: no proof, no key,
        // wrong key or a replayed nonce all fail closed before any write.
        if ($card->isHce() && $this->hce->verifyTap($card, $hceNonce, $hceMac) !== null) {
            TapFeedback::record($reader, 'rejected', 'hce_auth_failed');

            return ['ok' => false, 'reason' => 'hce_auth_failed', 'message' => __('api.hce_credential_unverified')];
        }

        $occurredAt = $this->resolveOccurredAt($clientTimestamp);

        return DB::transaction(function () use ($reader, $card, $clientTimestamp, $occurredAt) {
            $event = PresenceEvent::create([
                'card_id' => $card->id,
                'reader_id' => $reader->id,
                // Denormalized for the class-scoped realtime feed.
                'class_id' => $card->student?->class_id,
                'occurred_at' => $occurredAt,
                'metadata' => $clientTimestamp !== null ? ['client_timestamp' => $clientTimestamp] : null,
            ]);

            return ['ok' => true, 'event' => $event];
        });
    }

    private function resolveOccurredAt(?string $clientTimestamp): Carbon
    {
        if ($clientTimestamp !== null) {
            try {
                // Devices may send UTC (`Z`) or a numeric offset. Store the
                // app-timezone wall clock so SQLite comparisons and the
                // API's -05:00 rendering agree.
                return Carbon::parse($clientTimestamp)->setTimezone((string) config('app.timezone'));
            } catch (\Throwable) {
                // A device with a broken clock must never lose the tap.
            }
        }

        return now();
    }
}
