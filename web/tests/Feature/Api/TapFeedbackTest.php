<?php

namespace Tests\Feature\Api;

use App\Models\PresenceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Taps that are answered but write no events row leave a `tap_feedback`
 * cue for the realtime speaker bridge; taps that write a row do not
 * (their `tap` frame already carries the cue — no double beep).
 */
class TapFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        PresenceEvent::query()->delete();
    }

    private function tap(string $uid)
    {
        return $this->postJson('/api/v1/events/tap', ['credential_uid' => $uid], [
            'Authorization' => 'Bearer '.$this->readerToken(),
        ]);
    }

    #[Test]
    public function unknown_and_inactive_cards_leave_a_rejected_cue(): void
    {
        $this->tap('NOPE123')->assertNotFound();

        $card = $this->cardOf('Ana Martínez');
        $card->update(['status' => 'revoked']);
        $this->tap($card->credential_uid)->assertStatus(404);

        $this->assertDatabaseHas('tap_feedback', ['cue' => 'rejected', 'reason' => 'not_found', 'event_id' => null]);
        $this->assertDatabaseHas('tap_feedback', ['cue' => 'rejected', 'reason' => 'inactive']);
        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function a_broken_feedback_log_never_fails_the_devices_tap(): void
    {
        $uid = $this->cardUidFor('Maria González');
        $this->tap($uid)->assertOk();

        Schema::drop('tap_feedback');

        $this->tap($uid)->assertOk();
        $this->tap('NOPE123')->assertNotFound();
    }

    #[Test]
    public function accepted_taps_write_rows_and_never_log_a_cue(): void
    {
        $uid = $this->cardUidFor('Maria González');

        $this->tap($uid)->assertOk();
        $this->tap($uid)->assertOk();

        // No same-day duplicate rule: every tap is a separate deposit.
        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('tap_feedback', 0);
    }
}
