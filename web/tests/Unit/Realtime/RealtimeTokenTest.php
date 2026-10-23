<?php

namespace Tests\Unit\Realtime;

use App\Services\Realtime\RealtimeToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tokens del feed: emisión/verificación, caducidad, manipulación y
 * clave equivocada.
 */
class RealtimeTokenTest extends TestCase
{
    private const APP_KEY = 'realtime-test-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => self::APP_KEY]);
    }

    #[Test]
    public function tokens_roundtrip_to_the_user_id(): void
    {
        $token = RealtimeToken::issue(7, time() + 300);

        $this->assertSame(7, RealtimeToken::verify($token));
    }

    #[Test]
    public function expired_tokens_are_rejected(): void
    {
        $token = RealtimeToken::issue(7, time() - 1);

        $this->assertNull(RealtimeToken::verify($token));
    }

    #[Test]
    public function tampered_payloads_are_rejected(): void
    {
        $token = RealtimeToken::issue(7, time() + 300);
        [$userId, $expiresAt, $signature] = explode('.', $token);

        $forged = '8.'.$expiresAt.'.'.$signature;
        $this->assertNull(RealtimeToken::verify($forged));

        $this->assertNull(RealtimeToken::verify('7.'.$expiresAt.'.deadbeef'));
    }

    #[Test]
    public function malformed_tokens_are_rejected(): void
    {
        $this->assertNull(RealtimeToken::verify(null));
        $this->assertNull(RealtimeToken::verify(''));
        $this->assertNull(RealtimeToken::verify('no-dots-here'));
        $this->assertNull(RealtimeToken::verify('a.b.c'));
        $this->assertNull(RealtimeToken::verify('0.9999999999.'.hash_hmac('sha256', 'realtime:0.9999999999', self::APP_KEY)));
    }

    #[Test]
    public function a_token_signed_with_a_different_app_key_is_rejected(): void
    {
        $token = RealtimeToken::issue(7, time() + 300);

        config(['app.key' => 'somebody-elses-key']);

        $this->assertNull(RealtimeToken::verify($token));
    }

    #[Test]
    public function fresh_tokens_expire_at_the_configured_ttl(): void
    {
        config(['realtime.token_ttl_seconds' => 600]);

        $token = RealtimeToken::issue(3);

        $this->assertNotNull(RealtimeToken::verify($token));
        $this->assertEqualsWithDelta(time() + 600, RealtimeToken::freshExpiry(), 2);
    }
}
