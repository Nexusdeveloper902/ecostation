<?php

namespace App\Services\Recycling;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\RecyclingUpdate;
use App\Services\PointsService;
use App\Services\Realtime\RecyclingUpdateLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Classification + points-earn orchestration.
 *
 * Idempotency: one tap = one deposit = one award. A second classify call
 * for the same event returns the existing deposit without awarding again
 * (safe for device retries). The deposit and ledger share ONE
 * transaction; the classifier runs OUTSIDE it so no DB lock is held
 * across a vision-API round trip.
 *
 * Every new award writes its realtime frames (validated,
 * points_awarded, leaderboard_updated) INSIDE the same transaction, so
 * the feed only broadcasts committed truth. `points_awarded` is the one
 * frame the speaker turns into a result cue — it is written even when
 * the award is 0, because the speaker still has to say "not recyclable".
 */
class ClassificationService
{
    public function __construct(
        private readonly MaterialClassifier $classifier,
        private readonly PointsService $points,
        private readonly LeaderboardService $leaderboard,
    ) {}

    /**
     * @param  string  $imagePath  absolute filesystem path the classifier reads
     * @param  string|null  $storedPath  Storage-disk relative path persisted on the deposit
     * @param  callable|null  $insideAwardTx  extra writes that must be atomic with the award
     *                                        (bottle-first capture flip) — receives the fresh deposit
     * @return array{duplicate: bool, deposit: RecyclingDeposit, new_balance: int}
     */
    public function classifyAndAward(
        Reader $reader,
        PresenceEvent $event,
        string $imagePath,
        ?string $storedPath = null,
        ?callable $insideAwardTx = null,
    ): array {
        $student = $event->card->student;

        // Fast path: already classified — no classifier call, no writes.
        $existing = RecyclingDeposit::where('event_id', $event->id)->first();

        if ($existing !== null) {
            return [
                'duplicate' => true,
                'deposit' => $existing,
                'new_balance' => $this->points->balance($student),
            ];
        }

        // The (potentially slow, network-bound) judgment runs OUTSIDE any
        // transaction.
        $result = $this->classifier->classify($imagePath);
        $material = MaterialClass::from($result['material_class']);

        // The denormalized event class is the class-scoping key the
        // realtime feed and teacher views filter on.
        $classId = $event->class_id !== null ? (int) $event->class_id : null;

        try {
            return DB::transaction(function () use ($event, $student, $material, $result, $storedPath, $classId, $insideAwardTx) {
                // Re-check under the transaction: the unique(event_id)
                // index is the real guard; this makes the common loser
                // graceful (duplicate response, not a constraint 500).
                $existing = RecyclingDeposit::where('event_id', $event->id)->lockForUpdate()->first();

                if ($existing !== null) {
                    return [
                        'duplicate' => true,
                        'deposit' => $existing,
                        'new_balance' => $this->points->balance($student),
                    ];
                }

                $deposit = RecyclingDeposit::create([
                    'event_id' => $event->id,
                    'image_path' => $storedPath,
                    'material_class' => $material->value,
                    'confidence' => (float) $result['confidence'],
                    'points_awarded' => $material->points(),
                    'is_bottle' => (bool) ($result['is_bottle'] ?? false),
                    'is_recyclable' => (bool) ($result['is_recyclable'] ?? false),
                ]);

                $this->points->awardRecyclingPoints($student, $event, $material);

                $newBalance = $this->points->balance($student);

                RecyclingUpdateLog::record(RecyclingUpdate::TYPE_VALIDATED, [
                    'event_id' => $event->id,
                    'deposit_id' => $deposit->id,
                    'material_class' => $material->value,
                    'confidence' => (float) $result['confidence'],
                    'is_bottle' => (bool) ($result['is_bottle'] ?? false),
                    'is_recyclable' => (bool) ($result['is_recyclable'] ?? false),
                    'student_id' => $student->id,
                    'class_id' => $classId,
                    'student_name' => $student->name,
                    'reader_label' => $event->reader?->label,
                    'occurred_at' => $event->occurred_at->format('Y-m-d H:i'),
                    'image_path_present' => $deposit->image_path !== null,
                ]);

                // The speaker's ONE result cue — emitted even at 0 points.
                RecyclingUpdateLog::record(RecyclingUpdate::TYPE_POINTS_AWARDED, [
                    'event_id' => $event->id,
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'material_class' => $material->value,
                    'points' => $material->points(),
                    'new_balance' => $newBalance,
                    'class_id' => $classId,
                ]);

                RecyclingUpdateLog::record(RecyclingUpdate::TYPE_LEADERBOARD_UPDATED, [
                    'top' => $this->leaderboard->snapshot(),
                ]);

                if ($insideAwardTx !== null) {
                    $insideAwardTx($deposit);
                }

                return [
                    'duplicate' => false,
                    'deposit' => $deposit->refresh(),
                    'new_balance' => $newBalance,
                ];
            });
        } catch (QueryException $e) {
            // A concurrent winner inserted the deposit between our
            // pre-check and ours — answer as the duplicate.
            if ($this->isUniqueViolation($e)) {
                $winner = RecyclingDeposit::where('event_id', $event->id)->firstOrFail();

                return [
                    'duplicate' => true,
                    'deposit' => $winner,
                    'new_balance' => $this->points->balance($student),
                ];
            }

            throw $e;
        }
    }

    /**
     * Integrity-constraint detection across the supported drivers:
     * SQLite ("UNIQUE constraint failed"), MySQL ("Duplicate entry",
     * SQLSTATE 23000), PostgreSQL (SQLSTATE 23505).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();
        $sqlState = $e->errorInfo[0] ?? null;

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || $sqlState === '23000'
            || $sqlState === '23505';
    }
}
