<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PointsLedger;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\PointsService;
use App\Services\Realtime\RealtimeFeed;
use App\Services\RecyclingReportService;
use App\Services\StudentScope;
use App\Support\ReportRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The teacher reporting desk: a range summary and the drill-downs into
 * one class, one student and the live tap feed. Every surface resolves
 * its scope through StudentScope::forUser, so a teacher can never read
 * another class's data even by editing the URL; an out-of-scope row
 * answers 404, exactly like a row that does not exist.
 */
class TeacherDashboardController extends Controller
{
    private const RANGES = [
        '30d' => 'Últimos 30 días',
        '4w' => 'Últimas 4 semanas',
        '8w' => 'Últimas 8 semanas',
        '12w' => 'Últimas 12 semanas',
        'month' => 'Este mes',
        'year' => 'Este año',
    ];

    public function __construct(
        private readonly RecyclingReportService $reports,
        private readonly RealtimeFeed $realtimeFeed,
        private readonly PointsService $points,
    ) {}

    public function dashboard(Request $request): View
    {
        $scope = StudentScope::forUser($request->user());
        $classes = $this->classesInScope($scope);
        $selectedClass = $this->selectedClass($request, $classes);
        $classIds = $selectedClass !== null ? [(int) $selectedClass->id] : $scope->classIdFilter();
        [$from, $to] = $this->resolveRange($request);

        return view('teacher.dashboard', [
            'from' => $from,
            'to' => $to,
            'range' => $this->rangeKey($request),
            'ranges' => self::RANGES,
            'classes' => $classes,
            'selectedClass' => $selectedClass,
            'trend' => $this->reports->trend($from, $to, $classIds),
            'byMaterial' => $this->reports->byMaterial($from, $to, $classIds),
            'byClass' => $this->reports->byClass($from, $to, $classIds),
            'summary' => $this->reports->summary($from, $to, $classIds),
            'materialTrend' => $this->reports->materialWeeklyTrend($from, $to, $classIds),
            'topStudents' => $this->reports->topStudents($from, $to, $classIds),
        ]);
    }

