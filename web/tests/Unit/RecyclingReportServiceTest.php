<?php

namespace Tests\Unit;

use App\Enums\MaterialClass;
use App\Models\PointsLedger;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Services\RecyclingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one reporting data layer behind /teacher, its CSV/PDF exports and
 * the student breakdown. Every number derives from classified deposits
 * (in turn the events spine) and is filtered by the teacher's class
 * scope.
 */
class RecyclingReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function depositFor(string $name, MaterialClass $material, int $points): RecyclingDeposit
    {
        $event = $this->recyclingTapFor($name);

        PointsLedger::create([
            'student_id' => $event->card->student_id,
            'delta' => $points,
            'reason' => 'recycling_deposit',
            'event_id' => $event->id,
        ]);

        return RecyclingDeposit::create([
            'event_id' => $event->id,
            'material_class' => $material->value,
            'confidence' => 0.9,
            'points_awarded' => $points,
            'is_bottle' => $material === MaterialClass::Plastic,
            'is_recyclable' => true,
        ]);
    }

    private function service(): RecyclingReportService
    {
        return app(RecyclingReportService::class);
    }

    private function classId(string $name): int
    {
        return (int) Student::where('name', $name)->firstOrFail()->class_id;
    }

    #[Test]
    public function by_material_only_counts_the_requested_classes_and_uses_live_rates(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $this->depositFor('Diego López', MaterialClass::Metal, 15);

        $today = Carbon::today()->toDateString();
        $mix = $this->service()->byMaterial($today, $today, [$this->classId('Maria González')]);

        $this->assertSame(1, $mix['plastic']['items']);
        $this->assertSame(10, $mix['plastic']['points']);
        $this->assertSame(0, $mix['metal']['items']);
        $this->assertSame(MaterialClass::Plastic->points(), $mix['plastic']['rate']);
        $this->assertSame(0, $mix['other']['items']);
    }

    #[Test]
    public function by_class_lists_every_class_in_scope_with_its_totals(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $this->depositFor('Diego López', MaterialClass::Metal, 15);

        $today = Carbon::today()->toDateString();
        $rows = $this->service()->byClass($today, $today);

        $this->assertCount(2, $rows);
        $this->assertSame(['5° A', '5° B'], array_column($rows, 'class_name'));
        $this->assertSame([10, 15], array_column($rows, 'points'));
    }

    #[Test]
    public function by_class_honours_the_class_scope(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $this->depositFor('Diego López', MaterialClass::Metal, 15);

        $today = Carbon::today()->toDateString();
        $rows = $this->service()->byClass($today, $today, [$this->classId('Maria González')]);

        $this->assertCount(1, $rows);
        $this->assertSame('5° A', $rows[0]['class_name']);
        $this->assertSame(10, $rows[0]['points']);
    }

    #[Test]
    public function the_trend_is_zero_filled_across_the_whole_range(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);

        $from = Carbon::today()->subDays(2)->toDateString();
        $to = Carbon::today()->toDateString();
        $trend = $this->service()->trend($from, $to);

        $this->assertCount(3, $trend);
        $this->assertSame([$from, Carbon::today()->subDay()->toDateString(), $to], array_column($trend, 'date'));
        $this->assertSame([0, 0, 1], array_column($trend, 'items'));
        $this->assertSame([0, 0, 10], array_column($trend, 'points'));
    }

    #[Test]
    public function the_student_history_has_deposit_rows_and_honest_totals(): void
    {
        $deposit = $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $student = Student::where('name', 'Maria González')->firstOrFail();

        $history = $this->service()->studentHistory($student);

        $this->assertCount(1, $history['deposits']);
        $row = $history['deposits'][0];
        $this->assertSame($deposit->id, $row['deposit_id']);
        $this->assertSame('plastic', $row['material']);
        $this->assertSame(10, $row['points']);
        $this->assertTrue($row['is_recyclable']);
        $this->assertFalse($row['has_photo']);
        $this->assertSame($this->reader()->label, $row['reader']);
        $this->assertSame(['items' => 1, 'points' => 10], $history['totals']);
    }

    #[Test]
    public function the_weekly_material_trend_zero_fills_every_material_and_week(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);

        $today = Carbon::today()->toDateString();
        $weeks = $this->service()->materialWeeklyTrend($today, $today);

        $this->assertCount(1, $weeks);
        $this->assertSame(1, $weeks[0]['materials']['plastic']);
        $this->assertSame(0, $weeks[0]['materials']['metal']);
        $this->assertSame(0, $weeks[0]['materials']['other']);
        $this->assertSame(1, $weeks[0]['total']);
    }

    #[Test]
    public function the_summary_counts_active_students_points_and_the_previous_period(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);

        $today = Carbon::today()->toDateString();
        $summary = $this->service()->summary($today, $today);

        $this->assertSame(1, $summary['items']);
        $this->assertSame(10, $summary['points']);
        $this->assertSame(1, $summary['activeStudents']);
        $this->assertSame(Student::count(), $summary['totalStudents']);
        $this->assertSame(100, $summary['recyclablePct']);
        $this->assertSame(0, $summary['previousItems']);
        $this->assertNull($summary['deltaPct']);
        $this->assertSame(2, $summary['averagePoints']);
    }

    #[Test]
    public function the_most_active_students_are_ranked_by_points_inside_the_scope(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $this->depositFor('Maria González', MaterialClass::Metal, 15);
        $this->depositFor('Diego López', MaterialClass::Paper, 5);

        $today = Carbon::today()->toDateString();
        $rows = $this->service()->topStudents($today, $today, [$this->classId('Maria González')]);

        $this->assertCount(1, $rows);
        $this->assertSame('Maria González', $rows[0]['name']);
        $this->assertSame(2, $rows[0]['items']);
        $this->assertSame(25, $rows[0]['points']);
    }

    #[Test]
    public function the_export_rows_carry_the_receipt_fields(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $this->depositFor('Diego López', MaterialClass::Metal, 15);

        $today = Carbon::today()->toDateString();
        $rows = $this->service()->deposits($today, $today, [$this->classId('Maria González')]);

        $this->assertCount(1, $rows);
        $this->assertSame('Maria González', $rows[0]['student']);
        $this->assertSame('5° A', $rows[0]['class']);
        $this->assertSame('plastic', $rows[0]['material']);
        $this->assertSame(10, $rows[0]['points']);
        $this->assertTrue($rows[0]['is_recyclable']);
    }
}
