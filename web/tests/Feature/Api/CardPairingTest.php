<?php

namespace Tests\Feature\Api;

use App\Models\PendingPairing;
use App\Models\PointsLedger;
use App\Models\Student;
use App\Services\PairingService;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two-step arm-then-pair: arming happens on the dashboard side (the ARM
 * route arrives in Task 19; here it is called through PairingService) and
 * pairing happens on the device side. The short window synchronizes them.
 */
class CardPairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function arm(Student $student): PendingPairing
    {
        return app(PairingService::class)->arm($student);
    }

    private function pair(string $credentialUid, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => $credentialUid,
        ], array_merge(['Authorization' => 'Bearer '.$this->readerToken()], $headers));
    }

    /**
     * A student with no card yet: uses a seeded one if present, otherwise
     * creates one (each test that pairs a card burns a "fresh student").
     */
    private function studentWithoutCard(): Student
    {
        $existing = Student::whereDoesntHave('cards')->first();

        if ($existing !== null) {
            return $existing;
        }

        static $n = 0;
        $n++;

        return Student::create(['name' => "Estudiante Nueva {$n}"]);
    }

    #[Test]
    public function arming_then_pairing_a_fresh_card_links_it_to_the_student(): void
    {
        $student = $this->studentWithoutCard();

        // The window is real (45 s by default) and ISO 8601.
        $arm = $this->arm($student);
        $this->assertEqualsWithDelta(
            now()->addSeconds(45)->getTimestamp(),
            $arm->expires_at->getTimestamp(),
            1,
            'expires_at should be ~45 s in the future'
        );

        $response = $this->pair('NEWCARD1234A');

        $response->assertOk()
            ->assertJsonStructure(['status', 'paired_student_name', 'student_id', 'rekeyed'])
            ->assertJson([
                'status' => 'ok',
                'paired_student_name' => $student->name,
                'student_id' => $student->id,
                'rekeyed' => false,
            ]);

        // The card row links the credential to the student, active by default.
        $this->assertDatabaseHas('cards', [
            'credential_uid' => 'NEWCARD1234A',
            'student_id' => $student->id,
            'status' => 'active',
        ]);

        // The pending pairing is consumed and stamped with the reader.
        $pairing = PendingPairing::where('student_id', $student->id)->first();
        $this->assertNotNull($pairing->consumed_at);
        $this->assertEquals($this->reader()->id, $pairing->reader_id);
    }

    #[Test]
    public function a_paired_card_immediately_works_for_taps(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);
        $this->pair('NEWCARD5678B')->assertOk();

        // The whole point: pairing makes the card usable in operation mode.
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => 'NEWCARD5678B',
        ], ['Authorization' => 'Bearer '.$this->readerToken()])
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'student_first_name' => $student->firstName(),
            ]);
    }

    #[Test]
    public function pairing_with_no_active_session_returns_a_clean_409(): void
    {
        $response = $this->pair('FRESHCARD9999');

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'message' => 'No hay ninguna sesión de emparejamiento activa',
            ]);

        // Nothing was created.
        $this->assertDatabaseMissing('cards', ['credential_uid' => 'FRESHCARD9999']);
    }

    #[Test]
    public function an_already_paired_card_is_rejected_without_reassignment(): void
    {
        $owner = $this->cardOf('Maria González');   // existing active card
        $target = $this->studentWithoutCard();

        $this->arm($target);
        $response = $this->pair($owner->credential_uid);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'La tarjeta ya está emparejada',
            ]);

        // The card is NOT reassigned: still linked to its original student.
        $this->assertDatabaseHas('cards', [
            'credential_uid' => $owner->credential_uid,
            'student_id' => $owner->student_id,
        ]);

        // The pending session stays armed — the operator can scan a
        // DIFFERENT fresh card immediately.
        $pairing = PendingPairing::where('student_id', $target->id)->first();
        $this->assertNull($pairing->consumed_at);

        $this->pair('ANOTHERFRESH1')->assertOk();
        $this->assertDatabaseHas('cards', [
            'credential_uid' => 'ANOTHERFRESH1',
            'student_id' => $target->id,
        ]);
    }

    #[Test]
    public function a_revoked_card_is_reactivated_for_the_same_student_with_a_fresh_key(): void
    {
        // Unpair/revoke keeps the row, so the same credential_uid may be
        // re-linked deliberately for its ORIGINAL student: no new card
        // row, history untouched and still attributed to that student.
        $card = $this->cardOf('Ana Martínez');
        $ana = $card->student;

        $history = $this->recyclingTapFor('Ana Martínez');
        PointsLedger::create([
            'student_id' => $ana->id,
            'delta' => 8,
            'reason' => 'recycling_deposit',
            'event_id' => $history->id,
        ]);

        $card->update(['status' => 'revoked']);

        $this->arm($ana->fresh());

        $this->pair($card->credential_uid)->assertOk()
            ->assertJson([
                'status' => 'ok',
                'student_id' => $ana->id,
                'rekeyed' => false,
            ]);

        $card->refresh();
        $this->assertSame('active', $card->status->value);
        $this->assertSame($ana->id, (int) $card->student_id);
        $this->assertSame($ana->id, (int) $history->refresh()->card->student_id);
        $this->assertSame(8, app(PointsService::class)->balance($ana));

        // An ACTIVE card of someone else is still never reassigned.
        $this->arm($this->studentWithoutCard());
        $this->pair($card->credential_uid)->assertStatus(422)
            ->assertJson(['message' => 'La tarjeta ya está emparejada']);
    }

    #[Test]
    public function a_revoked_card_cannot_be_reactivated_for_a_different_student(): void
    {
        // Reusing the row for a different student would rewrite every
        // historical event of that card. The desk gets `already_paired`
        // and the original attribution (events + balance) survives.
        $card = $this->cardOf('Ana Martínez');
        $ana = $card->student;
        $history = $this->recyclingTapFor('Ana Martínez');
        PointsLedger::create([
            'student_id' => $ana->id,
            'delta' => 8,
            'reason' => 'recycling_deposit',
            'event_id' => $history->id,
        ]);

        $card->update(['status' => 'revoked']);

        $other = $this->studentWithoutCard();
        $this->arm($other);

        $this->pair($card->credential_uid)->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'La tarjeta ya está emparejada',
            ]);

        // Nothing moved: same owner, same event, same balance.
        $card->refresh();
        $this->assertSame('revoked', $card->status->value);
        $this->assertSame($ana->id, (int) $card->student_id);
        $this->assertSame($ana->id, (int) $history->refresh()->card->student_id);
        $this->assertSame(8, app(PointsService::class)->balance($ana));
        $this->assertSame(0, app(PointsService::class)->balance($other));

        // The armed window stays open for a genuinely fresh credential.
        $this->pair('FRESHAFTERREVOKE1')->assertOk();
    }

    #[Test]
    public function an_expired_pending_pairing_is_treated_as_inactive(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);

        PendingPairing::where('student_id', $student->id)
            ->update(['expires_at' => now()->subSeconds(1)]);

        $this->pair('TOOLATECARD1')->assertStatus(409)
            ->assertJson(['message' => 'No hay ninguna sesión de emparejamiento activa']);

        $this->assertDatabaseMissing('cards', ['credential_uid' => 'TOOLATECARD1']);
    }

    #[Test]
    public function a_consumed_pairing_is_one_shot(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);
        $this->pair('ONESHOTCARD1')->assertOk();

        // No session is armed anymore: a second card gets the 409.
        $this->pair('ONESHOTCARD2')->assertStatus(409);
    }

    #[Test]
    public function the_most_recent_armed_pairing_wins(): void
    {
        $first = $this->studentWithoutCard();
        $second = Student::whereDoesntHave('cards')
            ->where('id', '!=', $first->id)->first();

        if ($second === null) {
            $second = Student::create(['name' => 'Estudiante Segunda']);
        }

        $this->arm($first);
        $this->arm($second);

        // Arming the second window closed the first, so a consumed pairing
        // can never be shadowed by a stale one.
        $firstWindow = PendingPairing::where('student_id', $first->id)->latest('id')->first();
        $this->assertFalse($firstWindow->isActive(), 'arming a new window must close the previous one');

        $this->pair('LATESTWINS12')->assertOk()
            ->assertJson([
                'student_id' => $second->id,
                'paired_student_name' => $second->name,
            ]);

        // And the desk-side view of the same instant: the pairing that just
        // happened is what the status feed reports — no zombie.
        $status = app(PairingService::class)->statusPayload();
        $this->assertNull($status['pending']);
        $this->assertSame('LATESTWINS12', $status['last_pairing']['card_uid']);
    }

    #[Test]
    public function pairing_requires_a_valid_reader_bearer_key(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);

        // Missing key → 401
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'ANYCARD00001',
        ])->assertUnauthorized();

        // Invalid key → 401
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'ANYCARD00001',
        ], ['Authorization' => 'Bearer not-a-real-key'])
            ->assertUnauthorized();

        // A dashboard user's PAT is NOT a reader key → 401.
        $pat = $this->admin()->createToken('not-a-reader')->plainTextToken;
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'ANYCARD00001',
        ], ['Authorization' => "Bearer {$pat}"])
            ->assertUnauthorized();

        $this->assertDatabaseMissing('cards', ['credential_uid' => 'ANYCARD00001']);
    }

    #[Test]
    public function arm_and_pair_payloads_are_validated(): void
    {
        // Pairing without a credential_uid → 422 (validation, not 409):
        // validation runs before the controller logic.
        $student = $this->studentWithoutCard();
        $this->arm($student);

        $this->postJson('/api/v1/admin/cards/pair', [], [
            'Authorization' => 'Bearer '.$this->readerToken(),
        ])->assertStatus(422);
    }

    #[Test]
    public function concurrent_pair_taps_cannot_double_consume_one_session(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);

        // Two cards pairing "simultaneously" against one session: the
        // second gets 409 (session consumed) — never two cards linked.
        $this->pair('RACEWINNER01')->assertOk();
        $this->pair('RACELOSER02')->assertStatus(409);

        $this->assertDatabaseHas('cards', ['credential_uid' => 'RACEWINNER01', 'student_id' => $student->id]);
        $this->assertDatabaseMissing('cards', ['credential_uid' => 'RACELOSER02']);
    }
}
