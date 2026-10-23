<?php

namespace App\Services\Realtime;

use App\Models\RecyclingUpdate;

/**
 * The write half of the recycling channel. Every call MUST run inside the
 * same DB transaction as the state change it describes, so the feed only
 * ever broadcasts committed truth.
 */
final class RecyclingUpdateLog
{
    /**
     * @param  array<string, mixed>  $payload  precomputed frame payload (the writer owns the context)
     */
    public static function record(string $type, array $payload): RecyclingUpdate
    {
        return RecyclingUpdate::create([
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}
