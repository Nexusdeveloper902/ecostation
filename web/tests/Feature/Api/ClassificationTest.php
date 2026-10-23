<?php

namespace Tests\Feature\Api;

use App\Contracts\MaterialClassifier;
use App\Models\PointsLedger;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\RecyclingUpdate;
use App\Services\DeviceAuth\DeviceRequestSigner;
use App\Services\PointsService;
use App\Services\Recycling\ClassificationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /api/v1/recycling/classify — multipart event_id + image, signed
 * with Pulse-HMAC. One tap = one deposit + one ledger row; a retry is a
 * duplicate with no second award and no second speaker cue.
 */
class ClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function classification_awards_points_per_the_configured_table(): void
    {
        $this->fakeClassifier(['material_class' => 'plastic', 'confidence' => 0.87]);

        $event = $this->recyclingTapFor('Maria González');
        $student = $this->cardOf('Maria González')->student;

        $response = $this->postSigned('/api/v1/recycling/classify', $event->id);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'already_classified' => false,
                'material_class' => 'plastic',
                'confidence' => 0.87,
                'points_awarded' => 10,
                'new_balance' => 10,
            ]);

        $this->assertDatabaseHas('points_ledger', [
            'student_id' => $student->id,
            'delta' => 10,
            'reason' => 'recycling_deposit',
            'event_id' => $event->id,
        ]);
    }

    #[Test]
    public function re_submitting_the_same_event_does_not_double_award_points(): void
    {
        $this->fakeClassifier(['material_class' => 'metal', 'confidence' => 0.9]);

        $event = $this->recyclingTapFor('Carlos Pérez');

        $this->postSigned('/api/v1/recycling/classify', $event->id, UploadedFile::fake()->image('can.jpg'))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'already_classified' => false, 'points_awarded' => 15]);

        $this->postSigned('/api/v1/recycling/classify', $event->id, UploadedFile::fake()->image('can-retry.jpg'))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'already_classified' => true, 'points_awarded' => 15]);

        $this->assertSame(1, RecyclingDeposit::where('event_id', $event->id)->count());
        $this->assertSame(1, PointsLedger::where('event_id', $event->id)->count());
        $this->assertSame(15, (int) PointsLedger::where('event_id', $event->id)->sum('delta'));
    }

    #[Test]
    public function a_reader_cannot_classify_another_readers_event(): void
    {
        $event = $this->recyclingTapFor('Maria González');

        $other = Reader::create(['label' => 'Otra estación', 'api_key' => Str::random(32)]);

        $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
            'image' => UploadedFile::fake()->image('evil.jpg'),
        ], ['Authorization' => 'Bearer '.$other->api_key])
            ->assertForbidden()
            ->assertJson(['status' => 'error']);

        $this->assertDatabaseCount('recycling_deposits', 0);
    }

    #[Test]
    public function missing_image_is_a_validation_error(): void
    {
        $event = $this->recyclingTapFor('Maria González');

        $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
        ], ['Authorization' => 'Bearer '.$this->readerToken()])
            ->assertUnprocessable();
    }

    #[Test]
    public function a_classifier_failure_returns_503_and_awards_nothing(): void
    {
        $this->swap(MaterialClassifier::class, new class implements MaterialClassifier
        {
            public function classify(string $imagePath): array
            {
                throw ClassificationException::driverUnavailable('stub', 'connection refused');
            }
        });

        $event = $this->recyclingTapFor('Maria González');

        $this->postSigned('/api/v1/recycling/classify', $event->id)
            ->assertStatus(503)
            ->assertJson(['status' => 'error']);

        $this->assertDatabaseCount('recycling_deposits', 0);
        $this->assertDatabaseCount('points_ledger', 0);
    }

    #[Test]
    public function an_invalid_bearer_token_is_rejected(): void
    {
        $event = $this->recyclingTapFor('Maria González');

        $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
            'image' => UploadedFile::fake()->image('x.jpg'),
        ], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    #[Test]
    public function the_classified_image_is_persisted_and_referenced_on_the_deposit(): void
    {
        Storage::fake('local');

        $this->fakeClassifier(['material_class' => 'glass', 'confidence' => 0.8, 'is_bottle' => false, 'is_recyclable' => true]);

        $event = $this->recyclingTapFor('Maria González');

        $this->postSigned('/api/v1/recycling/classify', $event->id, UploadedFile::fake()->image('jar.jpg'))
            ->assertOk()
            ->assertJson([
                'material_class' => 'glass',
                'is_bottle' => false,
                'is_recyclable' => true,
            ]);

        $deposit = RecyclingDeposit::where('event_id', $event->id)->firstOrFail();
        $this->assertNotNull($deposit->image_path);
        $this->assertStringStartsWith('recycling-captures/', $deposit->image_path);
        Storage::disk('local')->assertExists($deposit->image_path);

        $this->assertFalse((bool) $deposit->is_bottle);
        $this->assertTrue((bool) $deposit->is_recyclable);
    }

    #[Test]
    public function a_driver_failure_cleans_up_the_stored_image(): void
    {
        Storage::fake('local');

        $this->swap(MaterialClassifier::class, new class implements MaterialClassifier
        {
            public function classify(string $imagePath): array
            {
                throw ClassificationException::driverUnavailable('deepseek', 'http 500');
            }
        });

        $event = $this->recyclingTapFor('Maria González');

        $this->postSigned('/api/v1/recycling/classify', $event->id, UploadedFile::fake()->image('orphan.jpg'))
            ->assertStatus(503);

        $this->assertSame(0, RecyclingDeposit::count());
        $this->assertSame([], Storage::disk('local')->allFiles('recycling-captures'), 'no orphan image must accumulate on a driver failure');
    }

    #[Test]
    public function a_stale_tap_event_is_rejected_by_the_classify_window(): void
    {
        config(['ecostation.capture.classify_window_seconds' => 600]);

        $event = $this->recyclingTapFor('Maria González');

        $this->travel(601)->seconds();

        $this->postSigned('/api/v1/recycling/classify', $event->id, UploadedFile::fake()->image('late.jpg'))
            ->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => __('api.event_expired'),
            ]);

        $this->assertDatabaseCount('recycling_deposits', 0);
        $this->assertDatabaseCount('points_ledger', 0);
    }

    #[Test]
    public function awarding_records_the_recycling_realtime_frames(): void
    {
        $this->fakeClassifier(['material_class' => 'plastic', 'confidence' => 0.9, 'is_bottle' => true, 'is_recyclable' => true]);

        $event = $this->recyclingTapFor('Maria González');
        $student = $this->cardOf('Maria González')->student;

        $this->postSigned('/api/v1/recycling/classify', $event->id)->assertOk();

        $types = RecyclingUpdate::orderBy('id')->pluck('type')->all();

        $this->assertContains('validated', $types);
        $this->assertContains('points_awarded', $types);
        $this->assertContains('leaderboard_updated', $types);

        $validated = RecyclingUpdate::where('type', 'validated')->firstOrFail()->payload;
        $this->assertTrue($validated['is_recyclable']);
        $this->assertSame($student->id, $validated['student_id']);
        $this->assertSame($student->class_id, $validated['class_id']);
        $this->assertArrayNotHasKey('cue', $validated);

        $pointsFrame = RecyclingUpdate::where('type', 'points_awarded')->firstOrFail();
        $this->assertSame(10, $pointsFrame->payload['points']);
        $this->assertSame(10, $pointsFrame->payload['new_balance']);
        $this->assertSame('Maria González', $pointsFrame->payload['student_name']);
        $this->assertIsInt($pointsFrame->payload['points']);
        $this->assertSame($student->id, $pointsFrame->payload['student_id']);
        $this->assertSame($student->class_id, $pointsFrame->payload['class_id']);
    }

    #[Test]
    public function an_other_result_awards_nothing_but_still_emits_points_awarded_with_zero(): void
    {
        $this->seedDemo();
        $event = $this->recyclingTapFor('Maria González');
        $this->fakeClassifier(['material_class' => 'other', 'confidence' => 0.9, 'is_bottle' => false, 'is_recyclable' => false]);
        $this->postSigned('/api/v1/recycling/classify', $event->id)->assertOk()->assertJsonPath('points_awarded', 0);
        $this->assertSame(0, app(PointsService::class)->balance($event->card->student));
        $this->assertSame(0, PointsLedger::where('event_id', $event->id)->count());
        $frames = RecyclingUpdate::where('type', 'points_awarded')->get();
        $this->assertCount(1, $frames);
        $this->assertSame(0, $frames[0]->payload['points']);
    }

    #[Test]
    public function every_new_classification_emits_exactly_one_points_awarded_and_a_retry_emits_none(): void
    {
        $this->seedDemo();
        $event = $this->recyclingTapFor('Maria González');
        $this->fakeClassifier(['material_class' => 'plastic', 'confidence' => 0.9, 'is_bottle' => true, 'is_recyclable' => true]);
        $this->postSigned('/api/v1/recycling/classify', $event->id)->assertOk();
        $this->postSigned('/api/v1/recycling/classify', $event->id)->assertOk()->assertJsonPath('already_classified', true);
        $frames = RecyclingUpdate::where('type', 'points_awarded')->get();
        $this->assertCount(1, $frames);
        $this->assertSame(10, $frames[0]->payload['points']);
    }

    // ------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $result */
    private function fakeClassifier(array $result): void
    {
        $this->swap(MaterialClassifier::class, new class($result) implements MaterialClassifier
        {
            /** @param  array<string, mixed>  $result */
            public function __construct(private array $result) {}

            public function classify(string $imagePath): array
            {
                return $this->result;
            }
        });
    }

    private function postSigned(string $path, int $eventId, ?UploadedFile $file = null): TestResponse
    {
        $file ??= UploadedFile::fake()->image('bottle.jpg');
        $hash = (string) hash_file('sha256', $file->getPathname());
        $canonical = DeviceRequestSigner::multipartCanonical((string) $eventId, $hash);
        $nonce = Str::random(32);

        return $this->call(
            'POST',
            $path,
            ['event_id' => $eventId],
            [],
            ['image' => $file],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => DeviceRequestSigner::authorizationHeader($this->reader(), 'POST', $path, $canonical, $nonce),
            ]
        );
    }
}
