<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/recycling/leaderboard — the student board, every class
 * board, and (for a student account) that account's own standing.
 */
class LeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', config('ecostation.leaderboard.top'))));

        $payload = [
            'top' => $this->leaderboard->top($limit),
            'classes' => $this->leaderboard->classes(),
        ];

        $user = $request->user();

        if ($user !== null && $user->isStudent() && $user->student !== null) {
            $payload['me'] = $this->leaderboard->rankOf($user->student);
        }

        return response()->json($payload);
    }
}
