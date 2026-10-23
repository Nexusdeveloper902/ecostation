<?php

namespace Tests\Unit;

use App\Models\Student;
use App\Models\User;
use App\Services\StudentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teacher data wall: teachers reach only students in their assigned
 * classes, admins everything, and every other shape fails closed.
 */
class StudentScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function the_teacher_scope_allows_assigned_classes_and_refuses_the_rest(): void
    {
        $scope = StudentScope::forUser($this->teacher());

        $this->assertFalse($scope->isUnrestricted());
        $this->assertTrue($scope->allowsStudent(Student::where('name', 'Maria González')->firstOrFail()));
        $this->assertFalse($scope->allowsStudent(Student::where('name', 'Diego López')->firstOrFail()));
    }

    #[Test]
    public function the_teacher_students_query_is_the_assigned_class_only(): void
    {
        $scope = StudentScope::forUser($this->teacher());

        $names = $scope->students()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(
            ['Maria González', 'Carlos Pérez', 'Ana Martínez'],
            $names,
        );
    }

    #[Test]
    public function the_class_id_filter_is_the_teacher_pivot(): void
    {
        $scope = StudentScope::forUser($this->teacher());

        $this->assertSame([$this->teacher()->classes()->firstOrFail()->id], $scope->classIdFilter());
    }

    #[Test]
    public function the_admin_is_unrestricted(): void
    {
        $scope = StudentScope::forUser($this->admin());

        $this->assertTrue($scope->isUnrestricted());
        $this->assertNull($scope->classIdFilter());
        $this->assertSame(5, $scope->students()->count());
        $this->assertTrue($scope->allowsStudent(Student::where('name', 'Diego López')->firstOrFail()));
    }

    #[Test]
    public function the_station_role_allows_no_one(): void
    {
        $scope = StudentScope::forUser(User::where('role', 'station')->firstOrFail());

        $this->assertFalse($scope->isUnrestricted());
        $this->assertSame([], $scope->classIdFilter());
        $this->assertSame(0, $scope->students()->count());
        $this->assertFalse($scope->allowsStudent(Student::firstOrFail()));
    }

    #[Test]
    public function an_orphaned_student_account_allows_no_one(): void
    {
        $user = User::where('role', 'student')->where('student_id', '!=', null)->firstOrFail();
        $user->update(['student_id' => null]);

        $scope = StudentScope::forUser($user->fresh());

        $this->assertFalse($scope->isUnrestricted());
        $this->assertSame(0, $scope->students()->count());
        $this->assertFalse($scope->allowsStudent(Student::firstOrFail()));
    }

    #[Test]
    public function a_student_account_sees_only_its_own_row(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $scope = StudentScope::forUser($maria->account);

        $this->assertFalse($scope->isUnrestricted());
        $this->assertTrue($scope->allowsStudent($maria));
        $this->assertFalse($scope->allowsStudent(Student::where('name', 'Carlos Pérez')->firstOrFail()));
        $this->assertSame([$maria->id], $scope->students()->pluck('id')->all());
    }
}
