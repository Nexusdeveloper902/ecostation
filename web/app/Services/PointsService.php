<?php

namespace App\Services;

use App\Enums\MaterialClass;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\Student;

/**
 * Points accounting. Every movement goes through the append-only
 * points_ledger; balances are always SUM(delta).
 */
class PointsService
{
    public function balance(Student $student): int
    {
        return (int) PointsLedger::where('student_id', $student->id)->sum('delta');
    }

    /**
     * Current balance plus the lifetime earned/spent split. `spent` is a
     * positive number.
     *
     * @return array{balance: int, earned: int, spent: int}
     */
    public function statement(Student $student): array
    {
        $earned = (int) PointsLedger::where('student_id', $student->id)->where('delta', '>', 0)->sum('delta');
        $spent = (int) PointsLedger::where('student_id', $student->id)->where('delta', '<', 0)->sum('delta');

        return ['balance' => $earned + $spent, 'earned' => $earned, 'spent' => -$spent];
    }

    /**
     * Award recycling points for a classified deposit. Called inside
     * ClassificationService's award transaction — frames ride it.
     *
     * The amount comes from the material class ONLY (admin rate); the
     * deposit's is_bottle/is_recyclable flags never change it. A zero
     * rate writes no ledger row.
     */
    public function awardRecyclingPoints(Student $student, PresenceEvent $event, MaterialClass $material): int
    {
        $points = $material->points();

        if ($points > 0) {
            PointsLedger::create([
                'student_id' => $student->id,
                'delta' => $points,
                'reason' => 'recycling_deposit',
                'event_id' => $event->id,
            ]);
        }

        return $points;
    }
}
