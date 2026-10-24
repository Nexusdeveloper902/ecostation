<?php

namespace App\Services\DeviceAuth;

use App\Models\Reader;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * Pulse-HMAC device signing: one canonical string owns both sides of the
 * wire contract (see docs and the firmware signer).
 *
 * `Authorization: Pulse-HMAC <kid>:<nonce>:<sig>` where kid is
 * sha256(api_key)[0:16] (public), nonce is single-use, and sig is
 * HMAC-SHA256(api_key, "METHOD\npath[?query]\nnonce\nsha256hex(body)").
 * Multipart uploads sign a canonical built from event_id + the image
 * hash because PHP does not expose raw multipart bytes.
 */
class DeviceRequestSigner
{
    /** Fingerprint length (hex chars of sha256(api_key)). */
    public const KID_LEN = 16;

    /** Nonce cache TTL — a captured signature stays dead at least this long. */
    public const NONCE_TTL_SECONDS = 86400;

    /** @return array{ok: true, reader: Reader}|array{ok: false, reason: 'malformed'|'unknown'|'replay'|'mismatch'} */
    public function verify(Request $request, string $header): array
    {
        $parts = explode(':', substr($header, strlen('Pulse-HMAC ')));

        if (count($parts) !== 3) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        [$kid, $nonce, $sig] = $parts;

        if (! ctype_xdigit($kid) || strlen($kid) !== self::KID_LEN
            || ! preg_match('/^[A-Za-z0-9]{16,64}$/', $nonce)
            || ! ctype_xdigit($sig) || strlen($sig) !== 64) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $reader = $this->readerForFingerprint(strtolower($kid));

        if ($reader === null) {
            return ['ok' => false, 'reason' => 'unknown'];
        }

        $expected = self::sign($reader->api_key, $request->method(), self::requestPath($request), self::canonicalBody($request), $nonce);

        if (! hash_equals($expected, strtolower($sig))) {
            return ['ok' => false, 'reason' => 'mismatch'];
        }

        // Cache::add is set-if-absent: the loser of a replay answers 401.
        $claimed = Cache::add(
            'pulse-hmac-nonce:'.$reader->id.':'.$nonce,
            1,
            self::NONCE_TTL_SECONDS
        );

        if (! $claimed) {
            return ['ok' => false, 'reason' => 'replay'];
        }

        return ['ok' => true, 'reader' => $reader];
    }

    /**
     * Fingerprint => reader id map, cached because verification used to load
     * every reader row on every request. Reader model events invalidate it
     * on create/rotate/delete.
     *
     * Only scalars go into the cache: `config('cache.serializable_classes')`
     * is `false`, so an Eloquent model stored in the database cache comes
     * back as `__PHP_Incomplete_Class` on the next request. The reader row is
     * loaded by id after the map hit (one indexed query).
     */
    private function readerForFingerprint(string $kid): ?Reader
    {
        /** @var array<string, int> $map */
        $map = Cache::rememberForever(Reader::AUTH_MAP_CACHE_KEY, function (): array {
            return Reader::query()->get(['id', 'api_key'])->mapWithKeys(
                fn (Reader $reader): array => [self::fingerprint((string) $reader->api_key) => (int) $reader->id],
            )->all();
        });

        $id = $map[$kid] ?? null;

        return $id === null ? null : Reader::find($id);
    }

    /** Sign a request (legitimate devices, bench curl, tests). */
    public static function sign(string $secret, string $method, string $path, string $body, string $nonce): string
    {
        return hash_hmac('sha256', self::canonical($method, $path, $nonce, $body), $secret);
    }

    /** Authorization header value for a reader + request. */
    public static function authorizationHeader(Reader $reader, string $method, string $path, string $body, string $nonce): string
    {
        return 'Pulse-HMAC '.self::fingerprint($reader->api_key).':'.$nonce.':'
            .self::sign($reader->api_key, $method, $path, $body, $nonce);
    }

    /** Public key fingerprint — safe to send in clear, reveals nothing about the key. */
    public static function fingerprint(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, self::KID_LEN);
    }

    /** The exact string both sides sign — byte-for-byte contract. */
    public static function canonical(string $method, string $path, string $nonce, string $body): string
    {
        return strtoupper($method)."\n".$path."\n".$nonce."\n".hash('sha256', $body);
    }

    /**
     * Multipart signing canonical — the `body` both sides sign for image
     * uploads. Byte-exact with the firmware CapturePayload:
     *   classify: "event_id=<id>\nimage.sha256=<hex>"
     *   capture:  "image.sha256=<hex>"
     */
    public static function multipartCanonical(?string $eventId, string $imageSha256): string
    {
        $imageSha256 = strtolower($imageSha256);
        if ($eventId !== null && $eventId !== '') {
            return 'event_id='.(string) (int) $eventId."\n".'image.sha256='.$imageSha256;
        }

        return 'image.sha256='.$imageSha256;
    }

    /**
     * The `body` input to sign()/verify(): raw bytes for JSON, the
     * multipart canonical for image uploads (hasFile('image')).
     */
    public static function canonicalBody(Request $request): string
    {
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            if (is_array($file)) {
                $file = reset($file);
            }
            if ($file instanceof UploadedFile && $file->isValid()) {
                $hash = @hash_file('sha256', $file->getPathname());
                if (is_string($hash) && $hash !== '') {
                    $eventId = $request->input('event_id');

                    return self::multipartCanonical($eventId !== null ? (string) $eventId : null, $hash);
                }
            }
            // Invalid/missing file bytes: fail closed, never match a valid signature.
            $eventId = $request->input('event_id');

            return self::multipartCanonical($eventId !== null ? (string) $eventId : null, '');
        }

        return (string) $request->getContent();
    }

    private static function requestPath(Request $request): string
    {
        $path = $request->getPathInfo();
        $query = $request->getQueryString();

        return $query !== null && $query !== '' ? $path.'?'.$query : $path;
    }
}
