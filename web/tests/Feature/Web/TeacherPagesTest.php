<?php

namespace Tests\Feature\Web;

use App\Enums\MaterialClass;
use App\Models\PointsLedger;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teacher reporting surface, scoped by StudentScope. A teacher only
 * ever sees their own classes; every out-of-scope student, class or
 * export answers 404 — the same answer as a row that does not exist.
 */
class TeacherPagesTest extends TestCase
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

    private function studentId(string $name): int
    {
        return (int) Student::where('name', $name)->firstOrFail()->id;
    }

    private function classId(string $name): int
    {
        return (int) Student::where('name', $name)->firstOrFail()->class_id;
    }

    private function teacherOf(int $classId, string $email): User
    {
        $teacher = User::forceCreate([
            'name' => 'Docente '.$email,
            'email' => $email,
            'password' => 'password',
            'role' => 'teacher',
            'must_change_password' => false,
        ]);

        $teacher->classes()->attach($classId);

        return $teacher;
    }

    #[Test]
    public function the_dashboard_only_shows_the_teachers_own_classes(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $this->depositFor('Diego López', MaterialClass::Metal, 15);

        $this->actingAs($this->teacher())
            ->get('/teacher')
            ->assertOk()
            ->assertViewHas('byClass', fn ($rows) => count($rows) === 1 && $rows[0]['class_name'] === '5° A')
            ->assertViewHas('trend')
            ->assertViewHas('byMaterial');
    }

    #[Test]
    public function a_teacher_opens_their_own_class_and_student_breakdown(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);

        $this->actingAs($this->teacher())
            ->get('/teacher/classes/'.$this->classId('Maria González'))
            ->assertOk()
            ->assertViewHas('students', fn ($students) => count($students) === 3)
            ->assertViewHas('byMaterial');

        $this->actingAs($this->teacher())
            ->get('/teacher/students/'.$this->studentId('Maria González'))
            ->assertOk()
            ->assertViewHas('history', fn ($history) => $history['totals']['points'] === 10);
    }

    #[Test]
    public function out_of_scope_students_classes_and_exports_are_404(): void
    {
        $diego = $this->studentId('Diego López');
        $classB = $this->classId('Diego López');

        $teacher = $this->teacher();

        $this->actingAs($teacher)->get("/teacher/students/{$diego}")->assertNotFound();
        $this->actingAs($teacher)->get("/teacher/classes/{$classB}")->assertNotFound();
        $this->actingAs($teacher)->get("/teacher/students/{$diego}/export.csv")->assertNotFound();
        $this->actingAs($teacher)->get("/teacher/students/{$diego}/export.pdf")->assertNotFound();
    }

    #[Test]
    public function the_csv_export_contains_only_in_scope_deposits_with_the_receipt_columns(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $this->depositFor('Diego López', MaterialClass::Metal, 15);

        $response = $this->actingAs($this->teacher())->get('/teacher/export.csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('fecha,hora,estudiante,curso,material,puntos,reciclable', $csv);
        $this->assertStringContainsString('Maria González', $csv);
        $this->assertStringContainsString('5° A', $csv);
        $this->assertStringContainsString('plastic', $csv);
        $this->assertStringNotContainsString('Diego López', $csv);
    }

    #[Test]
    public function the_pdf_export_answers_with_a_pdf(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);

        $this->actingAs($this->teacher())
            ->get('/teacher/export.pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function the_student_exports_work_for_an_in_scope_student(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $maria = $this->studentId('Maria González');

        $csv = $this->actingAs($this->teacher())->get("/teacher/students/{$maria}/export.csv");
        $csv->assertOk();
        $this->assertStringContainsString('Maria González', $csv->streamedContent());

        $this->actingAs($this->teacher())
            ->get("/teacher/students/{$maria}/export.pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function the_live_page_passes_the_scoped_feed_and_token_route(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $this->depositFor('Diego López', MaterialClass::Metal, 15);

        $this->actingAs($this->teacher())
            ->get('/teacher/live')
            ->assertOk()
            ->assertViewHas('recent', fn ($rows) => count($rows) === 1)
            ->assertViewHas('realtimeTokenUrl', route('realtime.token'));
    }

    #[Test]
    public function exports_use_the_events_class_snapshot_after_a_transfer(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);

        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $classB = $this->classId('Diego López');

        // The student transfers to 5° B after the historical 5° A deposit.
        $maria->update(['class_id' => $classB]);

        $own = $this->actingAs($this->teacher())->get('/teacher/export.csv');
        $own->assertOk();
        $this->assertStringContainsString('Maria González', $own->streamedContent());
        $this->assertStringContainsString('5° A', $own->streamedContent());

        $otherTeacher = $this->teacherOf($classB, 'docente-b@ecostation.test');
        $this->actingAsFresh($otherTeacher);
        $other = $this->get('/teacher/export.csv');
        $other->assertOk();
        $this->assertStringNotContainsString('Maria González', $other->streamedContent());
    }

    #[Test]
    public function the_admin_sees_every_class(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);
        $this->depositFor('Diego López', MaterialClass::Metal, 15);

        $this->actingAs($this->admin())
            ->get('/teacher')
            ->assertOk()
            ->assertViewHas('byClass', fn ($rows) => count($rows) === 2);

        $this->actingAs($this->admin())
            ->get('/teacher/classes/'.$this->classId('Diego López'))
            ->assertOk();
    }

    #[Test]
    public function a_student_is_forbidden(): void
    {
        $this->actingAs($this->cardOf('Maria González')->student->account)
            ->get('/teacher')
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get('/teacher')->assertRedirect('/login');
    }

    #[Test]
    public function from_and_to_query_parameters_are_validated_into_a_safe_range(): void
    {
        $from = Carbon::today()->subDays(6)->toDateString();
        $to = Carbon::today()->toDateString();

        $this->actingAs($this->teacher())
            ->get("/teacher?from={$from}&to={$to}")
            ->assertOk()
            ->assertViewHas('from', $from)
            ->assertViewHas('to', $to)
            ->assertViewHas('trend', fn ($rows) => count($rows) === 7);
    }
}
