<?php

namespace Tests\Feature\Console;

use App\Enums\PendingCaptureState;
use App\Models\PendingCapture;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Services\PointsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * photos:purge — the retention promise: the FILE goes after N days,
 * the deposit, event and points stay. A rerun is a no-op, and a file
 * that already vanished is never an error.
 */
class PurgePhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        Storage::fake('local');
    }

    #[Test]
    public function old_photos_are_deleted_but_the_deposit_and_points_stay_and_a_rerun_is_a_noop(): void
    {
        settings()->setMany(['photos.retention_days' => 30]);

        $old = $this->depositWithPhotoFor('Maria González', occurredAt: now()->subDays(31), points: 10);
        $new = $this->depositWithPhotoFor('Maria González', occurredAt: now()->subDays(29), points: 10);
        $oldPath = $old->image_path;

        $this->artisan('photos:purge')->expectsOutputToContain('Fotos eliminadas: 1')->assertSuccessful();

        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($new->image_path);
        $this->assertNull($old->refresh()->image_path);
        $this->assertSame('plastic', $old->material_class->value);
        $this->assertSame(20, app(PointsService::class)->balance($old->event->card->student));

        $this->artisan('photos:purge')->expectsOutputToContain('Fotos eliminadas: 0')->assertSuccessful();
    }

    #[Test]
    public function a_purged_photo_route_answers_404_afterwards(): void
    {
        settings()->setMany(['photos.retention_days' => 30]);
        $old = $this->depositWithPhotoFor('Maria González', occurredAt: now()->subDays(31));

        $this->artisan('photos:purge')->assertSuccessful();

        $this->actingAs($this->admin())->get("/deposits/{$old->id}/photo")->assertNotFound();
    }

    #[Test]
    public function terminal_and_stale_open_pending_captures_lose_their_file_and_row_while_young_ones_are_kept(): void
    {
        settings()->setMany(['photos.retention_days' => 30]);

        $oldTerminal = $this->pendingCapture(PendingCaptureState::Expired, now()->subDays(31));
        $youngTerminal = $this->pendingCapture(PendingCaptureState::Rejected, now()->subDays(29));
        $oldOpen = $this->pendingCapture(PendingCaptureState::AwaitingCard, now()->subDays(31));
        $oldValidating = $this->pendingCapture(PendingCaptureState::Validating, now()->subDays(31));

        $this->artisan('photos:purge')->expectsOutputToContain('Fotos eliminadas: 3')->assertSuccessful();

        Storage::disk('local')->assertMissing($oldTerminal->image_path);
        Storage::disk('local')->assertExists($youngTerminal->image_path);
        Storage::disk('local')->assertMissing($oldOpen->image_path);
        Storage::disk('local')->assertMissing($oldValidating->image_path);

        $this->assertModelMissing($oldTerminal);
        $this->assertModelExists($youngTerminal);
        $this->assertModelMissing($oldOpen);
        $this->assertModelMissing($oldValidating);
    }

    #[Test]
    public function a_failed_file_delete_keeps_the_pointer_and_a_rerun_retries(): void
    {
        settings()->setMany(['photos.retention_days' => 30]);
        $old = $this->depositWithPhotoFor('Maria González', occurredAt: now()->subDays(31));
        $path = (string) $old->image_path;

        // The disk reports the file but refuses the delete.
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->with($path)->andReturn(true);
        $disk->shouldReceive('delete')->with($path)->andReturn(false);
        $disk->shouldReceive('allFiles')->with('recycling-captures')->andReturn([]);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->artisan('photos:purge')
            ->expectsOutputToContain('Fotos no eliminadas: 1')
            ->assertExitCode(1);

        // Pointer intact so tomorrow's run (or a fixed disk) retries.
        $this->assertSame($path, $old->refresh()->image_path);
    }

    #[Test]
    public function an_unreferenced_file_older_than_the_cutoff_is_removed_but_young_orphans_remain(): void
    {
        settings()->setMany(['photos.retention_days' => 30]);

        $disk = Storage::disk('local');
        $oldOrphan = 'recycling-captures/orphan-viejo.jpg';
        $youngOrphan = 'recycling-captures/orphan-joven.jpg';
        $disk->put($oldOrphan, 'x');
        $disk->put($youngOrphan, 'x');
        touch($disk->path($oldOrphan), now()->subDays(31)->timestamp);
        touch($disk->path($youngOrphan), now()->subDays(2)->timestamp);

        $this->artisan('photos:purge')->expectsOutputToContain('Fotos eliminadas: 1')->assertSuccessful();

        $disk->assertMissing($oldOrphan);
        $disk->assertExists($youngOrphan);
    }

    #[Test]
    public function a_missing_file_is_a_no_op_and_the_row_is_still_cleaned(): void
    {
        settings()->setMany(['photos.retention_days' => 30]);
        $old = $this->depositWithPhotoFor('Maria González', occurredAt: now()->subDays(31));
        Storage::disk('local')->delete($old->image_path);

        $this->artisan('photos:purge')->expectsOutputToContain('Fotos eliminadas: 0')->assertSuccessful();

        $this->assertNull($old->refresh()->image_path);
    }

    #[Test]
    public function the_purge_is_scheduled_daily(): void
    {
        $this->assertTrue(collect(app(Schedule::class)->events())
            ->contains(fn ($e) => str_contains((string) $e->command, 'photos:purge')));
    }

    // ---------------------------------------------------------------- helpers

    private function depositWithPhotoFor(string $name, \DateTimeInterface $occurredAt, int $points = 10): RecyclingDeposit
    {
        $student = Student::where('name', $name)->firstOrFail();

        $event = PresenceEvent::create([
            'card_id' => $student->cards()->firstOrFail()->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $student->class_id,
            'occurred_at' => $occurredAt,
        ]);

        $path = UploadedFile::fake()->image('b.jpg')->store('recycling-captures', 'local');

        if ($points > 0) {
            PointsLedger::create([
                'student_id' => $student->id,
                'delta' => $points,
                'reason' => 'recycling_deposit',
                'event_id' => $event->id,
            ]);
        }

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

    private function pendingCapture(PendingCaptureState $state, \DateTimeInterface $createdAt): PendingCapture
    {
        $path = UploadedFile::fake()->image('pending.jpg')->store('recycling-captures', 'local');

        $capture = PendingCapture::create([
            'reader_id' => $this->reader()->id,
            'image_path' => $path,
            'state' => $state->value,
            'expires_at' => now()->addMinutes(5),
        ]);
        $capture->forceFill(['created_at' => $createdAt])->save();

        return $capture->refresh();
    }
}
