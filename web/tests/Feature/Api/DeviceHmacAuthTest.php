<?php

namespace Tests\Feature\Api;

use App\Models\Reader;
use App\Services\DeviceAuth\DeviceRequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pulse-HMAC signed device authentication (wire-compatible with the
 * firmware). The reader secret never rides the wire: every request
 * carries `Authorization: Pulse-HMAC <kid>:<nonce>:<sig>`.
 *
 * Task 4 proves the scheme over real HTTP through the real middleware
 * against a throwaway probe route; Task 6 moves the happy path onto the
 * real tap endpoint. The classify/capture cases stay deferred until
 * Tasks 9 and 10.
 */
class DeviceHmacAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();

        // Task 4 has no tap/classify route yet; this probe proves reader.auth
        // end to end. Removed in Task 6.
        Route::middleware('reader.auth')->post('/api/v1/_probe', fn () => ['ok' => true]);
    }

    private function signedPost(
        string $body,
        ?string $nonce = null,
        ?string $secret = null,
        string $path = '/api/v1/_probe',
    ): TestResponse {
        $reader = $this->reader();
        $nonce ??= Str::random(32);
        $secret ??= $this->readerToken();

        $sig = DeviceRequestSigner::sign($secret, 'POST', $path, $body, $nonce);
        $kid = DeviceRequestSigner::fingerprint($secret);

        return $this->call(
            'POST',
            $path,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => "Pulse-HMAC {$kid}:{$nonce}:{$sig}",
            ],
            $body
        );
    }

    #[Test]
    public function a_legitimately_signed_device_authenticates(): void
    {
        $response = $this->signedPost(json_encode(['probe' => 'ok']));

        $response->assertOk()->assertJson(['ok' => true]);
    }

    #[Test]
    public function a_wrong_secret_is_rejected_without_touching_state(): void
    {
        $response = $this->signedPost(json_encode(['probe' => 'ok']), secret: 'wrong-secret-000000000000000001');

        $response->assertUnauthorized()
            ->assertJson(['status' => 'error', 'message' => 'Firma de dispositivo no válida']);

        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function a_tampered_body_invalidates_the_captured_signature(): void
    {
        // Sign body A, send body B with the same header: the signature is
        // body-bound, so the request dies at the middleware.
        $reader = $this->reader();
        $nonce = Str::random(32);
        $sig = DeviceRequestSigner::sign($this->readerToken(), 'POST', '/api/v1/_probe', json_encode(['probe' => 'A']), $nonce);

        $response = $this->call(
            'POST',
            '/api/v1/_probe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Pulse-HMAC '.DeviceRequestSigner::fingerprint($reader->api_key).":{$nonce}:{$sig}",
            ],
            json_encode(['probe' => 'B'])
        );

        $response->assertUnauthorized()->assertJson(['status' => 'error']);
    }

    #[Test]
    public function a_captured_request_replayed_verbatim_is_rejected(): void
    {
        // The exact bytes of a legitimate request, captured off the wire
        // and replayed: the single-use nonce is already spent.
        $reader = $this->reader();
        $body = json_encode(['probe' => 'ok']);
        $nonce = Str::random(32);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => DeviceRequestSigner::authorizationHeader($reader, 'POST', '/api/v1/_probe', $body, $nonce),
        ];

        $this->call('POST', '/api/v1/_probe', [], [], [], $server, $body)->assertOk();

        $this->call('POST', '/api/v1/_probe', [], [], [], $server, $body)
            ->assertUnauthorized()
            ->assertJson(['status' => 'error', 'message' => 'Firma de dispositivo no válida']);
    }

    #[Test]
    public function reconnects_are_stateless_fresh_nonce_per_request_always_passes(): void
    {
        // A device that changed IP, rebooted, or re-discovered the backend
        // carries no session: every request is self-contained.
        for ($i = 0; $i < 9; $i++) {
            $this->signedPost(json_encode(['probe' => $i]))->assertOk();
        }
    }

    #[Test]
    public function the_legacy_bench_bearer_still_works(): void
    {
        $this->postJson('/api/v1/_probe', ['probe' => 'ok'], [
            'Authorization' => 'Bearer '.$this->readerToken(),
        ])->assertOk()->assertJson(['ok' => true]);
    }

    #[Test]
    public function the_legacy_bearer_can_be_locked_down_to_signatures_only(): void
    {
        config(['ecostation.device_auth.allow_legacy_bearer' => false]);

        $this->postJson('/api/v1/_probe', ['probe' => 'ok'], [
            'Authorization' => 'Bearer '.$this->readerToken(),
        ])->assertUnauthorized();

        $this->signedPost(json_encode(['probe' => 'ok']))->assertOk();
    }

    #[Test]
    public function unknown_fingerprints_and_malformed_headers_fail_closed(): void
    {
        $body = json_encode(['probe' => 'ok']);
        $serverFor = fn (string $auth): array => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $auth,
        ];

        // Fingerprint of a secret no reader holds.
        $this->call('POST', '/api/v1/_probe', [], [], [], $serverFor(
            'Pulse-HMAC deadbeefdeadbeef:'.Str::random(32).':'.str_repeat('0', 64)
        ), $body)->assertUnauthorized();

        // Truncated / garbage shapes.
        $this->call('POST', '/api/v1/_probe', [], [], [], $serverFor('Pulse-HMAC short'), $body)
            ->assertUnauthorized();
        $this->call('POST', '/api/v1/_probe', [], [], [], $serverFor('Pulse-HMAC :'.Str::random(32).':'.str_repeat('0', 64)), $body)
            ->assertUnauthorized();
    }

    #[Test]
    public function the_wire_format_is_pinned_for_firmware_interop(): void
    {
        // Golden vector — hardcoded, NOT self-computed: the ESP32 signer
        // pins these exact literals. Any drift on either side breaks
        // interop loudly instead of silently 401ing fielded readers.
        $body = '{"credential_uid":"QHHIKKOSD6UU"}';

        $this->assertSame(
            'POST'."\n".'/api/v1/events/tap'."\n".'0123456789abcdef'."\n".'56d9e707d82e2d35fce5841b43c860a3b86a49336f04f96023782a52d8f66fb6',
            DeviceRequestSigner::canonical('POST', '/api/v1/events/tap', '0123456789abcdef', $body)
        );

        $this->assertSame(
            '4ec7367d8b340da73914b806992ac6544cc54c9887777cddc6f827b02566c4f1',
            DeviceRequestSigner::sign('test-secret-000000000000000001', 'POST', '/api/v1/events/tap', $body, '0123456789abcdef')
        );

        $this->assertSame(
            'b46b73a6f9444ea4',
            DeviceRequestSigner::fingerprint('test-secret-000000000000000001')
        );
    }

    #[Test]
    public function the_multipart_canonical_format_is_pinned_for_firmware_interop(): void
    {
        // Byte-exact mirror of firmware CapturePayload signing bodies.
        // sha256('abc') = ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad.
        $this->assertSame(
            "event_id=421\nimage.sha256=ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
            DeviceRequestSigner::multipartCanonical('421', 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
        );
        $this->assertSame(
            'image.sha256=ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            DeviceRequestSigner::multipartCanonical(null, 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
        );
        // Event ids normalize to plain ints (device sends to_string, no padding).
        $this->assertSame(
            DeviceRequestSigner::multipartCanonical('421', 'aa'),
            DeviceRequestSigner::multipartCanonical('0421', 'aa')
        );
    }

    #[Test]
    public function a_signed_device_classifies_with_the_multipart_canonical(): void
    {
        $this->markTestSkipped('Task 9/10');
    }

    #[Test]
    public function a_swapped_image_invalidates_the_multipart_signature(): void
    {
        $this->markTestSkipped('Task 9/10');
    }

    #[Test]
    public function a_signed_device_captures_bottle_first_with_the_multipart_canonical(): void
    {
        $this->markTestSkipped('Task 9/10');
    }
}
