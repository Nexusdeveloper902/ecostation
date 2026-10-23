<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canal en vivo (WebSockets)
    |--------------------------------------------------------------------------
    |
    | `php artisan realtime:serve` empuja taps, clasificaciones, cues de
    | audio y el estado del emparejamiento. Es un servidor propio sobre
    | sockets de PHP (sin dependencias), y sondea la base de datos: la
    | web y el feed quedan desacoplados.
    |
    */

    // Interfaz de escucha. `./run serve` lo arranca junto a la web.
    'host' => env('REALTIME_HOST', '0.0.0.0'),

    // Puerto del WebSocket; debe diferir del puerto web (8000).
    'port' => (int) env('REALTIME_PORT', 8081),

    // Cada cuánto se consulta la base por novedades (ms).
    'poll_ms' => (int) env('REALTIME_POLL_MS', 300),

    // Cuántas filas recientes viajan en el frame `hello`.
    'history_limit' => (int) env('REALTIME_HISTORY_LIMIT', 20),

    // Techo de clientes concurrentes (handshake incluido). Un servidor
    // de aula no debe aceptar sockets sin límite.
    'max_clients' => (int) env('REALTIME_MAX_CLIENTS', 256),

    // Un socket que no completa el upgrade en este plazo se descarta.
    'handshake_timeout_seconds' => (int) env('REALTIME_HANDSHAKE_TIMEOUT_SECONDS', 5),

    // Orígenes de navegador permitidos además del mismo host. Los
    // clientes nativos sin `Origin` siempre pasan.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('REALTIME_ALLOWED_ORIGINS', '')),
    ))),

    // Base pública opcional para el proxy TLS inverso: si se define
    // (p. ej. `wss://feed.example.com`), el token responde esa URL;
    // sin ella se conserva el `ws://` de la LAN.
    'public_url' => env('REALTIME_PUBLIC_URL'),

    // Vida del token del feed (se re-emite al reconectar).
    'token_ttl_seconds' => (int) env('REALTIME_TOKEN_TTL_SECONDS', 900),

    // Vida máxima de una conexión WebSocket, aunque su token viva más.
    'max_connection_seconds' => (int) env('REALTIME_MAX_CONNECTION_SECONDS', 3600),

    // Cada cuánto se recalculan rol y cursos de una conexión abierta.
    // Un cambio de rol o de cursos se aplica sin reconectar.
    'scope_refresh_seconds' => (int) env('REALTIME_SCOPE_REFRESH_SECONDS', 45),

];
