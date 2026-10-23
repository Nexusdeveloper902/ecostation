<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Services\Realtime\Handshake;
use App\Services\Realtime\RealtimeFeed;
use App\Services\Realtime\RealtimePairing;
use App\Services\Realtime\RealtimeRecycling;
use App\Services\Realtime\RealtimeToken;
use App\Services\Realtime\TapFeedback;
use App\Services\Realtime\WsFrame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * realtime:serve — el feed en vivo por WebSockets.
 *
 * Servidor propio (sockets + stream_select, sin dependencias ni
 * extensiones): sondea `events`, `recycling_updates`,
 * `pending_pairings` y `tap_feedback`, y empuja frames a los clientes
 * autenticados con un token HMAC de corta vida.
 *
 * Entrega (fija en el plan):
 *   tap        admin todo · docente sus cursos · estación todo
 *   recycling  admin todo · docente sus cursos (payload sin class_id a
 *              todos) · estación todo, sin filtrar
 *   feedback   admin y estación
 *   pairing    solo admin (lleva UID de tarjetas)
 *   hello      historial de cada canal, nunca una señal de audio
 *
 * Una cuenta de estudiante no recibe token; si un token forjado llegara
 * con ese rol, la conexión no recibe nada (falla cerrada).
 */
class RealtimeServeCommand extends Command
{
    protected $signature = 'realtime:serve
        {--host= : Interfaz de escucha (por defecto config realtime.host)}
        {--port= : Puerto (por defecto config realtime.port)}
        {--check : Solo verifica APP_KEY y la configuración, sin abrir el socket}';

    protected $description = 'Sirve el canal en vivo por WebSockets (tap, recycling, feedback, pairing)';

    private const SELECT_TIMEOUT_SECONDS = 0;

    private const SELECT_TIMEOUT_MICRO = 250000;

    private const MAX_HANDSHAKE_BUFFER = 32768;

    /** Techo del buffer de salida por cliente (consumidor lento). */
    private const MAX_OUTBOUND_BUFFER = 1048576;

    /** El hello nunca lleva más de 20 eventos ni más de 64 KiB. */
    private const HELLO_MAX_EVENTS = 20;

    private const HELLO_MAX_BYTES = 65536;

    /**
     * @var array<int, array{
     *     sock: resource,
     *     buf: string,
     *     out: string,
     *     handshook: bool,
     *     admin: bool,
     *     role: string,
     *     class_ids: array<int, int>|null,
     *     user_id: int,
     *     expires_at: int,
     *     connected_at: float,
     *     scope_checked_at: float
     * }>
     */
    private array $clients = [];

    private int $maxClients = 256;

    private int $handshakeTimeout = 5;

    /** @var array<int, string> */
    private array $allowedOrigins = [];

    private int $lastEventId = 0;

    private float $lastPollAt = 0.0;

    private string $lastPairingSignature = '';

    private int $lastRecyclingId = 0;

    private int $lastFeedbackId = 0;

    private TapFeedback $tapFeedback;

    private RealtimeFeed $feed;

    private RealtimePairing $pairing;

    private RealtimeRecycling $recycling;

