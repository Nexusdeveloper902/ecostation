<?php

namespace App\Services;

use App\Enums\MaterialClass;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The recycling reporting data layer: per-day trend, material mix,
 * class totals and per-student histories. ONE truth behind /teacher,
 * its CSV/PDF exports and the student breakdown, so an export can never
 * disagree with the screen.
 *
 * Everything derives from classified deposits (never raw taps): an
 * unclassified tap has awarded no points and moved no material total.
 * Every method takes `$classIds`; null means the whole school, an array
 * means that teacher's classes (an empty array matches nothing, which
 * is how the scope fails closed).
 */
class RecyclingReportService
{
    /**
     * Per-day items + points over an inclusive range (zero-filled).
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{date: string, items: int, points: int}>
     */
    public function trend(string $from, string $to, ?array $classIds = null): array
    {
        $rows = $this->depositsQuery($from, $to, $classIds)
            ->selectRaw('date(e.occurred_at) as day, count(*) as items, coalesce(sum(d.points_awarded), 0) as points')
            ->groupByRaw('date(e.occurred_at)')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row->day] = ['items' => (int) $row->items, 'points' => (int) $row->points];
        }

        $trend = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $trend[] = [
                'date' => $day,
                'items' => $byDay[$day]['items'] ?? 0,
                'points' => $byDay[$day]['points'] ?? 0,
            ];
            $cursor->addDay();
        }

        return $trend;
    }

    /**
     * The material mix over an inclusive range: every material appears,
     * even at zero. `rate` is the LIVE admin rate, not a config snapshot.
     *
     * @param  array<int, int>|null  $classIds
     * @return array<string, array{items: int, points: int, rate: int}>
     */
    public function byMaterial(string $from, string $to, ?array $classIds = null): array
    {
        $mix = [];
        foreach (MaterialClass::cases() as $material) {
            $mix[$material->value] = ['items' => 0, 'points' => 0, 'rate' => $material->points()];
        }

        $rows = $this->depositsQuery($from, $to, $classIds)
            ->selectRaw('d.material_class as material_class, count(*) as items, coalesce(sum(d.points_awarded), 0) as points')
            ->groupBy('d.material_class')
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row->material_class;
            $material = MaterialClass::tryFrom($key);

            $mix[$key] = [
                'items' => (int) $row->items,
                'points' => (int) $row->points,
                'rate' => $material?->points() ?? 0,
            ];
        }

        return $mix;
    }

    /**
     * Items + points per class. Every class in scope is listed, including
     * quiet ones, so the dashboard table is an honest comparison.
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{class_id: int, class_name: string, items: int, points: int}>
     */
    public function byClass(string $from, string $to, ?array $classIds = null): array
    {
        $classes = SchoolClass::query()
            ->when($classIds !== null, fn ($query) => $query->whereIn('id', $classIds))
            ->orderBy('name')
            ->pluck('name', 'id');

        $rows = $this->depositsQuery($from, $to, $classIds)
            ->whereNotNull('cl.id')
            ->selectRaw('cl.id as class_id, count(*) as items, coalesce(sum(d.points_awarded), 0) as points')
            ->groupBy('cl.id', 'cl.name')
            ->get()
            ->keyBy('class_id');

        $result = [];
        foreach ($classes as $id => $name) {
            $row = $rows->get($id);
            $result[] = [
                'class_id' => (int) $id,
                'class_name' => (string) $name,
                'items' => $row !== null ? (int) $row->items : 0,
                'points' => $row !== null ? (int) $row->points : 0,
            ];
        }

        return $result;
    }

    /**
     * One student's recycling history: per-deposit rows (material, points,
     * reader, time, whether a photo survives), newest first, plus totals.
     *
     * @return array{student: array{id: int, name: string, class_name: ?string}, deposits: array<int, array{deposit_id: int, date: string, time: string, class_name: ?string, material: string, points: int, is_recyclable: bool, has_photo: bool, reader: ?string}>, totals: array{items: int, points: int}}
     */
    public function studentHistory(Student $student, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $from = Carbon::today()->subDays($days - 1)->startOfDay();

        $rows = DB::table('recycling_deposits as d')
            ->join('events as e', 'e.id', '=', 'd.event_id')
            ->join('cards as c', 'c.id', '=', 'e.card_id')
            ->leftJoin('classes as cl', 'cl.id', '=', 'e.class_id')
            ->leftJoin('readers as r', 'r.id', '=', 'e.reader_id')
            ->where('c.student_id', $student->id)
            ->where('e.occurred_at', '>=', $from)
            ->orderByDesc('e.occurred_at')
            ->orderByDesc('d.id')
            ->get([
                'd.id as deposit_id',
                'd.material_class',
                'd.points_awarded',
                'd.is_recyclable',
                'd.image_path',
                'e.occurred_at',
                'cl.name as class_name',
                'r.label as reader_label',
            ]);

        $deposits = $rows->map(fn ($row) => [
            'deposit_id' => (int) $row->deposit_id,
            'date' => Carbon::parse($row->occurred_at)->toDateString(),
            'time' => Carbon::parse($row->occurred_at)->format('H:i'),
            // Class SNAPSHOT of the tap, for historical exports.
            'class_name' => $row->class_name !== null ? (string) $row->class_name : null,
            'material' => (string) $row->material_class,
            'points' => (int) $row->points_awarded,
            'is_recyclable' => (bool) $row->is_recyclable,
            'has_photo' => $row->image_path !== null,
            'reader' => $row->reader_label !== null ? (string) $row->reader_label : null,
        ])->all();

        return [
            'student' => [
                'id' => (int) $student->id,
                'name' => (string) $student->name,
                'class_name' => $student->schoolClass?->name,
            ],
            'deposits' => $deposits,
            'totals' => [
                'items' => count($deposits),
                'points' => array_sum(array_column($deposits, 'points')),
            ],
        ];
    }

    /**
     * Flat deposit rows for the CSV exports, newest first.
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{date: string, time: string, student: string, class: ?string, material: string, points: int, is_recyclable: bool}>
     */
    public function deposits(string $from, string $to, ?array $classIds = null): array
    {
        return $this->depositsQuery($from, $to, $classIds)
            ->orderByDesc('e.occurred_at')
            ->orderByDesc('d.id')
            ->get([
                'd.material_class',
                'd.points_awarded',
                'd.is_recyclable',
                'e.occurred_at',
                's.name as student_name',
                'cl.name as class_name',
            ])
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->occurred_at)->toDateString(),
                'time' => Carbon::parse($row->occurred_at)->format('H:i'),
                'student' => (string) $row->student_name,
                'class' => $row->class_name !== null ? (string) $row->class_name : null,
                'material' => (string) $row->material_class,
                'points' => (int) $row->points_awarded,
                'is_recyclable' => (bool) $row->is_recyclable,
            ])
            ->all();
    }

    /** @param  array<int, int>|null  $classIds */
    private function depositsQuery(string $from, string $to, ?array $classIds): Builder
    {
        return DB::table('recycling_deposits as d')
            ->join('events as e', 'e.id', '=', 'd.event_id')
            ->join('cards as c', 'c.id', '=', 'e.card_id')
            ->join('students as s', 's.id', '=', 'c.student_id')
            // Historical artifacts follow the class at tap time, not the
            // student's current enrolment: reports and exports stay
            // attached to the class that actually produced them.
            ->leftJoin('classes as cl', 'cl.id', '=', 'e.class_id')
            ->leftJoin('readers as r', 'r.id', '=', 'e.reader_id')
            ->whereBetween('e.occurred_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->when($classIds !== null, fn ($query) => $query->whereIn('e.class_id', $classIds));
    }
}
