<?php

namespace Tests\Feature\Web;

use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deposit photos are staff-only: the private `local` disk has exactly
 * one authorized door, scoped by StudentScope. A null path or a file
 * that vanished is a 404, never a 500. Students never get a byte.
 */
class DepositPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        Storage::fake('local');
    }

    #[Test]
    public function an_admin_streams_the_photo_with_its_stored_mime(): void
    {
        $deposit = $this->depositWithPhotoFor('Maria González');

        $response = $this->actingAs($this->admin())
            ->get("/deposits/{$deposit->id}/photo");

        $response->assertOk();
        $this->assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertNotSame('', $response->streamedContent());
    }

    #[Test]
    public function a_deposit_without_a_photo_is_a_404(): void
    {
        $event = $this->recyclingTapFor('Maria González');
        $deposit = RecyclingDeposit::create([
            'event_id' => $event->id,
            'image_path' => null,
            'material_class' => 'plastic',
            'confidence' => 0.9,
            'points_awarded' => 10,
            'is_bottle' => true,
            'is_recyclable' => true,
        ]);

        $this->actingAs($this->admin())->get("/deposits/{$deposit->id}/photo")->assertNotFound();
    }

    #[Test]
    public function a_photo_whose_file_vanished_is_404_not_500(): void
    {
        $deposit = $this->depositWithPhotoFor('Maria González');
        Storage::disk('local')->delete($deposit->image_path);

        $this->actingAs($this->admin())->get("/deposits/{$deposit->id}/photo")->assertNotFound();
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $deposit = $this->depositWithPhotoFor('Maria González');

        $this->get("/deposits/{$deposit->id}/photo")->assertRedirect('/login');
    }

    #[Test]
    public function an_unknown_deposit_is_a_404(): void
    {
        $this->actingAs($this->admin())->get('/deposits/999999/photo')->assertNotFound();
    }

    #[Test]
    public function a_teacher_sees_photos_of_their_classes_only_and_students_never(): void
    {
        $own = $this->depositWithPhotoFor('Maria González');   // 5° A
        $other = $this->depositWithPhotoFor('Diego López');    // 5° B

        $this->actingAs($this->teacher())->get("/deposits/{$own->id}/photo")->assertOk();
        $this->actingAs($this->teacher())->get("/deposits/{$other->id}/photo")->assertNotFound();

        $student = User::where('role', 'student')->firstOrFail();
        $this->actingAsFresh($student)->get("/deposits/{$own->id}/photo")->assertForbidden();
    }

    #[Test]
    public function a_historical_photo_uses_the_events_class_snapshot(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $classA = (int) $maria->class_id;
        $classB = (int) Student::where('name', 'Diego López')->firstOrFail()->class_id;

        $deposit = $this->depositWithPhotoFor('Maria González');

        // After the event, the student moves to 5° B.
        $maria->update(['class_id' => $classB]);

        // The teacher of the class where the tap happened keeps access.
        $this->actingAs($this->teacher())->get("/deposits/{$deposit->id}/photo")->assertOk();

        // The teacher of the NEW class cannot see a historical 5° A photo.
        $this->actingAsFresh($this->teacherOf($classB, 'docente-b@ecostation.test'))
            ->get("/deposits/{$deposit->id}/photo")
            ->assertNotFound();

        $this->assertNotSame($classA, $classB);
    }

    // ---------------------------------------------------------------- helpers

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

    private function depositWithPhotoFor(string $name, ?string $occurredAt = null, int $points = 10): RecyclingDeposit
    {
        $student = Student::where('name', $name)->firstOrFail();

        $event = PresenceEvent::create([
            'card_id' => $student->cards()->firstOrFail()->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $student->class_id,
            'occurred_at' => $occurredAt !== null ? now()->parse($occurredAt) : now(),
        ]);

        $path = UploadedFile::fake()->image('b.jpg')->store('recycling-captures', 'local');

        return RecyclingDeposit::create([
            'event_id' => $event->id,
            'image_path' => $path,
            'material_class' => 'plastic',
            'confidence' => 0.95,
            'points_awarded' => $points,
            'is_bottle' => true,
            'is_recyclable' => true,
        ]);
    }
}
