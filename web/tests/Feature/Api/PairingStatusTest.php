<?php

namespace Tests\Feature\Api;

use App\Models\PendingPairing;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The read-only pairing status endpoint backing the admin pairing desk.
 * Covers the empty state, the armed state (pending + countdown), the
 * completed state and the auth surface.
 */
class PairingStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function getStatus(): TestResponse
    {
        return $this->getJson('/admin/pairing/status');
    }

    #[Test]
    public function guests_are_rejected(): void
    {
        $this->getStatus()->assertUnauthorized();
    }

    #[Test]
    public function teachers_are_forbidden(): void
    {
        $this->actingAs($this->teacher())->getStatus()->assertForbidden();
    }

    #[Test]
    public function an_admin_sees_the_empty_state_on_a_fresh_seed(): void
    {
        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('pending', null)
            ->assertJsonPath('last_pairing', null)
            ->assertJsonPath('recent_pairings', []);
    }

    #[Test]
    public function an_armed_session_reports_the_student_and_the_countdown(): void
    {
        $window = settings()->pairingWindowSeconds();
        $student = Student::orderBy('id')->firstOrFail();
        $pairing = $this->pairings()->arm($student);

        $response = $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending.student_id', $student->id)
            ->assertJsonPath('pending.student_name', $student->name)
            ->assertJsonStructure([
                'status',
                'pending' => ['student_id', 'student_name', 'expires_at', 'seconds_left', 'last_rejection'],
                'last_pairing',
                'recent_pairings',
            ]);

        $this->assertNull($response->json('pending.last_rejection'));

        $seconds = $response->json('pending.seconds_left');
        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual($window, $seconds);

        $this->assertSame(
            $pairing->id,
            PendingPairing::where('student_id', $student->id)->latest('id')->firstOrFail()->id,
        );
    }

    #[Test]
    public function an_expired_session_is_not_pending(): void
    {
        $student = Student::orderBy('id')->firstOrFail();
        $this->pairings()->arm($student);

        PendingPairing::latest('id')->firstOrFail()->update(['expires_at' => now()->subSecond()]);

        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending', null);
    }

    #[Test]
    public function arming_supersedes_any_previous_active_window(): void
    {
        $first = Student::orderBy('id')->firstOrFail();
        $second = Student::orderBy('id')->skip(1)->firstOrFail();
        $pairings = $this->pairings();

        $older = $pairings->arm($first);
        $newer = $pairings->arm($second);

        $this->assertTrue($newer->fresh()->isActive());
        $this->assertFalse($older->fresh()->isActive());

        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending.student_id', $second->id);

        $this->pairings()->pair($this->reader(), 'SUPERSEDED001');

        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending', null)
            ->assertJsonPath('last_pairing.card_uid', 'SUPERSEDED001')
            ->assertJsonPath('last_pairing.student_name', $second->name);
    }

    #[Test]
    public function a_window_written_by_a_clock_the_machine_no_longer_has_is_not_pending(): void
    {
        $student = Student::orderBy('id')->firstOrFail();
        $this->pairings()->arm($student);

        PendingPairing::latest('id')->firstOrFail()->forceFill([
            'created_at' => now()->addSeconds(12600),
            'updated_at' => now(),
            'expires_at' => now()->addSeconds(12645),
        ])->save();

        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending', null);

        $result = $this->pairings()->pair($this->reader(), 'CLOCKJUMP001');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_session', $result['reason']);
    }

    #[Test]
    public function a_completed_pairing_is_reported_with_its_card_and_reader(): void
    {
        $student = $this->studentWithoutCard();
        $reader = $this->reader();

        $this->pairings()->arm($student);
        $result = $this->pairings()->pair($reader, 'DESK00062041607');

        $this->assertTrue($result['ok']);

        $this->assertDatabaseHas('pending_pairings', [
            'id' => $result['pairing']->id,
            'card_id' => $result['card']->id,
            'reader_id' => $reader->id,
        ]);

        $response = $this->actingAs($this->admin())->getStatus()->assertOk();

        $response->assertJsonPath('pending', null)
            ->assertJsonPath('last_pairing.card_uid', 'DESK00062041607')
            ->assertJsonPath('last_pairing.student_id', $student->id)
            ->assertJsonPath('last_pairing.student_name', $student->name)
            ->assertJsonPath('last_pairing.reader_label', $reader->label)
            ->assertJsonCount(1, 'recent_pairings')
            ->assertJsonPath('recent_pairings.0.card_uid', 'DESK00062041607')
            ->assertJsonPath('recent_pairings.0.student_id', $student->id);
    }

    #[Test]
    public function a_rejected_tap_is_stamped_on_the_armed_window_and_reported(): void
    {
        $reader = $this->reader();
        $target = $this->studentWithoutCard();

        $this->pairings()->arm($target);
        $result = $this->pairings()->pair($reader, $this->cardUidFor('Maria González'));

        $this->assertFalse($result['ok']);
        $this->assertSame('already_paired', $result['reason']);

        $pairing = PendingPairing::latest('id')->firstOrFail();
        $this->assertNotNull($pairing->last_rejected_at);
        $this->assertSame($this->cardUidFor('Maria González'), $pairing->last_rejected_uid);
        $this->assertSame('already_paired', $pairing->last_rejected_reason);
        $this->assertTrue($pairing->isActive());

        $response = $this->actingAs($this->admin())->getStatus()->assertOk();

        $response->assertJsonPath('pending.last_rejection.card_uid', $this->cardUidFor('Maria González'))
            ->assertJsonPath('pending.last_rejection.reason', 'already_paired');

        $this->assertNotNull($response->json('pending.last_rejection.at'));
    }

    #[Test]
    public function a_rejected_tap_leaves_the_window_pairable_and_the_stamp_latest_wins(): void
    {
        $reader = $this->reader();
        $target = $this->studentWithoutCard();

        $this->pairings()->arm($target);

        $this->pairings()->pair($reader, $this->cardUidFor('Maria González'));
        $this->pairings()->pair($reader, $this->cardUidFor('Ana Martínez'));

        $pairing = PendingPairing::latest('id')->firstOrFail();
        $this->assertSame($this->cardUidFor('Ana Martínez'), $pairing->last_rejected_uid);

        $result = $this->pairings()->pair($reader, 'FRESH-AFTER-REJECTION');
        $this->assertTrue($result['ok']);
        $this->assertSame($target->id, $result['card']->student_id);
    }

    #[Test]
    public function a_no_session_tap_has_no_row_to_stamp_and_no_pending_in_the_feed(): void
    {
        $result = $this->pairings()->pair($this->reader(), 'ORPHAN123');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_session', $result['reason']);

        $this->actingAs($this->admin())->getStatus()
            ->assertOk()
            ->assertJsonPath('pending', null);
    }

    #[Test]
    public function the_history_lists_the_most_recent_completions_first(): void
    {
        $reader = $this->reader();

        foreach (['AAA111', 'BBB222', 'CCC333'] as $i => $uid) {
            $student = $this->studentWithoutCard($i);
            $this->pairings()->arm($student);
            $this->pairings()->pair($reader, $uid);
        }

        $this->actingAs($this->admin())->getStatus()
            ->assertOk()
            ->assertJsonCount(3, 'recent_pairings')
            ->assertJsonPath('recent_pairings.0.card_uid', 'CCC333')
            ->assertJsonPath('recent_pairings.2.card_uid', 'AAA111');
    }

    private function pairings(): PairingService
    {
        return $this->app->make(PairingService::class);
    }

    private function studentWithoutCard(int $n = 0): Student
    {
        return Student::create([
            'name' => "Estudiante Escritorio {$n} ".uniqid(),
            'class_id' => SchoolClass::where('name', '5° A')->value('id'),
        ]);
    }
}
