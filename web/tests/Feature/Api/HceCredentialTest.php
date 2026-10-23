<?php

namespace Tests\Feature\Api;

use App\Contracts\MaterialClassifier;
use App\Models\Card;
use App\Models\HceCredentialKey;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Student;
use App\Services\Hce\HceCredentialAuth;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHcePhone;
use Tests\TestCase;

/**
 * The Android phone as a first-class credential — with its OWN key.
 *
 * The reader relays the CHALLENGE transcript and this backend verifies it
 * with that credential's key. Pairing is the one-time, reader-wrapped key
 * hand-off; every later tap must prove possession.
 */
class HceCredentialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        PresenceEvent::query()->delete();
    }

    private function headers(?Reader $reader = null): array
    {
        return ['Authorization' => 'Bearer '.($reader ?? $this->reader())->api_key];
    }

    private function studentWithoutCard(): Student
    {
        return Student::create(['name' => 'Estudiante HCE '.uniqid()]);
    }

    private function arm(Student $student): void
    {
        app(PairingService::class)->arm($student);
    }

    private function pair(FakeHcePhone $phone, ?Reader $reader = null, array $override = []): TestResponse
    {
        $reader ??= $this->reader();

        return $this->postJson('/api/v1/admin/cards/pair', $override + $phone->pair($reader), $this->headers($reader));
    }

    private function tap(array $body, ?Reader $reader = null): TestResponse
    {
        return $this->postJson('/api/v1/events/tap', $body, $this->headers($reader));
    }

    /** Events written for phone credentials. */
    private function phoneEvents(): int
    {
        return PresenceEvent::whereIn('card_id', Card::where('kind', 'hce')->pluck('id'))->count();
    }

    /** Arm + pair a fresh phone to a fresh student. */
    private function enrolledPhone(string $credentialUid): array
    {
        $student = $this->studentWithoutCard();
        $phone = new FakeHcePhone($credentialUid);
        $this->arm($student);
        $this->pair($phone)->assertOk()->assertJson(['rekeyed' => false]);

        return [$phone, $student];
    }

    // ---------------------------------------------------------------
    // Provisioning (pairing = key hand-off)
    // ---------------------------------------------------------------

    #[Test]
    public function pairing_a_phone_provisions_its_own_encrypted_key_and_proven_taps_work(): void
    {
        [$phone, $student] = $this->enrolledPhone('PLS-PAIRTAPWORK1');

        $card = Card::where('credential_uid', 'PLS-PAIRTAPWORK1')->firstOrFail();
        $this->assertSame('hce', $card->kind->value);
        $this->assertSame($student->id, $card->student_id);

        // Stored for THIS card only, encrypted at rest, fingerprinted.
        $row = HceCredentialKey::where('card_id', $card->id)->firstOrFail();
        $this->assertSame(bin2hex($phone->key), $row->secret);
        $this->assertSame(HceCredentialAuth::fingerprint($phone->key), $row->fingerprint);
        $this->assertSame($this->reader()->id, $row->provisioned_by_reader_id);
        $raw = DB::table('hce_credential_keys')->where('id', $row->id)->value('secret');
        $this->assertStringNotContainsString(bin2hex($phone->key), $raw);
        // Never serialized.
        $this->assertArrayNotHasKey('secret', $row->toArray());

        $this->tap($phone->tap())
            ->assertOk()
            ->assertJson(['status' => 'ok', 'student_first_name' => $student->firstName()]);
    }

    #[Test]
    public function no_response_or_status_feed_ever_carries_key_material(): void
    {
        $student = $this->studentWithoutCard();
        $phone = new FakeHcePhone('PLS-NOKEYLEAKS01');
        $this->arm($student);
        $body = $phone->pair($this->reader());

        $pair = $this->postJson('/api/v1/admin/cards/pair', $body, $this->headers());
        $pair->assertOk();

        $card = Card::where('credential_uid', 'PLS-NOKEYLEAKS01')->firstOrFail();

        foreach ([
            $pair->getContent(),
            json_encode(app(PairingService::class)->statusPayload()),
            json_encode($card->toArray()),
        ] as $content) {
            $this->assertStringNotContainsString(bin2hex($phone->key), $content);
            $this->assertStringNotContainsString($body['hce_key_wrapped'], $content);
        }
    }

    #[Test]
    public function pairing_without_a_kind_defaults_to_physical(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);

        // Old firmware sends no kind — byte-for-byte the old contract.
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'PLAINUID12345',
        ], $this->headers())->assertOk();

        $this->assertDatabaseHas('cards', ['credential_uid' => 'PLAINUID12345', 'kind' => 'physical']);
        $this->assertSame(0, HceCredentialKey::count());

        // Physical cards are unaffected: no proof needed, none checked.
        $this->tap(['credential_uid' => 'PLAINUID12345'])->assertOk();
    }

    #[Test]
    public function an_unknown_kind_is_rejected_by_validation(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);

        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'WEIRDKIND0001',
            'credential_kind' => 'nfc-quantum',
        ], $this->headers())->assertStatus(422);

        $this->assertDatabaseMissing('cards', ['credential_uid' => 'WEIRDKIND0001']);
    }

    #[Test]
    public function an_hce_pairing_without_key_material_is_rejected_and_creates_nothing(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);

        // A bare id: no phone credential without a key.
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'PLS-BAREIDONLY01',
            'credential_kind' => 'hce',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hce_nonce', 'hce_mac', 'hce_key_wrapped', 'hce_key_nonce']);

        // Malformed hex is a validation error too.
        $phone = new FakeHcePhone('PLS-BAREIDONLY01');
        $this->pair($phone, null, ['hce_mac' => 'zz'])->assertStatus(422);

        $this->assertDatabaseMissing('cards', ['credential_uid' => 'PLS-BAREIDONLY01']);
    }

    #[Test]
    public function a_proof_that_does_not_verify_under_the_handed_over_key_is_rejected(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);
        $phone = new FakeHcePhone('PLS-BADPROOF0001');
        $impostor = new FakeHcePhone('PLS-BADPROOF0001');

        // Wrapped key from one phone, proof from another key.
        $body = $phone->pair($this->reader());
        $body = array_merge($body, $impostor->proof());

        $this->postJson('/api/v1/admin/cards/pair', $body, $this->headers())
            ->assertStatus(403)
            ->assertJson(['reason' => 'hce_proof_invalid']);

        $this->assertDatabaseMissing('cards', ['credential_uid' => 'PLS-BADPROOF0001']);

        // The window stays armed and the desk sees why.
        $status = app(PairingService::class)->statusPayload();
        $this->assertSame($student->id, $status['pending']['student_id']);
        $this->assertSame('hce_proof_invalid', $status['pending']['last_rejection']['reason']);

        // A correct retry in the same window succeeds.
        $this->pair($phone)->assertOk();
    }

    #[Test]
    public function a_key_wrapped_for_another_reader_cannot_be_unwrapped(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);
        $phone = new FakeHcePhone('PLS-WRONGREADER1');

        $this->postJson(
            '/api/v1/admin/cards/pair',
            $phone->pair($this->reader(), wrapWith: 'some-other-reader-key-000000000'),
            $this->headers()
        )->assertStatus(403);

        $this->assertDatabaseMissing('cards', ['credential_uid' => 'PLS-WRONGREADER1']);
    }

    #[Test]
    public function a_replayed_pairing_body_is_rejected(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);
        $phone = new FakeHcePhone('PLS-REPLAYPAIR01');
        $body = $phone->pair($this->reader());
        $this->postJson('/api/v1/admin/cards/pair', $body, $this->headers())->assertOk();

        // Unpair and re-arm, then replay the captured body verbatim.
        $card = Card::where('credential_uid', 'PLS-REPLAYPAIR01')->firstOrFail();
        $card->delete();
        $this->arm($student);

        $this->postJson('/api/v1/admin/cards/pair', $body, $this->headers())->assertStatus(403);
        $this->assertDatabaseMissing('cards', ['credential_uid' => 'PLS-REPLAYPAIR01']);
    }

    // ---------------------------------------------------------------
    // Tap verification
    // ---------------------------------------------------------------

    #[Test]
    public function a_phone_tap_without_proof_is_rejected_even_with_a_valid_reader_key(): void
    {
        [$phone] = $this->enrolledPhone('PLS-NOPROOFTAP01');

        // A reader-key holder can no longer just claim a phone id.
        $this->tap(['credential_uid' => $phone->credentialUid])
            ->assertStatus(403)
            ->assertJson(['status' => 'error', 'reason' => 'hce_auth_failed']);

        $this->assertSame(0, $this->phoneEvents());
        $this->assertDatabaseHas('tap_feedback', ['cue' => 'rejected', 'reason' => 'hce_auth_failed']);
    }

    #[Test]
    public function wrong_key_wrong_nonce_and_wrong_credential_id_all_fail(): void
    {
        [$phone] = $this->enrolledPhone('PLS-WRONGTHINGS1');
        $nonce = random_bytes(8);

        // Wrong key: a different phone claiming this id.
        $stranger = new FakeHcePhone($phone->credentialUid);
        $this->tap(['credential_uid' => $phone->credentialUid] + $stranger->proof())
            ->assertStatus(403);

        // Wrong nonce: a MAC over one nonce presented with another.
        $proof = $phone->proof($nonce);
        $proof['hce_nonce'] = bin2hex(random_bytes(8));
        $this->tap(['credential_uid' => $phone->credentialUid] + $proof)->assertStatus(403);

        // Wrong credential id inside the MAC.
        $this->tap(['credential_uid' => $phone->credentialUid] + $phone->proof(null, 'PLS-SOMEONEELSE1'))
            ->assertStatus(403);

        $this->assertSame(0, $this->phoneEvents());

        // Then the genuine phone still works.
        $this->tap($phone->tap())->assertOk();
    }

    #[Test]
    public function credential_a_key_cannot_authenticate_as_credential_b(): void
    {
        [$phoneA] = $this->enrolledPhone('PLS-CREDENTIALA1');
        [$phoneB, $studentB] = $this->enrolledPhone('PLS-CREDENTIALB1');

        // Phone A computes a perfectly valid-looking MAC for B's id with A's key.
        $this->tap(['credential_uid' => $phoneB->credentialUid] + $phoneA->proof(null, $phoneB->credentialUid))
            ->assertStatus(403);

        // And A's own proof relabeled as B.
        $this->tap(['credential_uid' => $phoneB->credentialUid] + $phoneA->proof())
            ->assertStatus(403);

        $this->assertSame(0, $this->phoneEvents());
        $this->tap($phoneB->tap())->assertOk()->assertJson(['student_first_name' => $studentB->firstName()]);
    }

    #[Test]
    public function a_replayed_tap_transcript_is_rejected(): void
    {
        [$phone] = $this->enrolledPhone('PLS-REPLAYTAP001');
        $body = $phone->tap();

        $this->tap($body)->assertOk();
        $this->tap($body)->assertStatus(403)->assertJson(['reason' => 'hce_auth_failed']);
    }

    #[Test]
    public function a_phone_credential_with_no_key_fails_closed(): void
    {
        [$phone] = $this->enrolledPhone('PLS-MISSINGKEY01');

        HceCredentialKey::query()->delete();

        $this->tap($phone->tap())->assertStatus(403)->assertJson(['reason' => 'hce_auth_failed']);

        // A row (kind hce, never had a key) behaves the same.
        $legacy = Card::create([
            'credential_uid' => 'PLS-LEGACYNOKEY1',
            'kind' => 'hce',
            'student_id' => $this->studentWithoutCard()->id,
        ]);
        $this->tap((new FakeHcePhone($legacy->credential_uid))->tap())->assertStatus(403);

        // A corrupt stored value is "no key", never an exception.
        $card = Card::where('credential_uid', 'PLS-MISSINGKEY01')->firstOrFail();
        HceCredentialKey::create([
            'card_id' => $card->id, 'secret' => 'not-hex', 'fingerprint' => '0000000000000000', 'provisioned_at' => now(),
        ]);
        $this->tap($phone->tap())->assertStatus(403);

        $this->assertSame(0, $this->phoneEvents());
    }

    #[Test]
    public function capture_association_also_requires_the_proof(): void
    {
        [$phone] = $this->enrolledPhone('PLS-EVERYDOOR001');

        // Bottle-first recycling association.
        Storage::fake('local');
        $this->swap(MaterialClassifier::class, new class implements MaterialClassifier
        {
            public function classify(string $imagePath): array
            {
                return ['material_class' => 'plastic', 'confidence' => 0.9, 'is_bottle' => true, 'is_recyclable' => true];
            }
        });

        $reader = $this->reader();
        $captureId = $this->post('/api/v1/recycling/capture', [
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], $this->headers($reader))->json('capture_id');

        $this->postJson("/api/v1/recycling/captures/{$captureId}/associate", [
            'credential_uid' => $phone->credentialUid,
        ], $this->headers($reader))
            ->assertStatus(403)
            ->assertJson(['reason' => 'hce_auth_failed']);

        // The window stayed open: the proven retry resolves it.
        $this->postJson("/api/v1/recycling/captures/{$captureId}/associate", $phone->tap(), $this->headers($reader))
            ->assertOk()
            ->assertJson(['capture_state' => 'accepted']);
    }

    // ---------------------------------------------------------------
    // Re-keying and overwrite protection
    // ---------------------------------------------------------------

    #[Test]
    public function the_same_student_can_rekey_a_reinstalled_phone_and_history_is_kept(): void
    {
        [$phone, $student] = $this->enrolledPhone('PLS-REINSTALL001');
        $this->tap($phone->tap())->assertOk();

        // Reinstall: same id, Keystore wiped, new key.
        $reinstalled = new FakeHcePhone($phone->credentialUid);
        $this->tap($reinstalled->tap())->assertStatus(403);

        $this->arm($student);
        $this->pair($reinstalled)->assertOk()->assertJson(['rekeyed' => true, 'student_id' => $student->id]);

        $card = Card::where('credential_uid', $phone->credentialUid)->firstOrFail();
        $this->assertSame(1, Card::where('credential_uid', $phone->credentialUid)->count());
        $this->assertSame(1, PresenceEvent::where('card_id', $card->id)->count());

        // Old key is dead, new key works. History is kept.
        $this->tap($phone->tap())->assertStatus(403);
        $this->tap($reinstalled->tap())->assertOk();
        $this->assertSame(2, PresenceEvent::where('card_id', $card->id)->count());
    }

    #[Test]
    public function provisioning_can_never_overwrite_another_students_credential_key(): void
    {
        [$victim] = $this->enrolledPhone('PLS-VICTIMPHONE1');
        $victimCard = Card::where('credential_uid', $victim->credentialUid)->firstOrFail();
        $before = HceCredentialKey::where('card_id', $victimCard->id)->firstOrFail()->fingerprint;

        // Arm a DIFFERENT student; a phone presents the victim's
        // (public, skimmable) id with its own key.
        $attackerStudent = $this->studentWithoutCard();
        $this->arm($attackerStudent);
        $this->pair(new FakeHcePhone($victim->credentialUid))->assertStatus(422);

        // A physical pair attempt with the victim's id is rejected too.
        $this->postJson('/api/v1/admin/cards/pair', ['credential_uid' => $victim->credentialUid], $this->headers())
            ->assertStatus(422);

        $victimCard->refresh();
        $this->assertNotSame($attackerStudent->id, $victimCard->student_id);
        $this->assertSame($before, HceCredentialKey::where('card_id', $victimCard->id)->firstOrFail()->fingerprint);
        $this->tap($victim->tap())->assertOk();
    }

    #[Test]
    public function a_physical_card_can_never_be_turned_into_a_phone_credential(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);
        $this->postJson('/api/v1/admin/cards/pair', ['credential_uid' => 'PHYS-ALREADY-01'], $this->headers())->assertOk();

        $this->arm($student);
        $this->pair(new FakeHcePhone('PHYS-ALREADY-01'))->assertStatus(422);

        $this->assertSame('physical', Card::where('credential_uid', 'PHYS-ALREADY-01')->firstOrFail()->kind->value);
        $this->assertSame(0, HceCredentialKey::count());
    }

    // ---------------------------------------------------------------
    // Identity
    // ---------------------------------------------------------------

    #[Test]
    public function identification_does_not_depend_on_the_rf_uid(): void
    {
        // No rf_uid column, no rf_uid input: identity is the application-
        // level credential id from the APDU exchange, proven per tap.
        $this->assertFalse(Schema::hasColumn('cards', 'rf_uid'));

        [$phone, $student] = $this->enrolledPhone('HCE-PHONE-CRED-9');

        // Two taps — as if from two randomized RF UIDs — resolve to the same student.
        foreach ([1, 2] as $tap) {
            $this->tap($phone->tap())
                ->assertOk()
                ->assertJson(['student_first_name' => $student->firstName()]);
        }

        $this->assertSame($student->id, Card::where('credential_uid', 'HCE-PHONE-CRED-9')->firstOrFail()->student_id);
    }
}
