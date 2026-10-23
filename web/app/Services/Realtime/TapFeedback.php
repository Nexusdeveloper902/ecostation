<?php

namespace App\Services\Realtime;

use App\Models\Reader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The `feedback` channel: taps that were answered but wrote no events row
 * (unknown / inactive card, failed HCE proof). Taps that write a row are
 * not logged here — the `tap` frame already carries their cue.
 */
final class TapFeedback
{
    /**
     * Best-effort: a speaker cue must never fail the device's tap. A
     * failed write is logged and swallowed.
     */
    public static function record(Reader $reader, string $cue, string $reason, ?int $eventId = null): void
    {
        try {
            DB::table('tap_feedback')->insert([
                'reader_id' => $reader->id,
                'event_id' => $eventId,
                'cue' => $cue,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('tap_feedback cue not recorded: '.$e->getMessage());
        }
    }

    /**
     * Rows with id > $afterId, oldest first (the broadcast delta).
     *
     * @return array<int, array{id: int, cue: string, reason: string, event_id: int|null, reader_id: int|null}>
     */
    public function after(int $afterId, int $limit = 100): array
    {
        return DB::table('tap_feedback')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'cue', 'reason', 'event_id', 'reader_id'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'cue' => (string) $row->cue,
                'reason' => (string) $row->reason,
                'event_id' => $row->event_id !== null ? (int) $row->event_id : null,
                'reader_id' => $row->reader_id !== null ? (int) $row->reader_id : null,
            ])
            ->all();
    }

    public function latestId(): int
    {
        try {
            return (int) (DB::table('tap_feedback')->max('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
