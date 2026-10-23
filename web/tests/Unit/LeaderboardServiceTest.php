<?php

namespace Tests\Unit;

use App\Models\PointsLedger;
use App\Models\Student;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Leaderboard ranking semantics: ledger-derived totals, deterministic
 * ordering (points DESC, id ASC), competition ranks on ties (1, 2, 2, 4).
 * The class board ranks by AVERAGE points per student so small classes
 * are not punished by headcount.
 */
class LeaderboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaderboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->service = app(LeaderboardService::class);
    }

    #[Test]
    public function ties_share_a_rank_and_the_next_rank_skips(): void
    {
        $maria = $this->studentIdOf('Maria González');
        $carlos = $this->studentIdOf('Carlos Pérez');
        $ana = $this->studentIdOf('Ana Martínez');
        $diego = $this->studentIdOf('Diego López');

        $this->award($diego, 30);   // 1st
        $this->award($maria, 10);   // tie 2nd
        $this->award($ana, 10);     // tie 2nd
        // Carlos: 0 points — still on the board (SUM 0).

        $top = $this->service->top();

        $this->assertSame(1, $top[0]['rank']);
        $this->assertSame($diego, $top[0]['student_id']);

        $this->assertSame(2, $top[1]['rank']);
        $this->assertSame(2, $top[2]['rank']);
        $this->assertEqualsCanonicalizing(
            [min($maria, $ana), max($maria, $ana)],
            [$top[1]['student_id'], $top[2]['student_id']],
            'ties order deterministically by student id',
        );

        $this->assertSame(4, $top[3]['rank'], 'competition ranking: the rank after a tie of 2 skips ahead');
        $this->assertSame($carlos, $top[3]['student_id']);
        $this->assertSame(0, $top[3]['points']);
    }

    #[Test]
    public function rank_of_returns_the_students_own_standing(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $this->award($this->studentIdOf('Carlos Pérez'), 25);
        $this->award($maria->id, 10);

        $standing = $this->service->rankOf($maria);

        $this->assertSame(2, $standing['rank']);
        $this->assertSame(10, $standing['points']);
    }

    #[Test]
    public function rank_of_shares_the_board_rank_under_ties(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $ana = Student::where('name', 'Ana Martínez')->firstOrFail();

        $this->award($this->studentIdOf('Carlos Pérez'), 25);
        $this->award($maria->id, 10);
        $this->award($ana->id, 10);
        $this->award($this->studentIdOf('Diego López'), 10);

        // Three students tie at 10: they share 2nd place, and the
        // zero-point student below them lands on 5th.
        $this->assertSame(['rank' => 2, 'points' => 10], $this->service->rankOf($maria));
        $this->assertSame(['rank' => 2, 'points' => 10], $this->service->rankOf($ana));

        $lucia = Student::where('name', 'Lucía Fernández')->firstOrFail();
        $this->assertSame(['rank' => 5, 'points' => 0], $this->service->rankOf($lucia));

        // The bounded query agrees entry-by-entry with the full board.
        foreach ($this->service->top(100) as $entry) {
            $student = Student::findOrFail($entry['student_id']);
            $this->assertSame($entry['rank'], $this->service->rankOf($student)['rank']);
        }
    }

    #[Test]
    public function rank_of_does_not_load_the_whole_board(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $this->award($maria->id, 10);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->service->rankOf($maria);

        $this->assertLessThanOrEqual(2, $queries, 'rankOf debe usar consultas acotadas, no el tablero completo');
    }

    #[Test]
    public function a_student_without_movements_ranks_last_with_zero(): void
    {
        $this->award($this->studentIdOf('Maria González'), 10);
        $this->award($this->studentIdOf('Carlos Pérez'), 5);
        $this->award($this->studentIdOf('Diego López'), 3);

        $ana = Student::where('name', 'Ana Martínez')->firstOrFail();

        $standing = $this->service->rankOf($ana);

        $this->assertSame(4, $standing['rank']);
        $this->assertSame(0, $standing['points']);
    }

    #[Test]
    public function the_limit_is_respected(): void
    {
        $this->award($this->studentIdOf('Maria González'), 5);

        $this->assertCount(2, $this->service->top(2));
    }

    #[Test]
    public function negative_movements_count_against_the_total(): void
    {
        $maria = $this->studentIdOf('Maria González');
        $this->award($maria, 25);

        PointsLedger::create([
            'student_id' => $maria,
            'delta' => -5,
            'reason' => 'adjustment',
        ]);

        $top = $this->service->top();

        $this->assertSame(20, $top[0]['points']);
    }

    #[Test]
    public function the_snapshot_is_a_compact_top_n(): void
    {
        $this->award($this->studentIdOf('Maria González'), 10);
        $this->award($this->studentIdOf('Carlos Pérez'), 15);
        $this->award($this->studentIdOf('Ana Martínez'), 5);

        $snapshot = $this->service->snapshot();

        $this->assertSame(['rank', 'student_id', 'student_name', 'points'], array_keys($snapshot[0]));
        $this->assertSame('Carlos Pérez', $snapshot[0]['student_name']);
        $this->assertCount(3, $snapshot);
    }

    #[Test]
    public function classes_rank_by_average_points_per_student_with_every_class_listed(): void
    {
        $this->seedDemo();
        PointsLedger::create(['student_id' => $this->cardOf('Diego López')->student_id, 'delta' => 15, 'reason' => 'recycling_deposit']);
        PointsLedger::create(['student_id' => $this->cardOf('Maria González')->student_id, 'delta' => 22, 'reason' => 'recycling_deposit']);
        $board = app(LeaderboardService::class)->classes();
        // 5° A has more total points (22 vs 15) but a lower average (7.3 vs 7.5).
        $this->assertSame(['5° B', '5° A'], array_column($board, 'class_name'));
        $this->assertSame([1, 2], array_column($board, 'rank'));
        $this->assertSame([2, 3], array_column($board, 'students'));
        $this->assertSame([15, 22], array_column($board, 'points'));
        $this->assertSame([7.5, 7.3], array_column($board, 'avg_points'));
    }

    #[Test]
    public function class_ties_share_a_rank_and_order_by_class_id(): void
    {
        $board = app(LeaderboardService::class)->classes();

        $this->assertSame([1, 1], array_column($board, 'rank'), 'every class has zero average — a tie');
        $ids = array_column($board, 'class_id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'ties order deterministically by class id');
        $this->assertSame([0.0, 0.0], array_column($board, 'avg_points'));
    }

    // ------------------------------------------------------------- helpers

    private function studentIdOf(string $name): int
    {
        return Student::where('name', $name)->firstOrFail()->id;
    }

    private function award(int $studentId, int $points): void
    {
        PointsLedger::create([
            'student_id' => $studentId,
            'delta' => $points,
            'reason' => 'recycling_deposit',
        ]);
    }
}
