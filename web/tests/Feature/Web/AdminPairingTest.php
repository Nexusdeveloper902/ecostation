<?php

namespace Tests\Feature\Web;

use App\Models\Card;
use App\Models\HceCredentialKey;
use App\Models\PendingPairing;
use App\Models\PresenceEvent;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHcePhone;
use Tests\TestCase;

/**
 * The pairing desk and the credential lifecycle: arm through the admin
 * route, pair from the device, revoke a lost credential (which destroys
 * its key) and unpair it (which deletes the row). Admin-only.
 *
 * These are the HCE revoke/badge cases deferred from Task 7.
 */
class AdminPairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        PresenceEvent::query()->delete();
    }

    private function studentWithoutCard(): Student
    {
        return Student::create([
            'name' => 'Estudiante Escritorio '.uniqid(),
            'class_id' => SchoolClass::where('name', '5° A')->value('id'),
        ]);
    }

    private function pair(FakeHcePhone $phone): TestResponse
    {
        return $this->postJson('/api/v1/admin/cards/pair', $phone->pair($this->reader()), [
            'Authorization' => 'Bearer '.$this->readerToken(),
        ]);
    }

    private function tap(array $body): TestResponse
    {
        return $this->postJson('/api/v1/events/tap', $body, [
            'Authorization' => 'Bearer '.$this->readerToken(),
        ]);
    }

    private function enrolledPhone(string $credentialUid): array
    {
        $student = $this->studentWithoutCard();
        $phone = new FakeHcePhone($credentialUid);

        $this->actingAs($this->admin())
            ->post('/admin/pairing/arm', ['student_id' => $student->id])
            ->assertRedirect();

        $this->pair($phone)->assertOk()->assertJson(['status' => 'ok', 'rekeyed' => false]);

        return [$phone, $student];
    }

    #[Test]
    public function arming_through_the_route_and_then_the_device_pair_succeeds(): void
    {
        $student = $this->studentWithoutCard();

        $this->actingAs($this->admin())
            ->post('/admin/pairing/arm', ['student_id' => $student->id])
            ->assertRedirect();

        $this->postJson('/api/v1/admin/cards/pair', ['credential_uid' => 'DESK-ARM-0001'], [
            'Authorization' => 'Bearer '.$this->readerToken(),
        ])->assertOk();

        $this->assertDatabaseHas('cards', [
            'credential_uid' => 'DESK-ARM-0001',
            'student_id' => $student->id,
            'kind' => 'physical',
        ]);
    }

    #[Test]
    public function a_teacher_cannot_arm_a_pairing(): void
    {
        $student = $this->studentWithoutCard();

        $this->actingAs($this->teacher())
            ->post('/admin/pairing/arm', ['student_id' => $student->id])
            ->assertForbidden();

        $this->assertSame(0, PendingPairing::count());
    }

    #[Test]
    public function revoking_a_phone_kills_the_next_proven_tap_and_destroys_its_key(): void
    {
        [$phone] = $this->enrolledPhone('HCE-REVOKEME-1');
        $this->tap($phone->tap())->assertOk();

        $card = Card::where('credential_uid', 'HCE-REVOKEME-1')->firstOrFail();
        $this->assertSame(1, HceCredentialKey::where('card_id', $card->id)->count());

        $this->actingAs($this->admin())
            ->post("/admin/cards/{$card->id}/revoke")
            ->assertRedirect(route('admin.pairing'));

        $this->assertSame('revoked', $card->fresh()->status->value);
        $this->assertSame(0, HceCredentialKey::where('card_id', $card->id)->count());

        // Revocation wins before any crypto: a perfect proof still fails.
        $this->tap($phone->tap())->assertNotFound()->assertJson(['reason' => 'inactive']);

        // Even a hand-flipped status cannot resurrect it: the key is gone.
        $card->refresh()->update(['status' => 'active']);
        $this->tap($phone->tap())->assertForbidden();
        $card->update(['status' => 'revoked']);

        // History is kept (unlike unpair).
        $this->assertSame(1, PresenceEvent::where('card_id', $card->id)->count());
    }

    #[Test]
    public function unpair_deletes_the_card_row(): void
    {
        [$phone] = $this->enrolledPhone('HCE-UNPAIR-0001');
        $card = Card::where('credential_uid', 'HCE-UNPAIR-0001')->firstOrFail();

        $this->actingAs($this->admin())
            ->delete("/admin/cards/{$card->id}")
            ->assertRedirect(route('admin.pairing'));

        $this->assertDatabaseMissing('cards', ['id' => $card->id]);
        $this->assertSame(0, HceCredentialKey::where('card_id', $card->id)->count());
    }

    #[Test]
    public function the_status_feed_reports_the_credential_kind(): void
    {
        $this->enrolledPhone('HCE-STATUS-KIND-1');

        $this->actingAs($this->admin())
            ->getJson('/admin/pairing/status')
            ->assertOk()
            ->assertJsonPath('last_pairing.card_uid', 'HCE-STATUS-KIND-1')
            ->assertJsonPath('last_pairing.card_kind', 'hce')
            ->assertJsonPath('recent_pairings.0.card_kind', 'hce');
    }

    #[Test]
    public function the_pairing_desk_badges_phone_credentials_and_hides_the_revoke_after_revoking(): void
    {
        $this->enrolledPhone('HCE-DESK-BADGE-1');
        $card = Card::where('credential_uid', 'HCE-DESK-BADGE-1')->firstOrFail();
        $revokeAction = route('admin.cards.revoke', $card);

        $this->actingAs($this->admin())
            ->get('/admin/pairing')
            ->assertOk()
            ->assertSee('HCE-DESK-BADGE-1')
            ->assertSee('Teléfono')
            ->assertSee($revokeAction, false);

        $this->actingAs($this->admin())
            ->post("/admin/cards/{$card->id}/revoke")
            ->assertRedirect(route('admin.pairing'));

        $this->actingAs($this->admin())
            ->get('/admin/pairing')
            ->assertOk()
            ->assertSee('Revocada')
            ->assertDontSee($revokeAction, false);
    }
}
