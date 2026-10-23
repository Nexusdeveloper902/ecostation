<?php

namespace Tests\Feature\Api;

use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TapEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        PresenceEvent::query()->delete();
    }

    private function tap(array $body)
    {
        return $this->postJson('/api/v1/events/tap', $body, [
            'Authorization' => 'Bearer '.$this->readerToken(),
        ]);
    }

    #[Test]
    public function a_valid_tap_creates_an_event_and_returns_device_feedback(): void
    {
        $response = $this->tap(['credential_uid' => $this->cardUidFor('Maria González')]);

        $response->assertOk()
            ->assertJsonStructure(['status', 'event_id', 'event_type', 'student_first_name', 'duplicate', 'next_step'])
            ->assertJson([
                'status' => 'ok',
                'event_type' => 'RECYCLING_DEPOSIT',
                'student_first_name' => 'Maria',
                'duplicate' => false,
                'next_step' => 'awaiting_classification',
            ]);

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseHas('events', [
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $this->cardOf('Maria González')->student->class_id,
        ]);
    }

    #[Test]
    public function an_unknown_card_returns_a_clean_404(): void
    {
        $this->tap(['credential_uid' => 'NOPE123'])
            ->assertNotFound()
            ->assertJson([
                'status' => 'error',
                'message' => 'Tarjeta no reconocida',
            ]);

        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function an_inactive_card_is_rejected(): void
    {
        $card = $this->cardOf('Ana Martínez');
        $card->update(['status' => 'revoked']);

        $this->tap(['credential_uid' => $card->credential_uid])
            ->assertNotFound()
            ->assertJson([
                'status' => 'error',
                'message' => 'La tarjeta no está activa',
            ]);

        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function a_missing_bearer_token_returns_401(): void
    {
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'])
            ->assertUnauthorized()
            ->assertJson(['status' => 'error']);
    }

    #[Test]
    public function an_invalid_bearer_token_returns_401(): void
    {
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'], [
            'Authorization' => 'Bearer not-a-real-key',
        ])->assertUnauthorized();
    }

    #[Test]
    public function a_tap_signals_awaiting_classification(): void
    {
        $response = $this->tap(['credential_uid' => $this->cardUidFor('Diego López')]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'event_type' => 'RECYCLING_DEPOSIT',
                'next_step' => 'awaiting_classification',
                'duplicate' => false,
            ])
            ->assertJsonPath('event_id', fn ($id) => is_int($id));

        // No points were awarded at tap time — classification comes first.
        $this->assertDatabaseCount('points_ledger', 0);
    }

    #[Test]
    public function a_client_timestamp_is_honored_when_present(): void
    {
        $clientTime = now()->subMinutes(30)->toIso8601String();

        $this->tap([
            'credential_uid' => $this->cardUidFor('Maria González'),
            'client_timestamp' => $clientTime,
        ])->assertOk();

        $event = PresenceEvent::first();
        $this->assertEqualsWithDelta(
            now()->subMinutes(30)->getTimestamp(),
            $event->occurred_at->getTimestamp(),
            5,
            'occurred_at should use the client timestamp (within tolerance).'
        );
        $this->assertSame($clientTime, $event->metadata['client_timestamp']);
    }

    #[Test]
    public function a_utc_client_timestamp_is_stored_and_returned_in_bogota_time(): void
    {
        $this->tap([
            'credential_uid' => $this->cardUidFor('Maria González'),
            'client_timestamp' => '2026-10-23T12:00:00Z',
        ])->assertOk();

        // Stored with the app timezone's wall clock (UTC-5), not UTC.
        $this->assertDatabaseHas('events', ['occurred_at' => '2026-10-23 07:00:00']);

        $event = PresenceEvent::firstOrFail();
        $this->assertSame('2026-10-23 07:00:00', $event->occurred_at->format('Y-m-d H:i:s'));

        RecyclingDeposit::create([
            'event_id' => $event->id,
            'material_class' => 'plastic',
            'confidence' => 0.9,
            'points_awarded' => 10,
            'is_bottle' => true,
            'is_recyclable' => true,
        ]);

        $user = User::where('email', 'maria@ecostation.test')->firstOrFail();
        $token = $user->createToken('zona-horaria')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me/history')
            ->assertOk()
            ->assertJsonPath('data.0.occurred_at', '2026-10-23T07:00:00-05:00');
    }

    #[Test]
    public function validation_errors_are_returned_as_422(): void
    {
        $this->tap([])->assertUnprocessable();
    }
}
