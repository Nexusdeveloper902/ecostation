<?php

namespace App\Http\Controllers\Web;

use App\Enums\MaterialClass;
use App\Http\Controllers\Controller;
use App\Models\PointsLedger;
use App\Models\Student;
use App\Services\PointsService;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    /** Friendly URL aliases: the English material values never leak into links. */
    private const MATERIAL_FILTERS = [
        'azul' => 'plastic',
        'coral' => 'metal',
        'turquesa' => 'glass',
        'ambar' => 'paper',
        'rosa' => 'other',
    ];

    public function __construct(
        private readonly LeaderboardService $leaderboard,
        private readonly PointsService $points,
    ) {}

    public function dashboard(Request $request): View
    {
        $student = $this->studentFor($request);

        $recent = $this->deposits($student)
            ->orderByDesc('e.occurred_at')
            ->orderByDesc('d.id')
            ->limit(5)
            ->get();

        $yearStart = now()->startOfYear();
        $monthStart = now()->startOfMonth();
        $previousMonthStart = now()->startOfMonth()->subMonthNoOverflow();

        $year = $this->deposits($student)
            ->where('e.occurred_at', '>=', $yearStart)
            ->where('e.occurred_at', '<=', now()->endOfYear());

        $yearItems = (clone $year)->count();
        $yearPoints = (int) (clone $year)->sum('d.points_awarded');
        $yearSince = (clone $year)->min('e.occurred_at');

        $monthItems = $this->deposits($student)
            ->whereBetween('e.occurred_at', [$monthStart, now()->endOfMonth()])
            ->count();

        $previousMonthItems = $this->deposits($student)
            ->whereBetween('e.occurred_at', [$previousMonthStart, $previousMonthStart->copy()->endOfMonth()])
            ->count();

        $byMaterial = $this->materialMix($year);
        $standing = $this->leaderboard->rankInClass($student);

        return view('student.dashboard', [
            'student' => $student,
            'balance' => $this->points->balance($student),
            'rank' => $standing['rank'],
            'recent' => $recent,
            'rankStudents' => $standing['students'],
            'studentsTotal' => Student::count(),
            'yearItems' => $yearItems,
            'yearPoints' => $yearPoints,
            'yearSince' => $yearSince !== null ? Carbon::parse($yearSince) : null,
            'monthItems' => $monthItems,
            'previousMonthItems' => $previousMonthItems,
            'monthDelta' => $monthItems - $previousMonthItems,
            'weekPoints' => (int) PointsLedger::query()
                ->where('student_id', $student->id)
                ->where('delta', '>', 0)
                ->where('created_at', '>=', now()->startOfWeek())
                ->sum('delta'),
            'byMaterial' => $byMaterial,
        ]);
    }

    public function history(Request $request): View
    {
        $student = $this->studentFor($request);

        $selectedMonth = $this->monthOrCurrent((string) $request->query('month', ''));
        $materialFilter = (string) $request->query('material', '');

        if (! array_key_exists($materialFilter, self::MATERIAL_FILTERS)) {
            $materialFilter = '';
        }

        $selectedMaterial = self::MATERIAL_FILTERS[$materialFilter] ?? '';

        $monthStart = Carbon::createFromFormat('!Y-m', $selectedMonth)->startOfMonth();

        $filtered = $this->deposits($student)
            ->whereBetween('e.occurred_at', [$monthStart, $monthStart->copy()->endOfMonth()])
            ->when($selectedMaterial !== '', fn (Builder $query) => $query->where('d.material_class', $selectedMaterial));

        $monthItems = (clone $filtered)->count();
        $monthPoints = (int) (clone $filtered)->sum('d.points_awarded');

        $deposits = $filtered
            ->orderByDesc('e.occurred_at')
            ->orderByDesc('d.id')
            ->paginate(20)
            ->withQueryString();

        return view('student.history', [
            'student' => $student,
            'deposits' => $deposits,
            'months' => $this->monthOptions($selectedMonth),
            'selectedMonth' => $selectedMonth,
            'selectedMaterial' => $selectedMaterial,
            'materialFilter' => $materialFilter,
            'monthLabel' => $monthStart->copy()->locale('es')->translatedFormat('F Y'),
            'monthItems' => $monthItems,
            'monthPoints' => $monthPoints,
        ]);
    }

    public function leaderboard(Request $request): View
    {
        $student = $this->studentFor($request);
        $scope = $request->query('scope') === 'school' ? 'school' : 'class';
        $classId = $scope === 'class' ? $student->class_id : null;
        $top = $this->leaderboard->top(10, $classId);
        $classes = $this->leaderboard->classes();
        $me = $classId !== null
            ? $this->leaderboard->rankInClass($student)
            : $this->leaderboard->rankOf($student) + ['students' => Student::count()];

        $studentIds = array_column($top, 'student_id');
        $studentIds[] = (int) $student->id;

        $studentDeposits = DB::table('recycling_deposits as d')
            ->join('events as e', 'e.id', '=', 'd.event_id')
            ->join('cards as c', 'c.id', '=', 'e.card_id')
            ->whereIn('c.student_id', array_values(array_unique($studentIds)))
            ->groupBy('c.student_id')
            ->selectRaw('c.student_id, count(*) as items')
            ->pluck('items', 'student_id');

        $classDeposits = DB::table('recycling_deposits as d')
            ->join('events as e', 'e.id', '=', 'd.event_id')
            ->whereNotNull('e.class_id')
            ->groupBy('e.class_id')
            ->selectRaw('e.class_id, count(*) as items')
            ->pluck('items', 'class_id');

        return view('student.leaderboard', [
            'student' => $student,
            'top' => $top,
            'classes' => $classes,
            'me' => $me,
            'scope' => $scope,
            'studentDeposits' => $studentDeposits,
            'classDeposits' => $classDeposits,
        ]);
    }

    private function studentFor(Request $request): Student
    {
        $student = $request->user()->student;

        abort_if($student === null, 404, __('api.student_not_linked'));

        return $student;
    }

    /** Classified deposits for one student, with the tap time and station label. */
    private function deposits(Student $student): Builder
    {
        $cardIds = $student->cards()->pluck('id')->all();

        return DB::table('recycling_deposits as d')
            ->join('events as e', 'e.id', '=', 'd.event_id')
            ->leftJoin('readers as r', 'r.id', '=', 'e.reader_id')
            ->whereIn('e.card_id', $cardIds === [] ? [0] : $cardIds)
            ->select([
                'd.*',
                'e.id as event_id',
                'e.occurred_at',
                'r.label as reader_label',
            ]);
    }

    /**
     * @param  Builder  $query  a deposits() query already scoped to a period
     * @return array<string, array{items: int, points: int, rate: int}>
     */
    private function materialMix(Builder $query): array
    {
        $rows = $query
            ->select(DB::raw('d.material_class as material'))
            ->selectRaw('count(*) as items, coalesce(sum(d.points_awarded), 0) as points')
            ->groupBy('d.material_class')
            ->get()
            ->keyBy('material');

        $mix = [];

        foreach (MaterialClass::cases() as $material) {
            $row = $rows->get($material->value);

            $mix[$material->value] = [
                'items' => $row !== null ? (int) $row->items : 0,
                'points' => $row !== null ? (int) $row->points : 0,
                'rate' => $material->points(),
            ];
        }

        return $mix;
    }

    private function monthOrCurrent(string $raw): string
    {
        try {
            $month = Carbon::createFromFormat('!Y-m', $raw);

            if ($month->format('Y-m') !== $raw || $month->gt(now())) {
                return now()->format('Y-m');
            }

            return $raw;
        } catch (\Throwable) {
            return now()->format('Y-m');
        }
    }

    /** @return array<string, string> */
    private function monthOptions(string $selected): array
    {
        $current = now()->startOfMonth();
        $selectedStart = Carbon::createFromFormat('!Y-m', $selected)->startOfMonth();
        $oldest = $current->copy()->subMonthsNoOverflow(5);
        $start = $selectedStart->lt($oldest) ? $selectedStart->copy() : $oldest;
        $end = $selectedStart->gt($current) ? $selectedStart->copy() : $current->copy();

        $months = [];
        $cursor = $end;

        while ($cursor->gte($start)) {
            $months[$cursor->format('Y-m')] = ucfirst($cursor->copy()->locale('es')->translatedFormat('F Y'));
            $cursor = $cursor->copy()->subMonthNoOverflow();
        }

        return $months;
    }
}
