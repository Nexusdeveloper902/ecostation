<?php

namespace Tests\Unit;

use App\Enums\MaterialClass;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Student;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Points accounting: balances are always SUM(points_ledger.delta) and
 * the ledger is append-only. Award amounts come from MaterialClass only.
 */
class PointsServiceTest extends TestCase
{
    use RefreshDatabase;

    private PointsService $points;

    protected function setUp(): void
    {
        parent::setUp();
        $this->points = app(PointsService::class);
        $this->seedDemo();
    }

    #[Test]
    public function balance_starts_at_zero(): void
    {
        $this->assertSame(0, $this->points->balance(Student::first()));
    }

    #[Test]
    public function balance_is_the_sum_of_deltas(): void
    {
        $student = Student::first();

        $this->points->awardRecyclingPoints($student, $this->makeEvent($student), MaterialClass::Plastic); // +10
        $this->points->awardRecyclingPoints($student, $this->makeEvent($student), MaterialClass::Metal); // +15

        $this->assertSame(25, $this->points->balance($student));
    }

    #[Test]
    public function recycling_points_match_the_configured_table(): void
    {
        $student = Student::first();
        $event = $this->makeEvent($student);

        foreach (MaterialClass::cases() as $material) {
            $awarded = $this->points->awardRecyclingPoints(
                Student::create(['name' => 'L '.$material->value, 'class_id' => $student->class_id]),
                $event,
                $material
            );

            $this->assertSame(
                (int) config("ecostation.points.{$material->value}"),
                $awarded,
                "Points for [{$material->value}] must match config/ecostation.php"
            );
        }
    }

    #[Test]
    public function zero_point_materials_do_not_create_ledger_noise(): void
    {
        $student = Student::first();
        $this->points->awardRecyclingPoints($student, $this->makeEvent($student), MaterialClass::Other); // 0

        $this->assertSame(0, PointsLedger::where('student_id', $student->id)->count());
        $this->assertSame(0, $this->points->balance($student));
    }

    #[Test]
    public function the_statement_splits_earned_and_spent(): void
    {
        $student = Student::first();
        $this->points->awardRecyclingPoints($student, $this->makeEvent($student), MaterialClass::Metal); // +15

        PointsLedger::create([
            'student_id' => $student->id,
            'delta' => -5,
            'reason' => 'adjustment',
        ]);

        $this->assertSame(
            ['balance' => 10, 'earned' => 15, 'spent' => 5],
            $this->points->statement($student)
        );
    }

    #[Test]
    public function changing_a_material_rate_is_never_retroactive(): void
    {
        $this->seedDemo();
        $student = $this->cardOf('Maria González')->student;
        $points = app(PointsService::class);
        $points->awardRecyclingPoints($student, $this->recyclingTapFor('Maria González'), MaterialClass::Plastic);
        settings()->setMany(['points.plastic' => 20]);
        $points->awardRecyclingPoints($student, $this->recyclingTapFor('Maria González'), MaterialClass::Plastic);
        $this->assertSame([10, 20], PointsLedger::where('student_id', $student->id)->orderBy('id')->pluck('delta')->all());
        $this->assertSame(30, $points->balance($student));
    }

    private function makeEvent(Student $student): PresenceEvent
    {
        return PresenceEvent::create([
            'card_id' => $student->cards()->first()->id,
            'reader_id' => Reader::first()->id,
            'class_id' => $student->class_id,
            'occurred_at' => now(),
        ]);
    }
}
