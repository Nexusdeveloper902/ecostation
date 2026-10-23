<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Realtime\RealtimeToken;
use Illuminate\Http\JsonResponse;

/**
 * Emite el token de corta vida que necesita el handshake WebSocket. La
 * ruta web va con sesión (el app del altavoz inicia sesión y la llama
 * con la cookie); el proceso del socket nunca toca sesiones, solo
 * verifica este token HMAC. `GET /api/v1/realtime/token` reutiliza este
 * controlador para un altavoz con Sanctum.
 */
class RealtimeTokenController extends Controller
{
    public function issue(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        // Un solo reloj: la caducidad que se anuncia es exactamente la
        // que quedó firmada dentro del token.
        $expiresAt = RealtimeToken::freshExpiry();

        $publicBase = config('realtime.public_url');
        $url = is_string($publicBase) && $publicBase !== ''
            ? rtrim($publicBase, '/').'/app'
            : 'ws://'.request()->getHost().':'.(int) config('realtime.port').'/app';

        return response()->json([
            'token' => RealtimeToken::issue((int) $user->id, $expiresAt),
            'expires_at' => $expiresAt,
            'url' => $url,
        ]);
    }
}
