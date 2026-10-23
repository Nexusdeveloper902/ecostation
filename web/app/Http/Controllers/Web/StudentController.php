<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Services\PointsService;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Student self-service (dashboard, history, leaderboard).
 *
 * The student row always resolves from the authenticated account
 * (users.student_id), never from a URL parameter. An orphaned account
 * (student deleted, users.student_id nullOnDelete) answers 404 rather
 * than leaking another student's data.
 */
class StudentController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
        private readonly PointsService $points,
    ) {}

    public function dashboard(Request $request): View
    {
        $student = $this->studentFor($request);
        $cardIds = $student->cards()->pluck('id')->all();

        $recent = RecyclingDeposit::query()
            ->join('events', 'events.id', '=', 'recycling_deposits.event_id')
            ->whereIn('events.card_id', $cardIds === [] ? [0] : $cardIds)
            ->orderByDesc('events.occurred_at')
            ->orderByDesc('recycling_deposits.id')
            ->limit(5)
            ->get(['recycling_deposits.*', 'events.occurred_at as occurred_at']);

        return view('student.dashboard', [
            'student' => $student,
            'balance' => $this->points->balance($student),
            'rank' => $this->leaderboard->rankOf($student)['rank'],
            'recent' => $recent,
        ]);
    }

    public function history(Request $request): View
    {
        $student = $this->studentFor($request);
        $cardIds = $student->cards()->pluck('id')->all();

        $deposits = RecyclingDeposit::query()
            ->join('events', 'events.id', '=', 'recycling_deposits.event_id')
            ->whereIn('events.card_id', $cardIds === [] ? [0] : $cardIds)
            ->orderByDesc('events.occurred_at')
            ->orderByDesc('recycling_deposits.id')
            ->paginate(20)
            ->withQueryString();

        return view('student.history', [
            'student' => $student,
            'deposits' => $deposits,
        ]);
    }

    public function leaderboard(Request $request): View
    {
        $student = $this->studentFor($request);

        return view('student.leaderboard', [
            'student' => $student,
            'top' => $this->leaderboard->top(10),
            'classes' => $this->leaderboard->classes(),
            'me' => $this->leaderboard->rankOf($student),
        ]);
    }

    private function studentFor(Request $request): Student
    {
        $student = $request->user()->student;

        abort_if($student === null, 404, __('api.student_not_linked'));

        return $student;
    }
}