    public function showClass(Request $request, SchoolClass $class): View
    {
        $scope = StudentScope::forUser($request->user());
        $classIds = $scope->classIdFilter();

        abort_if($classIds !== null && ! in_array((int) $class->id, $classIds, true), 404);

        [$from, $to] = $this->resolveRange($request);
        $search = trim((string) $request->query('q', ''));

        $students = $class->students()
            ->orderBy('name')
            ->get()
            ->when($search !== '', fn (Collection $rows) => $rows->filter(
                fn (Student $student) => mb_stripos((string) $student->name, $search) !== false
            ))
            ->values();

        $ids = $students->pluck('id')->all();

        $balances = PointsLedger::query()
            ->whereIn('student_id', $ids === [] ? [0] : $ids)
            ->groupBy('student_id')
            ->selectRaw('student_id, coalesce(sum(delta), 0) as balance')
            ->pluck('balance', 'student_id');

        $stats = DB::table('recycling_deposits as d')
            ->join('events as e', 'e.id', '=', 'd.event_id')
            ->join('cards as c', 'c.id', '=', 'e.card_id')
            ->whereIn('c.student_id', $ids === [] ? [0] : $ids)
            ->where('e.class_id', $class->id)
            ->whereBetween('e.occurred_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->groupBy('c.student_id')
            ->selectRaw('c.student_id, count(*) as items, coalesce(sum(d.points_awarded), 0) as points, max(e.occurred_at) as last_event')
            ->get()
            ->keyBy('student_id');

        $studentRows = $students->map(function (Student $student) use ($balances, $stats) {
            $row = $stats->get($student->id);

            return [
                'id' => (int) $student->id,
                'name' => (string) $student->name,
                'balance' => (int) ($balances[$student->id] ?? 0),
                'items' => $row !== null ? (int) $row->items : 0,
                'points' => $row !== null ? (int) $row->points : 0,
                'last_event' => $row?->last_event !== null ? Carbon::parse($row->last_event) : null,
            ];
        })->all();

        return view('teacher.class', [
            'from' => $from,
            'to' => $to,
            'range' => $this->rangeKey($request),
            'ranges' => self::RANGES,
            'class' => $class,
            'students' => $studentRows,
            'classes' => $this->classesInScope($scope),
            'byMaterial' => $this->reports->byMaterial($from, $to, [(int) $class->id]),
            'summary' => $this->reports->summary($from, $to, [(int) $class->id]),
            'search' => $search,
        ]);
    }

    public function showStudent(Request $request, Student $student): View
    {
        $scope = StudentScope::forUser($request->user());

        abort_unless($scope->allowsStudent($student), 404);

        $history = $this->reports->studentHistory($student);

        $recyclable = count(array_filter($history['deposits'], fn (array $deposit) => $deposit['is_recyclable']));
        $withPhoto = count(array_filter($history['deposits'], fn (array $deposit) => $deposit['has_photo']));

        return view('teacher.student', [
            'student' => $student,
            'history' => $history,
            'balance' => $this->points->balance($student),
            'recyclablePct' => $history['totals']['items'] > 0
                ? (int) round($recyclable / $history['totals']['items'] * 100)
                : 0,
            'withPhoto' => $withPhoto,
            'classes' => $this->classesInScope($scope),
        ]);
    }

    public function live(Request $request): View
    {
        $scope = StudentScope::forUser($request->user());
        $classes = $this->classesInScope($scope);
        $selectedClass = $this->selectedClass($request, $classes);
        $classIds = $selectedClass !== null ? [(int) $selectedClass->id] : $scope->classIdFilter();

        $recent = $this->realtimeFeed->recent(20, $classIds);

        $deposits = RecyclingDeposit::query()
            ->whereIn('event_id', array_column($recent, 'id'))
            ->get()
            ->keyBy('event_id');

        $recent = array_map(function (array $row) use ($deposits) {
            $deposit = $deposits->get($row['id']);

            $row['material'] = $deposit?->material_class?->value;
            $row['points'] = $deposit?->points_awarded;

            return $row;
        }, $recent);

        $readers = Reader::query()->orderBy('label')->get();

        $todayCounts = DB::table('events')
            ->where('occurred_at', '>=', Carbon::today())
            ->groupBy('reader_id')
            ->selectRaw('reader_id, count(*) as items')
            ->pluck('items', 'reader_id');

        $lastEvents = DB::table('events')
            ->whereNotNull('reader_id')
            ->groupBy('reader_id')
            ->selectRaw('reader_id, max(occurred_at) as last_event')
            ->pluck('last_event', 'reader_id');

        $stations = $readers->map(function (Reader $reader) use ($todayCounts, $lastEvents) {
            $last = $lastEvents[$reader->id] ?? null;
            $lastAt = $last !== null ? Carbon::parse($last) : null;

            return [
                'id' => (int) $reader->id,
                'label' => (string) $reader->label,
                'today' => (int) ($todayCounts[$reader->id] ?? 0),
                'last_event' => $lastAt,
                'online' => $lastAt !== null && $lastAt->gte(now()->subMinutes(15)),
            ];
        })->all();

        $silent = collect($stations)
            ->filter(fn (array $station) => $station['last_event'] !== null && ! $station['online'])
            ->sortByDesc(fn (array $station) => $station['last_event']->getTimestamp())
            ->first();

        return view('teacher.live', [
            'recent' => $recent,
            'realtimeTokenUrl' => route('realtime.token'),
            'classes' => $classes,
            'selectedClass' => $selectedClass,
            'classIds' => $classIds,
            'stations' => $stations,
            'today' => $this->reports->summary(
                Carbon::today()->toDateString(),
                Carbon::today()->toDateString(),
                $classIds,
            ),
            'silentStation' => $silent !== null
                ? [
                    'label' => $silent['label'],
                    'minutes' => (int) $silent['last_event']->diffInMinutes(now()),
                ]
                : null,
        ]);
    }

    /** @return Collection<int, SchoolClass> */
    private function classesInScope(StudentScope $scope): Collection
    {
        if ($scope->isUnrestricted()) {
            return SchoolClass::orderBy('name')->get();
        }

        return SchoolClass::whereIn('id', $scope->classIdFilter() ?? [])->orderBy('name')->get();
    }

    private function selectedClass(Request $request, Collection $classes): ?SchoolClass
    {
        $id = (int) $request->query('class', 0);

        if ($id <= 0) {
            return null;
        }

        return $classes->first(fn (SchoolClass $class) => (int) $class->id === $id);
    }

    private function rangeKey(Request $request): string
    {
        $range = (string) $request->query('range', '30d');

        return array_key_exists($range, self::RANGES) ? $range : '30d';
    }

    /** @return array{0: string, 1: string} */
    private function resolveRange(Request $request): array
    {
        if ($request->filled('from') || $request->filled('to')) {
            return ReportRange::resolve($request);
        }

        $today = Carbon::today();

        [$from, $to] = match ($this->rangeKey($request)) {
            '4w' => [$today->copy()->subDays(27), $today],
            '8w' => [$today->copy()->subDays(55), $today],
            '12w' => [$today->copy()->subDays(83), $today],
            'month' => [$today->copy()->startOfMonth(), $today],
            'year' => [$today->copy()->startOfYear(), $today],
            default => [$today->copy()->subDays(29), $today],
        };

        return [$from->toDateString(), $to->toDateString()];
    }
}
