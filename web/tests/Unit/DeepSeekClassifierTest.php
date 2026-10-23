<?php

namespace Tests\Unit;

use App\Services\Recycling\ClassificationException;
use App\Services\Recycling\Drivers\DeepSeekClassifier;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The DeepSeek vision driver, verified without a key: every wire claim is
 * pinned against Http::fake (model ID, detail:high, thinking-disabled, JSON
 * contract, user-message-only shape), and every failure mode degrades to
 * an honest driverUnavailable — never an exception leak.
 */
class DeepSeekClassifierTest extends TestCase
{
    /** A real 4x4 JPEG (content-detected, never extension-trusted). */
    private const JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElDPEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7/8AAEQgABAAEAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A8ZooooA//9k=';

    private string $jpeg;

    private string $txt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jpeg = sys_get_temp_dir().'/dscl-'.uniqid().'.jpg';
        file_put_contents($this->jpeg, (string) base64_decode(self::JPEG_BASE64));

        $this->txt = sys_get_temp_dir().'/dscl-'.uniqid().'.txt';
        file_put_contents($this->txt, 'not an image at all');
    }

    protected function tearDown(): void
    {
        @unlink($this->jpeg);
        @unlink($this->txt);
        parent::tearDown();
    }

    private function driver(?string $key = 'k'): DeepSeekClassifier
    {
        return new DeepSeekClassifier($key);
    }

    private function jpeg(): string
    {
        return $this->jpeg;
    }

    /** The full DeepSeek HTTP body for a successful classification. */
    private function okBody(string $class = 'plastic', array $override = []): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode(array_merge([
                'material_class' => $class,
                'confidence' => 0.91,
                'is_bottle' => $class === 'plastic',
                'is_recyclable' => $class !== 'other',
            ], $override))]]],
        ];
    }

    private function okResponse(array $content): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($content)]]],
            ], 200),
        ]);
    }

    #[Test]
    public function a_happy_path_returns_the_full_boundary_schema(): void
    {
        $this->okResponse([
            'material_class' => 'plastic',
            'confidence' => 0.91,
            'is_bottle' => true,
            'is_recyclable' => true,
        ]);

        $result = $this->driver()->classify($this->jpeg());

        $this->assertSame('plastic', $result['material_class']);
        $this->assertSame(0.91, $result['confidence']);
        $this->assertTrue($result['is_bottle']);
        $this->assertTrue($result['is_recyclable']);
    }

    #[Test]
    public function the_request_matches_todays_documented_wire_format(): void
    {
        $this->okResponse(['material_class' => 'paper', 'confidence' => 0.5]);

        $this->driver()->classify($this->jpeg());

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return ($payload['model'] ?? null) === 'deepseek-flash'
                && ($payload['response_format'] ?? []) === ['type' => 'json_object']
                && ($payload['temperature'] ?? null) === 0
                && ($payload['thinking'] ?? []) === ['type' => 'disabled']
                && ($payload['max_tokens'] ?? null) === 200
                && ($payload['messages'][0]['role'] ?? null) === 'user'
                && str_contains((string) ($payload['messages'][0]['content'][0]['text'] ?? ''), 'json')
                && ($payload['messages'][0]['content'][1]['type'] ?? null) === 'image_url'
                && str_starts_with((string) ($payload['messages'][0]['content'][1]['image_url']['url'] ?? ''), 'data:image/jpeg;base64,')
                && ($payload['messages'][0]['content'][1]['image_url']['detail'] ?? null) === 'high';
        });
    }

    #[Test]
    public function the_bearer_key_rides_the_header_never_the_url(): void
    {
        $this->okResponse(['material_class' => 'glass', 'confidence' => 0.7]);

        $this->driver('secret-key')->classify($this->jpeg());

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer secret-key')
                && ! str_contains($request->url(), 'secret-key');
        });
    }

    #[Test]
    public function boundary_fields_default_from_the_class_when_omitted(): void
    {
        $this->okResponse(['material_class' => 'paper', 'confidence' => 0.6]);
        $paper = $this->driver()->classify($this->jpeg());
        $this->assertFalse($paper['is_bottle']);
        $this->assertTrue($paper['is_recyclable']);
    }

    #[Test]
    public function other_defaults_to_non_bottle_non_recyclable(): void
    {
        $this->okResponse(['material_class' => 'other', 'confidence' => 0.4]);
        $other = $this->driver()->classify($this->jpeg());
        $this->assertFalse($other['is_bottle']);
        $this->assertFalse($other['is_recyclable']);
    }

    #[Test]
    public function confidence_above_one_clamps_to_one(): void
    {
        $this->okResponse(['material_class' => 'metal', 'confidence' => 4.2]);
        $this->assertSame(1.0, $this->driver()->classify($this->jpeg())['confidence']);
    }

    #[Test]
    public function confidence_below_zero_clamps_to_zero(): void
    {
        $this->okResponse(['material_class' => 'metal', 'confidence' => -3]);
        $this->assertSame(0.0, $this->driver()->classify($this->jpeg())['confidence']);
    }

    #[Test]
    public function a_missing_key_is_a_driver_failure_not_a_http_call(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        try {
            $this->driver(null)->classify($this->jpeg());
            $this->fail('classify without a key must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('no DEEPSEEK_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function an_unreadable_image_never_reaches_the_api(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        try {
            $this->driver()->classify('/no/such/image.png');
            $this->fail('classify on a missing file must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('not readable', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_non_image_file_is_rejected_by_content_not_extension(): void
    {
        // Faked transport FIRST: if a regression ever lets the type
        // through, the suite fails on stray-request instead of firing
        // real (slow, billable) traffic from a unit test.
        Http::fake();
        Http::preventStrayRequests();

        $this->expectException(ClassificationException::class);
        $this->expectExceptionMessageMatches('/not a supported kind/');

        $this->driver()->classify($this->txt);
    }

    #[Test]
    public function an_oversized_image_is_rejected_before_any_http(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        // Injectable ceiling (production default is the documented 32 MiB).
        try {
            (new DeepSeekClassifier('k', 'deepseek-flash', 15.0, 10))->classify($this->jpeg());
            $this->fail('classify over the ceiling must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('MiB single-image ceiling', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function empty_model_content_is_named_as_the_documented_flake(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => '']]],
        ], 200)]);

        try {
            $this->driver()->classify($this->jpeg());
            $this->fail('empty content must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('empty content', $e->getMessage());
        }
    }

    #[Test]
    public function stringy_booleans_parse_without_cast_traps(): void
    {
        // (bool)"false" === true in PHP — the strict parser must not
        // inherit that trap.
        $this->okResponse(['material_class' => 'plastic', 'confidence' => 0.9, 'is_bottle' => 'false', 'is_recyclable' => 'yes']);
        $result = $this->driver()->classify($this->jpeg());

        $this->assertFalse($result['is_bottle']);
        $this->assertTrue($result['is_recyclable']);
    }

    #[Test]
    public function prose_instead_of_json_throws_loudly(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'a bottle, probably']]],
        ], 200)]);

        try {
            $this->driver()->classify($this->jpeg());
            $this->fail('prose must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('valid material_class', $e->getMessage());
        }
    }

    #[Test]
    public function an_unknown_class_throws_loudly(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['material_class' => 'unobtanium', 'confidence' => 1])]]],
        ], 200)]);

        try {
            $this->driver()->classify($this->jpeg());
            $this->fail('an unknown class must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('valid material_class', $e->getMessage());
        }
    }

    #[Test]
    public function a_custom_model_override_still_sends(): void
    {
        $this->okResponse(['material_class' => 'plastic', 'confidence' => 0.8]);

        (new DeepSeekClassifier('k', 'deepseek-v4-flash-vision-exp'))->classify($this->jpeg());

        Http::assertSent(fn ($request): bool => ($request->data()['model'] ?? null) === 'deepseek-v4-flash-vision-exp');
    }

    #[Test]
    public function a_429_or_503_is_retried_once_then_succeeds(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'rate']], 429)
            ->push($this->okBody('metal'), 200)]);

        $this->assertSame('metal', $this->driver()->classify($this->jpeg())['material_class']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_persistent_500_fails_after_exactly_two_attempts(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->expectException(ClassificationException::class);
        try {
            $this->driver()->classify($this->jpeg());
        } finally {
            Http::assertSentCount(2);
        }
    }

    #[Test]
    public function a_402_is_named_insufficient_balance_and_not_retried(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'Insufficient Balance']], 402)]);

        try {
            $this->driver()->classify($this->jpeg());
            $this->fail('a 402 must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('saldo insuficiente', $e->getMessage());
        }

        Http::assertSentCount(1);
    }
}