    public function handle(
        RealtimeFeed $feed,
        RealtimePairing $pairing,
        RealtimeRecycling $recycling,
        TapFeedback $tapFeedback,
    ): int {
        $this->feed = $feed;
        $this->tapFeedback = $tapFeedback;
        $this->pairing = $pairing;
        $this->recycling = $recycling;

        // Sin APP_KEY utilizable no hay firma verificable: el feed se
        // niega a arrancar en vez de aceptar tokens forjables.
        if (! RealtimeToken::hasUsableKey()) {
            $this->error('realtime:serve no puede arrancar: APP_KEY está vacía o es demasiado corta (mínimo 32 bytes).');
            $this->error('Genera una clave con: php artisan key:generate');

            return self::FAILURE;
        }

        if ((bool) $this->option('check')) {
            $this->info('Configuración en vivo correcta: APP_KEY utilizable.');

            return self::SUCCESS;
        }

        $host = (string) ($this->option('host') ?: config('realtime.host'));
        $port = (int) ($this->option('port') ?: config('realtime.port'));
        $pollMs = max(50, (int) config('realtime.poll_ms'));
        $historyLimit = max(1, (int) config('realtime.history_limit'));

        $this->maxClients = max(1, (int) config('realtime.max_clients', 256));
        $this->handshakeTimeout = max(1, (int) config('realtime.handshake_timeout_seconds', 5));
        $this->allowedOrigins = array_values((array) config('realtime.allowed_origins', []));

        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($server === false) {
            $this->error("realtime:serve no puede escuchar en {$host}:{$port} — {$errstr} ({$errno}).");
            $this->error('Puede haber otro servidor en ese puerto.');

            return self::FAILURE;
        }
        stream_set_blocking($server, false);

        // Arranca en la cabeza actual: el hello lleva el historial y solo
        // lo genuinamente nuevo se difunde.
        $this->lastEventId = $feed->latestEventId();
        $this->lastPollAt = microtime(true);
        $this->lastPairingSignature = $pairing->signature();
        $this->lastRecyclingId = $recycling->latestUpdateId();
        $this->lastFeedbackId = $tapFeedback->latestId();

        $this->info("realtime:serve escuchando en ws://{$host}:{$port} — sondeo {$pollMs} ms, historial {$historyLimit}, cabeza {$this->lastEventId}.");

        while (true) {
            $read = [$server];
            $write = [];
            foreach ($this->clients as $client) {
                $read[] = $client['sock'];
                // Solo los sockets con bytes pendientes entran en el
                // conjunto de escritura: backpressure real, no un
                // `fwrite` que descarta la cola.
                if ($client['out'] !== '') {
                    $write[] = $client['sock'];
                }
            }
            $except = null;

            $ready = @stream_select($read, $write, $except, self::SELECT_TIMEOUT_SECONDS, self::SELECT_TIMEOUT_MICRO);
            if ($ready === false) {
                break;
            }

            foreach ($read as $socket) {
                if ($socket === $server) {
                    $this->accept($server);
                } else {
                    $this->read($socket);
                }
            }

            foreach ($write as $socket) {
                $this->flush((int) $socket);
            }

            $this->poll($pollMs);
        }

        return self::SUCCESS;
    }

    private function accept($server): void
    {
        $new = @stream_socket_accept($server, 0);
        if ($new === false) {
            return;
        }

        // Sin tope, un cliente (o un bug) puede agotar descriptores. El
        // rechazo es HTTP plano: todavía no hay framing WebSocket.
        if (\count($this->clients) >= $this->maxClients) {
            @fwrite($new, Handshake::denialResponse(503, 'El feed en vivo está lleno; reintenta en unos segundos.'));
            @fclose($new);

            return;
        }

        stream_set_blocking($new, false);
        $this->clients[(int) $new] = [
            'sock' => $new,
            'buf' => '',
            'out' => '',
            'handshook' => false,
            'admin' => false,
            'role' => '',
            'class_ids' => null,
            'user_id' => 0,
            'expires_at' => 0,
            'connected_at' => microtime(true),
            'scope_checked_at' => microtime(true),
        ];
    }

    private function read($socket): void
    {
        $clientId = (int) $socket;
        if (! isset($this->clients[$clientId])) {
            return;
        }

        $data = @fread($socket, 8192);
        if ($data === false || ($data === '' && feof($socket))) {
            $this->drop($clientId);

            return;
        }
        if ($data === '') {
            return;
        }

        $this->clients[$clientId]['buf'] .= $data;

        if (! $this->clients[$clientId]['handshook']) {
            if (\strlen($this->clients[$clientId]['buf']) > self::MAX_HANDSHAKE_BUFFER) {
                $this->drop($clientId);

                return;
            }
            $this->handshake($clientId, $socket);

            return;
        }

        while (true) {
            $result = WsFrame::decode($this->clients[$clientId]['buf'], requireMask: true);
            if ($result['error'] !== null) {
                $this->drop($clientId);

                return;
            }
            if ($result['frame'] === null) {
                return;
            }
            $this->clients[$clientId]['buf'] = substr($this->clients[$clientId]['buf'], $result['consumed']);
            $this->handleFrame($clientId, $result['frame']);
            if (! isset($this->clients[$clientId])) {
                return;
            }
        }
    }

