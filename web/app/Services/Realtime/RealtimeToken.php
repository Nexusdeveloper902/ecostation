<?php

namespace App\Services\Realtime;

/**
 * Token del feed: `userId.expiry.signature`, con firma HMAC-SHA256 de
 * "realtime:userId.expiry" usando APP_KEY. Permite que el proceso
 * WebSocket autentique a un usuario sin parsear sesiones de Laravel;
 * caduca en minutos y se re-emite al reconectar.
 *
 * Sin una APP_KEY utilizable la firma falla CERRADA: no se emiten
 * tokens y no se verifica ninguno (ni siquiera uno forjado con la
 * clave vacía).
 */
final class RealtimeToken
{
    public static function issue(int $userId, ?int $expiresAt = null): string
    {
        $expiresAt ??= time() + self::defaultTtl();

        $payload = $userId.'.'.$expiresAt;
        $signature = self::signature($payload);

        if ($signature === null) {
            throw new \RuntimeException(
                'APP_KEY no está definida o es demasiado corta para firmar tokens del feed en vivo.'
            );
        }

        return $payload.'.'.$signature;
    }

    public static function verify(?string $token): ?int
    {
        $claims = self::inspect($token);

        if ($claims === null || $claims['expires_at'] < time()) {
            return null;
        }

        return $claims['user_id'];
    }

    /**
     * Verifica la firma y devuelve los claims SIN comprobar la caducidad;
     * el servidor en vivo necesita el `expires_at` para cerrar la
     * conexión exactamente cuando vence el token.
     *
     * @return array{user_id: int, expires_at: int}|null
     */
    public static function inspect(?string $token): ?array
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

        $expected = self::signature($userId.'.'.$expiresAt);

        if ($expected === null || ! hash_equals($expected, $signature)) {
            return null;
        }

        return ['user_id' => (int) $userId, 'expires_at' => (int) $expiresAt];
    }

    /** Segundos epoch en que muere un token recién emitido. */
    public static function freshExpiry(): int
    {
        return time() + self::defaultTtl();
    }

    /** ¿Hay una APP_KEY suficientemente fuerte para firmar? */
    public static function hasUsableKey(): bool
    {
        return self::key() !== null;
    }

    private static function defaultTtl(): int
    {
        return max(60, (int) config('realtime.token_ttl_seconds', 900));
    }

    /**
     * APP_KEY utilizable: 32+ bytes, ya sea literal o tras decodificar
     * el prefijo `base64:` que usa `key:generate`.
     */
    private static function key(): ?string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return is_string($decoded) && strlen($decoded) >= 32 ? $decoded : null;
        }

        return strlen($key) >= 32 ? $key : null;
    }

    private static function signature(string $payload): ?string
    {
        $key = self::key();

        return $key === null ? null : hash_hmac('sha256', 'realtime:'.$payload, $key);
    }
}
