<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\PointsService;
use App\Services\Realtime\RealtimeFeed;
use App\Services\RecyclingReportService;
use App\Services\StudentScope;
use App\Support\ReportRange;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public function __construct(
        private readonly RecyclingReportService $reports,
        private readonly RealtimeFeed $realtimeFeed,
        private readonly PointsService $points,
    ) {}

    public function dashboard(Request $request): View
    {
        $scope = StudentScope::forUser($request->user());
        [$from, $to] = ReportRange::resolve($request);
        $classIds = $scope->classIdFilter();

        return view('teacher.dashboard', [
            'from' => $from,
            'to' => $to,
            'classes' => $this->classesInScope($scope),
            'trend' => $this->reports->trend($from, $to, $classIds),
            'byMaterial' => $this->reports->byMaterial($from, $to, $classIds),
            'byClass' => $this->reports->byClass($from, $to, $classIds),
        ]);
    }

    public function showClass(Request $request, SchoolClass $class): View
    {
        $scope = StudentScope::forUser($request->user());
        $classIds = $scope->classIdFilter();

        abort_if($classIds !== null && ! in_array((int) $class->id, $classIds, true), 404);

        [$from, $to] = ReportRange::resolve($request);

        $students = $class->students()
            ->orderBy('name')
            ->get()
            ->map(fn (Student $student) => [
                'id' => (int) $student->id,
                'name' => (string) $student->name,
                'balance' => $this->points->balance($student),
            ])
            ->all();

        return view('teacher.class', [
            'from' => $from,
            'to' => $to,
            'class' => $class,
            'students' => $students,
            'classes' => $this->classesInScope($scope),
            'byMaterial' => $this->reports->byMaterial($from, $to, [(int) $class->id]),
        ]);
    }

    public function showStudent(Request $request, Student $student): View
    {
        $scope = StudentScope::forUser($request->user());

        abort_unless($scope->allowsStudent($student), 404);

        return view('teacher.student', [
            'student' => $student,
            'history' => $this->reports->studentHistory($student),
            'classes' => $this->classesInScope($scope),
        ]);
    }

    public function live(Request $request): View
    {
        $scope = StudentScope::forUser($request->user());

        return view('teacher.live', [
            'recent' => $this->realtimeFeed->recent(20, $scope->classIdFilter()),
            'realtimeTokenUrl' => route('realtime.token'),
            'classes' => $this->classesInScope($scope),
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
}