    private function handshake(int $clientId, $socket): void
    {
        $buffer = $this->clients[$clientId]['buf'];
        $headEnd = strpos($buffer, "\r\n\r\n");
        if ($headEnd === false) {
            return;
        }

        $head = substr($buffer, 0, $headEnd);
        $rest = substr($buffer, $headEnd + 4);
        $request = Handshake::parseRequest($head);
        $target = (string) $request['target'];

        $token = $request['query']['token'] ?? null;
        $claims = RealtimeToken::inspect(\is_string($token) ? $token : null);

        if ($claims === null || $claims['expires_at'] < time()) {
            $this->reject($clientId, $socket, 401, 'Token de feed en vivo inválido o vencido.', $target);

            return;
        }

        if (! Handshake::isUpgrade($request)) {
            $this->reject($clientId, $socket, 426, 'Se requiere una actualización WebSocket.', $target);

            return;
        }

        if (! Handshake::originAllowed($request, $this->allowedOrigins)) {
            $this->reject($clientId, $socket, 403, 'Origen no permitido para el feed en vivo.', $target);

            return;
        }

        $userId = $claims['user_id'];

        // Un usuario inexistente se rechaza ANTES del 101 (falla cerrada).
        $scope = $this->resolveScope($userId);
        if ($scope === null) {
            $this->reject($clientId, $socket, 401, 'Usuario de feed en vivo desconocido.', $target);

            return;
        }

        $client = $this->clients[$clientId];
        $client['admin'] = $scope['admin'];
        $client['role'] = $scope['role'];
        $client['class_ids'] = $scope['class_ids'];
        $client['user_id'] = $userId;
        // Auth is not frozen at handshake: the token expiry and the
        // user's current role/classes are re-checked while connected.
        $client['expires_at'] = $claims['expires_at'];
        $client['connected_at'] = microtime(true);
        $client['scope_checked_at'] = microtime(true);

        // El hello se construye ANTES de enviar el 101: si la base falla,
        // el cliente recibe un 503 plano y nunca un upgrade a medias.
        try {
            $hello = $this->buildHello($client);
            $frame = WsFrame::encode(
                json_encode($hello, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable) {
            DB::purge();
            $this->reject(
                $clientId,
                $socket,
                503,
                'El feed en vivo no puede leer la base de datos; reintenta en unos segundos.',
                $target,
            );

            return;
        }

        $client['handshook'] = true;
        $client['buf'] = $rest;
        $this->clients[$clientId] = $client;

        $accept = Handshake::acceptKey($request['headers']['sec-websocket-key']);
        $this->writeRaw($clientId, Handshake::successResponse($accept));
        $this->write($clientId, $frame);
    }

    /**
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    private function buildHello(array $client): array
    {
        // El hello tiene un techo duro: 20 filas por canal histórico. Si
        // aun así excede 64 KiB, se recortan las más viejas.
        $limit = min(max(1, (int) config('realtime.history_limit')), self::HELLO_MAX_EVENTS);

        $events = \array_values(\array_filter(
            $this->feed->recent($limit),
            fn (array $row): bool => $this->clientSeesTap($client, $row),
        ));

        $recycling = \array_values(\array_filter(
            $this->recycling->recent($limit),
            fn (array $row): bool => $this->clientSeesRecycling($client, (string) $row['type'], $row['payload']),
        ));

        $hello = [
            'type' => 'hello',
            'last_id' => $this->lastEventId,
            'events' => $events,
            'recycling' => $recycling,
        ];

        if ($client['admin']) {
            $hello['pairing'] = $this->pairing->payload();
        }

        return $this->boundedHello($hello);
    }

    /**
     * Recorta desde el extremo más viejo hasta caber en el techo. Los
     * frames conservan su forma: solo se omite historial.
     *
     * @param  array<string, mixed>  $hello
     * @return array<string, mixed>
     */
    private function boundedHello(array $hello): array
    {
        while (\strlen((string) json_encode($hello, JSON_UNESCAPED_UNICODE)) > self::HELLO_MAX_BYTES) {
            if (! empty($hello['events'])) {
                array_shift($hello['events']);

                continue;
            }

            if (! empty($hello['recycling'])) {
                array_shift($hello['recycling']);

                continue;
            }

            break;
        }

        return $hello;
    }

    /** Rechazo HTTP plano; el target se registra con el token enmascarado. */
    private function reject(int $clientId, $socket, int $status, string $reason, string $target = ''): void
    {
        if ($target !== '') {
            Log::debug('realtime: handshake rechazado', [
                'path' => Handshake::redactTarget($target),
                'status' => $status,
            ]);
        }

        @fwrite($socket, Handshake::denialResponse($status, $reason));
        $this->drop($clientId);
    }

    /**
     * Resuelve el rol y los cursos de una conexión. `null` = usuario
     * inexistente (401 en el handshake). Cualquier rol no previsto
     * recibe una lista vacía: no ve nada.
     *
     * @return array{admin: bool, role: string, class_ids: array<int, int>|null}|null
     */
    private function resolveScope(int $userId): ?array
    {
        try {
            $user = DB::table('users')->where('id', $userId)->first(['id', 'role']);
        } catch (\Throwable) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        $scope = ['admin' => false, 'role' => (string) $user->role, 'class_ids' => null];

        if ($scope['role'] === UserRole::Admin->value) {
            $scope['admin'] = true;

            return $scope;
        }

        if ($scope['role'] === UserRole::Teacher->value) {
            try {
                $scope['class_ids'] = DB::table('teacher_class')
                    ->where('user_id', $userId)
                    ->pluck('class_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            } catch (\Throwable) {
                $scope['class_ids'] = [];
            }

            return $scope;
        }

        if ($scope['role'] === UserRole::Station->value) {
            return $scope; // sin filtro
        }

        // Estudiante u otro rol: nunca recibe token, y si llegara uno,
        // no ve ninguna fila (falla cerrada).
        $scope['class_ids'] = [];

        return $scope;
    }

    /** @param  array<string, mixed>  $row */
    private function clientSeesTap(array $client, array $row): bool
    {
        if ($client['role'] === UserRole::Admin->value || $client['role'] === UserRole::Station->value) {
            return true;
        }

        if ($client['role'] !== UserRole::Teacher->value) {
            return false;
        }

        return $row['class_id'] !== null
            && in_array((int) $row['class_id'], $client['class_ids'] ?? [], true);
    }

    /**
     * Tipos sin datos de estudiante que un docente puede recibir aunque
     * el payload no traiga `class_id`. Cualquier otro tipo sin curso es
     * solo para admin/estación: un nombre nunca cruza la pared.
     */
    private const CLASS_FREE_TYPES = ['capture_created', 'leaderboard_updated'];

    /** @param  array<string, mixed>  $payload */
    private function clientSeesRecycling(array $client, string $type, array $payload): bool
    {
        if ($client['role'] === UserRole::Admin->value || $client['role'] === UserRole::Station->value) {
            return true;
        }

        if ($client['role'] !== UserRole::Teacher->value) {
            return false;
        }

        if (! \array_key_exists('class_id', $payload)) {
            // Fail closed: only explicitly safe, student-free types.
            return in_array($type, self::CLASS_FREE_TYPES, true);
        }

        return $payload['class_id'] !== null
            && in_array((int) $payload['class_id'], $client['class_ids'] ?? [], true);
    }

    /** @param  array{fin: bool, opcode: int, payload: string}  $frame */
    private function handleFrame(int $clientId, array $frame): void
    {
        match ($frame['opcode']) {
            WsFrame::OP_CLOSE => $this->drop($clientId),
            WsFrame::OP_PING => $this->write($clientId, WsFrame::encode($frame['payload'], WsFrame::OP_PONG)),
            WsFrame::OP_PONG, WsFrame::OP_TEXT, WsFrame::OP_BINARY, WsFrame::OP_CONT => null,
            default => $this->drop($clientId),
        };
    }

    private function poll(int $pollMs): void
    {
        $interval = $pollMs / 1000.0;
        if ((microtime(true) - $this->lastPollAt) < $interval) {
            return;
        }
        $this->lastPollAt = microtime(true);

        // Auth is a live property, not a handshake souvenir.
        $this->maintainConnections();

        // Cada canal es independiente: una lectura fallida (base
        // bloqueada, tabla ausente, archivo reemplazado) omite SU frame
        // y el servidor sigue sirviendo los demás.
        try {
            $rows = $this->feed->eventsAfter($this->lastEventId);
        } catch (\Throwable) {
            DB::purge();
            $rows = [];
        }

        foreach ($rows as $row) {
            $this->lastEventId = $row['id'];
            $frame = WsFrame::encode(json_encode(['type' => 'tap', 'event' => $row], JSON_UNESCAPED_UNICODE));
            foreach ($this->clients as $clientId => $client) {
                if ($client['handshook'] && $this->clientSeesTap($client, $row)) {
                    $this->write($clientId, $frame);
                }
            }
        }

        // ---- pairing (solo admin) ----------------------------------
        try {
            $signature = $this->pairing->signature();
        } catch (\Throwable) {
            DB::purge();
            $signature = null;
        }

        if ($signature !== null && $signature !== $this->lastPairingSignature) {
            try {
                $payload = $this->pairing->payload();
            } catch (\Throwable) {
                DB::purge();
                $payload = null;
            }

            // Sin payload no se avanza la firma: el próximo sondeo lo
            // reintenta en vez de perder el cambio.
            if ($payload !== null) {
                $this->lastPairingSignature = $signature;
                $frame = WsFrame::encode(json_encode(['type' => 'pairing'] + $payload, JSON_UNESCAPED_UNICODE));
                foreach ($this->clients as $clientId => $client) {
                    if ($client['handshook'] && $client['admin']) {
                        $this->write($clientId, $frame);
                    }
                }
            }
        }

        // ---- recycling ---------------------------------------------
        try {
            $updates = $this->recycling->updatesAfter($this->lastRecyclingId);
        } catch (\Throwable) {
            DB::purge();
            $updates = [];
        }

        foreach ($updates as $update) {
            $this->lastRecyclingId = $update['id'];
            $frame = WsFrame::encode(json_encode(
                ['type' => 'recycling', 'update' => $update],
                JSON_UNESCAPED_UNICODE,
            ));
            foreach ($this->clients as $clientId => $client) {
                if ($client['handshook'] && $this->clientSeesRecycling($client, (string) $update['type'], $update['payload'])) {
                    $this->write($clientId, $frame);
                }
            }
        }

        // ---- feedback (admin + estación) ---------------------------
        try {
            $cues = $this->tapFeedback->after($this->lastFeedbackId);
        } catch (\Throwable) {
            DB::purge();
            $cues = [];
        }

        foreach ($cues as $cue) {
            $this->lastFeedbackId = $cue['id'];
            $frame = WsFrame::encode(json_encode(['type' => 'feedback', 'feedback' => $cue], JSON_UNESCAPED_UNICODE));
            foreach ($this->clients as $clientId => $client) {
                if ($client['handshook']
                    && in_array($client['role'], [UserRole::Admin->value, UserRole::Station->value], true)) {
                    $this->write($clientId, $frame);
                }
            }
        }
    }

    /**
     * Token expiry, hard connection cap, pre-handshake timeout and
     * role/class rescoping. Runs on every poll (the poll is the server's
     * only clock).
     */
    private function maintainConnections(): void
    {
        $now = microtime(true);
        $nowSeconds = time();
        $maxLifetime = max(1, (int) config('realtime.max_connection_seconds', 3600));
        $scopeRefresh = max(1, (int) config('realtime.scope_refresh_seconds', 45));

        foreach (\array_keys($this->clients) as $clientId) {
            $client = $this->clients[$clientId] ?? null;

            if ($client === null) {
                continue;
            }

            if (! $client['handshook']) {
                // Un socket que nunca completa el upgrade no puede
                // ocupar un descriptor indefinidamente.
                if (($now - $client['connected_at']) >= $this->handshakeTimeout) {
                    $this->drop($clientId);
                }

                continue;
            }

            if ($nowSeconds >= $client['expires_at']) {
                $this->close($clientId, 'token_expired');

                continue;
            }

            if (($now - $client['connected_at']) >= $maxLifetime) {
                $this->close($clientId, 'connection_lifetime');

                continue;
            }

            if (($now - $client['scope_checked_at']) < $scopeRefresh) {
                continue;
            }

            $scope = $this->resolveScope($client['user_id']);

            // The account disappeared: close instead of serving stale data.
            if ($scope === null) {
                $this->close($clientId, 'user_gone');

                continue;
            }

            $this->clients[$clientId]['admin'] = $scope['admin'];
            $this->clients[$clientId]['role'] = $scope['role'];
            $this->clients[$clientId]['class_ids'] = $scope['class_ids'];
            $this->clients[$clientId]['scope_checked_at'] = $now;
        }
    }

    /** Cierra el frame y el socket; el motivo nunca lleva datos de alumnos. */
    private function close(int $clientId, string $reason): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        $this->write($clientId, WsFrame::encode($reason, WsFrame::OP_CLOSE));
        $this->drop($clientId);
    }

    /** Encola bytes crudos (101) o un frame ya codificado. */
    private function writeRaw(int $clientId, string $bytes): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        $this->clients[$clientId]['out'] .= $bytes;

        // Un cliente que no drena la cola no puede hacer crecer la
        // memoria del servidor sin límite: se descarta.
        if (\strlen($this->clients[$clientId]['out']) > self::MAX_OUTBOUND_BUFFER) {
            $this->drop($clientId);

            return;
        }

        $this->flush($clientId);
    }

    private function write(int $clientId, string $frame): void
    {
        $this->writeRaw($clientId, $frame);
    }

    /** Escribe lo pendiente; una escritura parcial conserva el resto. */
    private function flush(int $clientId): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        while ($this->clients[$clientId]['out'] !== '') {
            $written = @fwrite(
                $this->clients[$clientId]['sock'],
                $this->clients[$clientId]['out'],
            );

            if ($written === false) {
                $this->drop($clientId);

                return;
            }

            if ($written === 0) {
                if (feof($this->clients[$clientId]['sock'])) {
                    $this->drop($clientId);
                }

                return;
            }

            $this->clients[$clientId]['out'] = substr($this->clients[$clientId]['out'], $written);
        }
    }

    private function drop(int $clientId): void
    {
        if (isset($this->clients[$clientId])) {
            @fclose($this->clients[$clientId]['sock']);
            unset($this->clients[$clientId]);
        }
    }
}
