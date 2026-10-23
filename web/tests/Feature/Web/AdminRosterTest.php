<?php

namespace Tests\Feature\Web;

use App\Models\Card;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin roster desks: students (single + CSV import), classes
 * (including teacher assignment) and staff accounts. Every route is
 * admin-only; a teacher or station user gets 403 on all of them.
 */
class AdminRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Override explícito de pruebas: la creación es determinista y el
        // valor compartido sigue estando prohibido como clave nueva.
        config(['ecostation.student_initial_password' => 'password']);
        $this->seedDemo();
    }

    private function classId(string $name): int
    {
        return (int) SchoolClass::where('name', $name)->firstOrFail()->id;
    }

    private function studentId(string $name): int
    {
        return (int) Student::where('name', $name)->firstOrFail()->id;
    }

    #[Test]
    public function creating_a_student_provisions_a_student_account_with_a_forced_rotation(): void
    {
        $classId = $this->classId('5° A');

        $this->actingAs($this->admin())
            ->post('/admin/students', ['name' => 'Nuevo Estudiante', 'class_id' => $classId])
            ->assertRedirect(route('admin.students'))
            ->assertSessionHas('account_email', 'nuevo@ecostation.test')
            ->assertSessionHas('account_password', 'password');

        $student = Student::where('name', 'Nuevo Estudiante')->firstOrFail();
        $this->assertSame($classId, (int) $student->class_id);

        $account = $student->account;
        $this->assertNotNull($account);
        $this->assertSame('student', $account->role->value);
        $this->assertTrue($account->must_change_password);
        $this->assertSame('nuevo@ecostation.test', $account->email);
    }

    #[Test]
    public function the_csv_import_creates_missing_classes_and_skips_duplicates(): void
    {
        $csv = <<<'CSV'
nombre,curso
Rosa Gutiérrez,5° C
Jorge Ramírez,5° C
Maria González,5° A
CSV;

        $this->actingAs($this->admin())
            ->post('/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('alumnos.csv', $csv),
            ])
            ->assertRedirect(route('admin.students'))
            ->assertSessionHas('creados', 2)
            ->assertSessionHas('omitidos', 1);

        $this->assertNotNull(SchoolClass::where('name', '5° C')->first());
        $this->assertSame(2, Student::where('class_id', $this->classId('5° C'))->count());
        $this->assertSame(1, Student::where('name', 'Maria González')->count());

        $rosa = Student::where('name', 'Rosa Gutiérrez')->firstOrFail();
        $this->assertNotNull($rosa->account);
        $this->assertSame('rosa@ecostation.test', $rosa->account->email);
    }

    #[Test]
    public function creating_a_class_is_unique_case_insensitively(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/classes', ['name' => '6° A'])
            ->assertRedirect(route('admin.classes'));

        $this->assertNotNull(SchoolClass::where('name', '6° A')->first());

        $this->actingAs($this->admin())
            ->post('/admin/classes', ['name' => '6° a'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, SchoolClass::whereRaw('lower(name) = lower(?)', ['6° A'])->count());
    }

    #[Test]
    public function assigning_teachers_extends_the_teachers_scope(): void
    {
        $classB = $this->classId('5° B');

        $this->actingAs($this->admin())
            ->put("/admin/classes/{$classB}/teachers", ['teacher_ids' => [$this->teacher()->id]])
            ->assertRedirect(route('admin.classes'));

        $scope = StudentScope::forUser($this->teacher()->fresh());

        $this->assertTrue($scope->allowsStudent(Student::where('name', 'Diego López')->firstOrFail()));
        $this->assertContains($classB, $scope->classIdFilter());
    }

    #[Test]
    public function assigning_a_student_account_as_a_teacher_is_422(): void
    {
        $classB = $this->classId('5° B');
        $studentAccount = $this->cardOf('Maria González')->student->account;

        $this->actingAs($this->admin())
            ->put("/admin/classes/{$classB}/teachers", ['teacher_ids' => [$studentAccount->id]])
            ->assertStatus(422);

        $this->assertSame(0, SchoolClass::findOrFail($classB)->teachers()->count());
    }

    #[Test]
    public function a_station_staff_account_skips_the_forced_rotation(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/staff', [
                'name' => 'Estación Dos',
                'email' => 'estacion2@ecostation.test',
                'role' => 'station',
                'password' => 'secret123',
            ])
            ->assertRedirect(route('admin.staff'));

        $station = User::where('email', 'estacion2@ecostation.test')->firstOrFail();
        $this->assertSame('station', $station->role->value);
        $this->assertFalse($station->must_change_password);
    }

    #[Test]
    public function admin_and_teacher_staff_accounts_force_a_rotation(): void
    {
        foreach ([['Nuevo Admin', 'nuevo.admin@ecostation.test', 'admin'], ['Nuevo Docente', 'nuevo.docente@ecostation.test', 'teacher']] as [$name, $email, $role]) {
            $this->actingAs($this->admin())->post('/admin/staff', [
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'password' => 'secret123',
            ])->assertRedirect(route('admin.staff'));

            $this->assertTrue(User::where('email', $email)->firstOrFail()->must_change_password);
        }
    }

    #[Test]
    public function deleting_a_student_cascades_its_cards_events_and_ledger(): void
    {
        $maria = $this->cardOf('Maria González');
        $studentId = (int) $maria->student_id;
        $cardId = (int) $maria->id;
        $event = $this->recyclingTapFor('Maria González');

        PointsLedger::create([
            'student_id' => $studentId,
            'delta' => 10,
            'reason' => 'recycling_deposit',
            'event_id' => $event->id,
        ]);

        $this->actingAs($this->admin())
            ->delete("/admin/students/{$studentId}")
            ->assertRedirect(route('admin.students'));

        $this->assertNull(Student::find($studentId));
        $this->assertNull(Card::find($cardId));
        $this->assertSame(0, PresenceEvent::where('id', $event->id)->count());
        $this->assertSame(0, PointsLedger::where('student_id', $studentId)->count());
        // The account row survives with student_id nulled (history is kept).
        $this->assertNull(User::where('name', 'Maria González')->firstOrFail()->student_id);
    }

    #[Test]
    public function teacher_and_station_users_get_403_on_every_admin_route(): void
    {
        $classId = $this->classId('5° A');
        $studentId = $this->studentId('Maria González');
        $readerId = $this->reader()->id;
        $cardId = $this->cardOf('Maria González')->id;

        $routes = [
            ['get', '/admin/students'],
            ['post', '/admin/students'],
            ['post', '/admin/students/import'],
            ['delete', "/admin/students/{$studentId}"],
            ['get', '/admin/classes'],
            ['post', '/admin/classes'],
            ['put', "/admin/classes/{$classId}/teachers"],
            ['get', '/admin/staff'],
            ['post', '/admin/staff'],
            ['get', '/admin/stations'],
            ['post', '/admin/stations'],
            ['post', "/admin/stations/{$readerId}/rotate-key"],
            ['delete', "/admin/stations/{$readerId}"],
            ['get', '/admin/pairing'],
            ['post', '/admin/pairing/arm'],
            ['get', '/admin/pairing/status'],
            ['post', "/admin/cards/{$cardId}/revoke"],
            ['delete', "/admin/cards/{$cardId}"],
            ['get', '/admin/settings'],
            ['put', '/admin/settings'],
        ];

        foreach ([$this->teacher(), User::where('email', 'estacion@ecostation.test')->firstOrFail()] as $user) {
            foreach ($routes as [$method, $uri]) {
                $this->actingAs($user)->{$method}($uri)->assertForbidden();
            }
        }
    }
}
