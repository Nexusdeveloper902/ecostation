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

    // Vida del token del feed (se re-emite al reconectar).
    'token_ttl_seconds' => (int) env('REALTIME_TOKEN_TTL_SECONDS', 900),

];
