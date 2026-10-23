<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MaterialClass;
use App\Http\Controllers\Controller;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Services\PointsService;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's own data (Task 12). The student always comes from the
 * authenticated account (`users.student_id`), never from a parameter.
 * An account with no linked student row is a 404 — never another
 * student's numbers.
 */
class MeController extends Controller
{
    public function __construct(
        private readonly PointsService $points,
        private readonly LeaderboardService $leaderboard,
    ) {}

    public function balance(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $standing = $this->leaderboard->rankOf($student);

        return response()->json([
            'student' => $this->studentPayload($student),
            'balance' => $this->points->balance($student),
            'rank' => $standing['rank'],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $student = $this->student($request);

        // One query, newest first; `event` is eager-loaded for occurred_at.
        $deposits = RecyclingDeposit::query()
            ->join('events', 'events.id', '=', 'recycling_deposits.event_id')
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->where('cards.student_id', $student->id)
            ->orderByDesc('events.occurred_at')
            ->orderByDesc('recycling_deposits.id')
            ->select('recycling_deposits.*')
            ->with('event')
            ->simplePaginate(20)
            ->through(fn (RecyclingDeposit $deposit) => [
                'deposit_id' => $deposit->id,
                'occurred_at' => $deposit->event->occurred_at->toIso8601String(),
                // Read the raw column: a legacy/unknown enum value must
                // render as null, never throw a ValueError from the cast.
                'material_class' => $this->materialValue($deposit),
                'points_awarded' => (int) $deposit->points_awarded,
                'is_recyclable' => (bool) $deposit->is_recyclable,
            ]);

        return response()->json($deposits);
    }

    private function materialValue(RecyclingDeposit $deposit): ?string
    {
        $raw = $deposit->getRawOriginal('material_class');

        if (! is_string($raw)) {
            return null;
        }

        return MaterialClass::tryFrom($raw)?->value;
    }

    private function student(Request $request): Student
    {
        $student = $request->user()->student;

        abort_if($student === null, 404, __('api.student_not_linked'));

        return $student;
    }

    /** @return array{id: int, name: string, class_name: string|null} */
    private function studentPayload(Student $student): array
    {
        return [
            'id' => $student->id,
            'name' => $student->name,
            'class_name' => $student->schoolClass?->name,
        ];
    }
}
