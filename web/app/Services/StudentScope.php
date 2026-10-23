<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The teacher data wall.
 *
 * Teachers may only reach students enrolled in the classes they teach;
 * admins see the whole school. Every teacher-reachable surface that
 * resolves a student applies this scope instead of trusting the URL or
 * the route-model binding.
 *
 * Student accounts scope to exactly their own student row. The station
 * role, and any shape that is neither admin/teacher/student, gets an
 * empty allowlist — it fails closed, never open.
 *
 * Deliberate exception: the recycling leaderboard — it is a public
 * competition board by design.
 */
class StudentScope
{
    /**
     * @param  Collection<int, int>|null  $classIds  null = unrestricted (admin)
     * @param  int|null  $studentId  set = student-account scope (own row only)
     */
    public function __construct(
        private readonly ?Collection $classIds,
        private readonly ?int $studentId = null,
    ) {}

    public static function forUser(User $user): self
    {
        if ($user->isAdmin()) {
            return new self(null);
        }

        if ($user->isStudent()) {
            // FAIL CLOSED: users.student_id is nullable by design (deleting
            // a student leaves the account row, nullOnDelete). A null here
            // must never degrade to the unrestricted admin shape; an empty
            // allowlist serves nothing instead.
            if ($user->student_id === null) {
                return new self(collect(), null);
            }

            return new self(null, (int) $user->student_id);
        }

        if ($user->isTeacher()) {
            return new self($user->classes()->pluck('classes.id')->map(fn ($id) => (int) $id));
        }

        // station and any other shape: fail closed.
        return new self(collect());
    }

    public function isUnrestricted(): bool
    {
        return $this->classIds === null && $this->studentId === null;
    }

    /** @return Collection<int, int>|null */
    public function classIds(): ?Collection
    {
        return $this->classIds;
    }

    /** Class-id filter for raw query builders (null = no filter / unrestricted). */
    public function classIdFilter(): ?array
    {
        return $this->classIds?->values()->all();
    }

    public function studentId(): ?int
    {
        return $this->studentId;
    }

    public function allowsStudent(Student $student): bool
    {
        return $this->allows((int) $student->id, $student->class_id !== null ? (int) $student->class_id : null);
    }

    public function allows(int $studentId, ?int $classId): bool
    {
        if ($this->studentId !== null) {
            return $studentId === $this->studentId;
        }

        if ($this->classIds === null) {
            return true; // admin / unrestricted
        }

        return $classId !== null && $this->classIds->contains($classId);
    }

    /** Base query for student lists inside the scope. */
    public function students(): Builder
    {
        if ($this->studentId !== null) {
            return Student::query()->whereKey($this->studentId);
        }

        if ($this->classIds !== null) {
            return Student::query()->whereIn('class_id', $this->classIds->values()->all());
        }

        return Student::query();
    }
}
