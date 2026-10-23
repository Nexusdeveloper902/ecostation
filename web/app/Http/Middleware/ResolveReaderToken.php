<?php

namespace App\Http\Middleware;

use App\Models\Reader;
use App\Services\DeviceAuth\DeviceRequestSigner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Device-side authentication: Pulse-HMAC signatures (the device path) or
 * the legacy static Bearer key (bench curl/Postman), which can be locked
 * down via DEVICE_AUTH_ALLOW_LEGACY_BEARER=false.
 */
class ResolveReaderToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (str_starts_with($header, 'Pulse-HMAC ')) {
            $result = app(DeviceRequestSigner::class)->verify($request, $header);

            if (! $result['ok']) {
                return $this->deny('api.invalid_device_signature');
            }

            $request->attributes->set('reader', $result['reader']);

            return $next($request);
        }

        if (! config('ecostation.device_auth.allow_legacy_bearer', true)) {
            return $this->deny('api.invalid_device_signature');
        }

        $token = $request->bearerToken();

        if (empty($token)) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.missing_bearer_token'),
            ], 401);
        }

        $reader = Reader::where('api_key', $token)->first();

        if ($reader === null) {
            return $this->deny('api.invalid_bearer_token');
        }

        $request->attributes->set('reader', $reader);

        return $next($request);
    }

    private function deny(string $key): Response
    {
        return response()->json([
            'status' => 'error',
            'message' => __($key),
        ], 401);
    }
}
