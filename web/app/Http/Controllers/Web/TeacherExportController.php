<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Pdf\RecyclingReportPdf;
use App\Services\RecyclingReportService;
use App\Services\StudentScope;
use App\Support\ReportRange;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Teacher CSV/PDF exports. The range and the class scope are the same
 * ones the dashboard renders, and an out-of-scope student answers 404
 * before any bytes are produced.
 */
class TeacherExportController extends Controller
{
    /** The receipt columns; the header is pinned by the plan. */
    private const COLUMNS = ['fecha', 'hora', 'estudiante', 'curso', 'material', 'puntos', 'reciclable'];

    public function __construct(
        private readonly RecyclingReportService $reports,
        private readonly RecyclingReportPdf $pdf,
    ) {}

    /**
     * The read-only export landing page: scope, range summary and links
     * to the existing CSV/PDF routes. It never produces a file itself.
     */
    public function page(Request $request): View
    {
        $scope = StudentScope::forUser($request->user());
        [$from, $to] = ReportRange::resolve($request);

        $classIds = $scope->classIdFilter();

        $classes = $classIds === null
            ? SchoolClass::orderBy('name')->get()
            : SchoolClass::whereIn('id', $classIds)->orderBy('name')->get();

        $query = ['from' => $from, 'to' => $to];

        return view('teacher.export', [
            'from' => $from,
            'to' => $to,
            'classes' => $classes,
            'summary' => $this->reports->summary($from, $to, $classIds),
            'byMaterial' => $this->reports->byMaterial($from, $to, $classIds),
            'csvUrl' => route('teacher.export.csv', $query),
            'pdfUrl' => route('teacher.export.pdf', $query),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $scope = StudentScope::forUser($request->user());
        [$from, $to] = ReportRange::resolve($request);

        $rows = $this->reports->deposits($from, $to, $scope->classIdFilter());

        return $this->streamCsv('reciclaje-'.$from.'-'.$to.'.csv', $rows);
    }

    public function pdf(Request $request): Response
    {
        $scope = StudentScope::forUser($request->user());
        [$from, $to] = ReportRange::resolve($request);

        return $this->pdf->summary($from, $to, $scope->classIdFilter());
    }

    public function studentCsv(Request $request, Student $student): StreamedResponse
    {
        $this->authorizeStudent($request, $student);

        $history = $this->reports->studentHistory($student, 90);
        $rows = [];

        foreach ($history['deposits'] as $deposit) {
            $rows[] = [
                'date' => $deposit['date'],
                'time' => $deposit['time'],
                'student' => $history['student']['name'],
                'class' => $deposit['class_name'] ?? $history['student']['class_name'],
                'material' => $deposit['material'],
                'points' => $deposit['points'],
                'is_recyclable' => $deposit['is_recyclable'],
            ];
        }

        return $this->streamCsv('reciclaje-estudiante-'.$student->id.'.csv', $rows);
    }

    public function studentPdf(Request $request, Student $student): Response
    {
        $this->authorizeStudent($request, $student);

        return $this->pdf->student($student);
    }

    /**
     * @param  array<int, array{date: string, time: string, student: string, class: ?string, material: string, points: int, is_recyclable: bool}>  $rows
     */
    private function streamCsv(string $filename, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::COLUMNS, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['date'],
                    $row['time'],
                    $row['student'],
                    $row['class'] ?? '',
                    $row['material'],
                    (string) $row['points'],
                    $row['is_recyclable'] ? 'sí' : 'no',
                ], ',', '"', '');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authorizeStudent(Request $request, Student $student): void
    {
        abort_unless(StudentScope::forUser($request->user())->allowsStudent($student), 404);
    }
}
