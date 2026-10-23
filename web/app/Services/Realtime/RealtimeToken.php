<?php

namespace App\Services\Realtime;

/**
 * Token del feed: `userId.expiry.signature`, con firma HMAC-SHA256 de
 * "realtime:userId.expiry" usando APP_KEY. Permite que el proceso
 * WebSocket autentique a un usuario sin parsear sesiones de Laravel;
 * caduca en minutos y se re-emite al reconectar.
 */
final class RealtimeToken
{
    public static function issue(int $userId, ?int $expiresAt = null): string
    {
        $expiresAt ??= time() + self::defaultTtl();

        $payload = $userId.'.'.$expiresAt;

        return $payload.'.'.self::signature($payload);
    }

    public static function verify(?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            return null;
        }
        [$userId, $expiresAt, $signature] = $parts;

        if (! ctype_digit($userId) || ! ctype_digit($expiresAt) || $userId === '0') {
            return null;
        }

        if (! hash_equals(self::signature($userId.'.'.$expiresAt), $signature)) {
            return null;
        }

        if ((int) $expiresAt < time()) {
            return null;
        }

        return (int) $userId;
    }

    /** Segundos epoch en que muere un token recién emitido. */
    public static function freshExpiry(): int
    {
        return time() + self::defaultTtl();
    }

    private static function defaultTtl(): int
    {
        return max(60, (int) config('realtime.token_ttl_seconds', 900));
    }

    private static function signature(string $payload): string
    {
        return hash_hmac('sha256', 'realtime:'.$payload, (string) config('app.key'));
    }
}
