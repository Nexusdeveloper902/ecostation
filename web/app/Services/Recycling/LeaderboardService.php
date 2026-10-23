<?php

namespace App\Services\Recycling;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The leaderboard. Ranking derives EXCLUSIVELY from the real points
 * ledger (SUM(delta) per student — students with no movements rank at
 * zero), never from a denormalized counter that could drift. Ties share
 * a competition rank (1, 2, 2, 4) and order deterministically by id, so
 * the same data always renders the same board on every client.
 *
 * The class board ranks by AVERAGE points per student: a small class is
 * not punished by headcount. Every class appears (zero included).
 */
class LeaderboardService
{
    /**
     * The top-N students, rank-stamped.
     *
     * @return array<int, array{rank: int, student_id: int, student_name: string, class_name: string|null, points: int}>
     */
    public function top(int $limit = 10): array
    {
        return $this->stampRanks($this->scored()->limit($limit)->get());
    }

    /**
     * A student's own standing (rank + points), competition-ranked.
     *
     * No carga el tablero completo: dos consultas acotadas al índice de
     * `points_ledger` — la suma propia y cuántos estudiantes tienen una
     * suma estrictamente mayor (los empates comparten el mejor puesto).
     *
     * @return array{rank: int|null, points: int}
     */
    public function rankOf(Student $student): array
    {
        $totals = DB::query()->fromSub(
            DB::table('students')
                ->leftJoin('points_ledger', 'points_ledger.student_id', '=', 'students.id')
                ->groupBy('students.id')
                ->select([
                    'students.id as student_id',
                    DB::raw('COALESCE(SUM(points_ledger.delta), 0) as points'),
                ]),
            'scored',
        );

        $own = (clone $totals)->where('student_id', (int) $student->id)->value('points');
        $points = $own !== null ? (int) $own : 0;

        // Competición (1, 2, 2, 4): el puesto es uno más que el número
        // de estudiantes con MÁS puntos; los empates no lo incrementan.
        $better = (clone $totals)->where('points', '>', $points)->count();

        return ['rank' => $better + 1, 'points' => $points];
    }

    /**
     * A small top-N snapshot for the leaderboard_updated frame payload
     * (computed inside the award transaction — deliberately cheap).
     *
     * @return array<int, array{rank: int, student_id: int, student_name: string, points: int}>
     */
    public function snapshot(int $limit = 3): array
    {
        return array_map(
            fn (array $entry) => [
                'rank' => $entry['rank'],
                'student_id' => $entry['student_id'],
                'student_name' => $entry['student_name'],
                'points' => $entry['points'],
            ],
            $this->top($limit),
        );
    }

    /**
     * Every class, ranked by average points per student (competition
     * ranks; ties order by class id ascending).
     *
     * @return array<int, array{rank: int, class_id: int, class_name: string, students: int, points: int, avg_points: float}>
     */
    public function classes(): array
    {
        $rows = DB::table('classes')
            ->leftJoin('students', 'students.class_id', '=', 'classes.id')
            ->leftJoin('points_ledger', 'points_ledger.student_id', '=', 'students.id')
            ->groupBy('classes.id', 'classes.name')
            ->select([
                'classes.id as class_id',
                'classes.name as class_name',
                DB::raw('COUNT(DISTINCT students.id) as students'),
                DB::raw('CAST(COALESCE(SUM(points_ledger.delta), 0) AS INTEGER) as points'),
            ])
            ->get()
            ->map(fn ($row) => [
                'class_id' => (int) $row->class_id,
                'class_name' => (string) $row->class_name,
                'students' => (int) $row->students,
                'points' => (int) $row->points,
                'avg_points' => round((int) $row->points / max((int) $row->students, 1), 1),
            ])
            ->sort(fn (array $a, array $b) => $a['avg_points'] === $b['avg_points']
                ? $a['class_id'] <=> $b['class_id']
                : $b['avg_points'] <=> $a['avg_points'])
            ->values();

        $ranks = $this->competitionRanks($rows->pluck('avg_points')->all());

        return $rows->map(fn (array $row, int $index) => [
            'rank' => $ranks[$index],
            'class_id' => $row['class_id'],
            'class_name' => $row['class_name'],
            'students' => $row['students'],
            'points' => $row['points'],
            'avg_points' => $row['avg_points'],
        ])->all();
    }

    /**
     * The ordered, point-scored board base: EVERY student appears (zero
     * movements = zero points), points DESC, student_id ASC.
     */
    private function scored(): Builder
    {
        return Student::query()
            ->leftJoin('points_ledger', 'points_ledger.student_id', '=', 'students.id')
            ->leftJoin('classes', 'classes.id', '=', 'students.class_id')
            ->groupBy('students.id', 'students.name', 'classes.name')
            ->orderByDesc(DB::raw('COALESCE(SUM(points_ledger.delta), 0)'))
            ->orderBy('students.id')
            ->select([
                'students.id as student_id',
                'students.name as student_name',
                'classes.name as class_name',
                DB::raw('CAST(COALESCE(SUM(points_ledger.delta), 0) AS INTEGER) as points'),
            ]);
    }

    /**
     * Competition ranking (1, 2, 2, 4): equal values share the better
     * place; the next distinct value skips ahead by the tie size.
     *
     * @param  array<int, int|float>  $values  already ordered high → low
     * @return array<int, int> ranks aligned with the input indexes
     */
    private function competitionRanks(array $values): array
    {
        $ranks = [];
        $rank = 0;
        $previous = null;
        $first = true;

        foreach ($values as $index => $value) {
            if ($first || $value !== $previous) {
                $rank = $index + 1;
                $previous = $value;
                $first = false;
            }

            $ranks[$index] = $rank;
        }

        return $ranks;
    }

    /**
     * @param  iterable<object{student_id: int, student_name: string, class_name: string|null, points: int}>  $rows
     * @return array<int, array{rank: int, student_id: int, student_name: string, class_name: string|null, points: int}>
     */
    private function stampRanks(iterable $rows): array
    {
        $rows = Collection::make($rows)->values();
        $ranks = $this->competitionRanks($rows->map(fn ($row) => (int) $row->points)->all());

        return $rows->map(fn ($row, int $index) => [
            'rank' => $ranks[$index],
            'student_id' => (int) $row->student_id,
            'student_name' => (string) $row->student_name,
            'class_name' => $row->class_name !== null ? (string) $row->class_name : null,
            'points' => (int) $row->points,
        ])->all();
    }
}
