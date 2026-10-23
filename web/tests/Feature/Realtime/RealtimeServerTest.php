<?php

namespace Tests\Feature\Realtime;

use App\Services\Realtime\RealtimeToken;
use App\Services\Realtime\WsFrame;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * El servidor REAL sobre sockets REALES: arranca `php artisan
 * realtime:serve` contra una base de archivo dedicada y conduce el
 * protocolo con un cliente hecho a mano (upgrade → 101 → hello → frames
 * en vivo). Aquí se fija "filas dentro → frames fuera" y la matriz de
 * entrega: estación sin filtrar, docentes por curso, admin todo,
 * pairing solo admin.
 */
class RealtimeServerTest extends TestCase
{
    private const APP_KEY = 'realtime-server-test-key';

    private const STUDENTS = ['Maria González', 'Diego López'];

    /** @var array{process: Process, port: int, db: string}|null */
    private ?array $server = null;

    protected function tearDown(): void
    {
        $this->stopServer();
        parent::tearDown();
    }

    #[Test]
    public function the_feed_answers_hello_with_history_and_broadcasts_new_taps(): void
    {
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $adminId = $this->seedUser($db, 'admin');
        $seededId = $this->seedTap($db, '07:50', 'LIVE TEST One');

        $port = $this->startServer($db);
        $this->assertNotNull($port, 'realtime:serve no arrancó');

        $token = RealtimeToken::issue($adminId, time() + 120);
        [$sock] = $this->upgrade($port, $token);

        $hello = $this->readMessage($sock);
        $this->assertNotNull($hello, 'no llegó el hello');
        $this->assertSame('hello', $hello['type']);
        $this->assertSame($seededId, $hello['last_id']);
        $this->assertContains('LIVE TEST One', array_column($hello['events'], 'student_name'));
        $this->assertArrayHasKey('pairing', $hello);
        $this->assertNull($hello['pairing']['pending']);

        $newId = $this->seedTap($db, '12:02', 'LIVE TEST Two');

        $tap = $this->readMessage($sock);
        $this->assertNotNull($tap, 'no llegó el tap tras una fila nueva');
        $this->assertSame('tap', $tap['type']);
        $this->assertSame($newId, $tap['event']['id']);
        $this->assertSame('LIVE TEST Two', $tap['event']['student_name']);
        $this->assertSame('RECYCLING_DEPOSIT', $tap['event']['type']);
        $this->assertSame('accepted', $tap['event']['feedback']);
        $this->assertSame('12:02', $tap['event']['time']);
        $this->assertSame('Live Reader', $tap['event']['reader_label']);
        $this->assertIsInt($tap['event']['student_id']);
        $this->assertArrayNotHasKey('served', $tap['event']);
        $this->assertArrayNotHasKey('reason', $tap['event']);
        $this->assertArrayNotHasKey('school_id', $tap['event']);

        fclose($sock);
    }

    #[Test]
    public function pairing_state_changes_broadcast_to_connected_desks(): void
    {
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $adminId = $this->seedUser($db, 'admin');
        $port = $this->startServer($db);
        $this->assertNotNull($port, 'realtime:serve no arrancó');

        [$sock] = $this->upgrade($port, RealtimeToken::issue($adminId, time() + 120));

        $hello = $this->readMessage($sock);
        $this->assertNotNull($hello, 'no llegó el hello');
        $this->assertArrayHasKey('pairing', $hello);

        $this->seedPendingPairing($db, 'LIVE PAIR Student');

        $armed = $this->readMessage($sock);
        $this->assertNotNull($armed, 'no llegó el pairing tras armar');
        $this->assertSame('pairing', $armed['type']);
        $this->assertSame('LIVE PAIR Student', $armed['pending']['student_name']);
        $this->assertIsInt($armed['pending']['seconds_left']);
        $this->assertGreaterThan(0, $armed['pending']['seconds_left']);

        $conn = DB::connection('realtime_file');
        $conn->table('pending_pairings')->update([
            'card_id' => $this->seedPairCard($conn, 'LIVEPAIRCARD'),
            'consumed_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $done = $this->readMessage($sock);
        $this->assertNotNull($done, 'no llegó el pairing tras consumir');
        $this->assertSame('pairing', $done['type']);
        $this->assertNull($done['pending']);
        $this->assertSame('LIVEPAIRCARD', $done['last_pairing']['card_uid']);
        $this->assertSame('LIVE PAIR Student', $done['last_pairing']['student_name']);

        fclose($sock);
    }

    #[Test]
    public function pairing_frames_never_reach_teacher_connections(): void
    {
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $teacherId = $this->seedUser($db, 'teacher');
        $classId = $this->seedClass($db, $teacherId);
        $port = $this->startServer($db);
        $this->assertNotNull($port, 'realtime:serve no arrancó');

        [$sock] = $this->upgrade($port, RealtimeToken::issue($teacherId, time() + 120));

        $hello = $this->readMessage($sock);
        $this->assertNotNull($hello, 'no llegó el hello');
        $this->assertArrayNotHasKey('pairing', $hello, 'el hello docente no lleva pairing');

        $this->seedPendingPairing($db, 'SECRET PAIR Student');
        usleep(400000);

        $this->assertNull($this->readMessageIfAny($sock, 0.6), 'un docente nunca debe recibir pairing');

        // Pero sí recibe el tap de un estudiante de su curso.
        $this->seedTap($db, '07:55', 'LIVE TEST Teacher', $classId);
        $tap = $this->readMessage($sock);
        $this->assertNotNull($tap, 'el tap del propio curso debe llegar');
        $this->assertSame('tap', $tap['type']);

        fclose($sock);
    }

    #[Test]
    public function teacher_connections_only_receive_taps_of_their_own_classes(): void
    {
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $teacherId = $this->seedUser($db, 'teacher');
        $adminId = $this->seedUser($db, 'admin');
        $classId = $this->seedClass($db, $teacherId);
        $port = $this->startServer($db);
        $this->assertNotNull($port, 'realtime:serve no arrancó');

        [$teacherSock] = $this->upgrade($port, RealtimeToken::issue($teacherId, time() + 120));
        $this->readMessage($teacherSock);

        [$adminSock] = $this->upgrade($port, RealtimeToken::issue($adminId, time() + 120));
        $this->readMessage($adminSock);

        $outsideId = $this->seedTap($db, '07:50', 'OUT OF SCOPE Student', null);

        $adminTap = $this->readMessage($adminSock);
        $this->assertNotNull($adminTap, 'el admin recibe el tap aunque no tenga curso');
        $this->assertSame($outsideId, $adminTap['event']['id']);

        usleep(400000);
        $this->assertNull(
            $this->readMessageIfAny($teacherSock, 0.6),
            'un docente no ve taps de estudiantes fuera de sus cursos',
        );

        $insideId = $this->seedTap($db, '07:55', 'IN SCOPE Student', $classId);

        $teacherTap = $this->readMessage($teacherSock);
        $this->assertNotNull($teacherTap, 'el tap del propio curso debe llegar');
        $this->assertSame($insideId, $teacherTap['event']['id']);

        $this->assertNotNull($this->readMessage($adminSock), 'el admin también recibe el tap del curso');

        fclose($teacherSock);
        fclose($adminSock);
    }

    #[Test]
    public function station_connections_receive_tap_recycling_and_feedback_but_never_pairing(): void
    {
        // La estación (altavoz) recibe TODO el canal de taps y el de
        // reciclaje sin filtrar —incluido points_awarded con 0 puntos— y
        // los cues de feedback; nunca pairing.
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $stationId = $this->seedUser($db, 'station');
        $teacherId = $this->seedUser($db, 'teacher');
        $classA = $this->seedClass($db, $teacherId, '5° A Live');
        $classB = $this->seedClass($db, $this->seedUser($db, 'teacher'), '5° B Live');

        $port = $this->startServer($db);
        $this->assertNotNull($port, 'realtime:serve no arrancó');

        [$station] = $this->upgrade($port, RealtimeToken::issue($stationId, time() + 120));
        $stationHello = $this->readMessage($station);
        $this->assertSame('hello', $stationHello['type']);
        $this->assertArrayNotHasKey('pairing', $stationHello);

        [$teacher] = $this->upgrade($port, RealtimeToken::issue($teacherId, time() + 120));
        $this->readMessage($teacher); // drain hello

        // Un tap de Diego (5° B) y uno de Maria (5° A).
        $this->seedTap($db, '08:00', 'Diego López', $classB);
        $this->seedTap($db, '08:01', 'Maria González', $classA);

        // Un depósito `other` de 0 puntos (Diego) y uno plástico de 10
        // (Maria), tal como los escriben las transacciones de la Tarea 9.
        $this->seedRecyclingUpdate($db, 'validated', [
            'student_id' => 2, 'class_id' => $classB, 'material_class' => 'other',
        ]);
        $this->seedRecyclingUpdate($db, 'points_awarded', [
            'student_id' => 2, 'class_id' => $classB, 'material_class' => 'other', 'points' => 0, 'new_balance' => 0,
        ]);
        $this->seedRecyclingUpdate($db, 'validated', [
            'student_id' => 1, 'class_id' => $classA, 'material_class' => 'plastic',
        ]);
        $this->seedRecyclingUpdate($db, 'points_awarded', [
            'student_id' => 1, 'class_id' => $classA, 'material_class' => 'plastic', 'points' => 10, 'new_balance' => 10,
        ]);

        // Un cue de feedback (tap respondido sin fila de evento).
        $now = now()->format('Y-m-d H:i:s');
        DB::connection('realtime_file')->table('tap_feedback')->insert([
            'cue' => 'rejected', 'reason' => 'not_found', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $stationFrames = $this->collectMessages($station, 2.0);
        $teacherFrames = $this->collectMessages($teacher, 2.0);

        $stationTypes = array_column($stationFrames, 'type');
        $this->assertContains('tap', $stationTypes);
        $this->assertContains('recycling', $stationTypes);
        $this->assertContains('feedback', $stationTypes);
        $this->assertNotContains('pairing', $stationTypes);

        $stationTaps = array_values(array_filter($stationFrames, fn ($f) => $f['type'] === 'tap'));
        $this->assertCount(2, $stationTaps, 'la estación recibe ambos taps sin filtrar');

        $stationAwards = array_values(array_filter(
            $stationFrames,
            fn ($f) => $f['type'] === 'recycling' && $f['update']['type'] === 'points_awarded',
        ));
        $this->assertCount(2, $stationAwards, 'una recompensa por depósito y ninguna por un clasificado duplicado');
        $this->assertSame([0, 10], array_map(fn ($f) => $f['update']['payload']['points'], $stationAwards));

        // El docente de 5° A solo ve a Maria.
        $teacherTaps = array_values(array_filter($teacherFrames, fn ($f) => $f['type'] === 'tap'));
        $this->assertCount(1, $teacherTaps);
        $this->assertSame('Maria González', $teacherTaps[0]['event']['student_name']);

        $teacherUpdates = array_values(array_filter($teacherFrames, fn ($f) => $f['type'] === 'recycling'));
        $this->assertCount(2, $teacherUpdates, 'solo validated + points_awarded de Maria');
        foreach ($teacherUpdates as $frame) {
            $this->assertSame($classA, $frame['update']['payload']['class_id']);
        }
        $this->assertNotContains('feedback', array_column($teacherFrames, 'type'));

        // Armar un emparejamiento no cruza el cable de la estación.
        $this->seedPendingPairing($db, 'STATION SECRET PAIR');
        usleep(400000);
        $this->assertNull($this->readMessageIfAny($station, 0.6), 'la estación nunca recibe pairing');

        fclose($station);
        fclose($teacher);
    }

    #[Test]
    public function invalid_tokens_are_refused_with_plain_http_401_before_any_framing(): void
    {
        $db = storage_path('framework/testing/realtime-bogus-'.uniqid().'.sqlite');

        $port = $this->startServer($db);
        $this->assertNotNull($port, 'realtime:serve no arrancó');

        [$sock, $head] = $this->upgrade($port, 'totally-bogus-token');

        $body = (string) @fread($sock, 256);

        $this->assertStringStartsWith('HTTP/1.1 401', $head);
        $this->assertStringContainsString('Connection: close', $head);
        $this->assertStringContainsString('feed en vivo', $head.$body);

        fclose($sock);
    }

    #[Test]
    public function a_token_for_an_unknown_user_is_refused_at_handshake(): void
    {
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $port = $this->startServer($db);
        $this->assertNotNull($port, 'realtime:serve no arrancó');

        [$sock, $head] = $this->upgrade($port, RealtimeToken::issue(999999, time() + 120));

        $this->assertStringStartsWith('HTTP/1.1 401', $head);

        fclose($sock);
    }

    #[Test]
    public function recycling_updates_respect_teacher_scope(): void
    {
        // Un docente de 5° A recibe el `validated` de Maria y NO el de
        // Diego (5° B); el admin recibe ambos. El hello lleva el snapshot
        // del canal con el mismo filtro.
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $teacherId = $this->seedUser($db, 'teacher');
        $adminId = $this->seedUser($db, 'admin');
        $classA = $this->seedClass($db, $teacherId, '5° A Live');
        $classB = $this->seedClass($db, $this->seedUser($db, 'teacher'), '5° B Live');

        $this->seedRecyclingUpdate($db, 'validated', ['student_id' => 2, 'class_id' => $classB, 'material_class' => 'metal']);
        $this->seedRecyclingUpdate($db, 'validated', ['student_id' => 1, 'class_id' => $classA, 'material_class' => 'plastic']);

        $port = $this->startServer($db);
        $this->assertNotNull($port, 'realtime:serve no arrancó');

        [$teacher] = $this->upgrade($port, RealtimeToken::issue($teacherId, time() + 120));
        $teacherHello = $this->readMessage($teacher);
        $this->assertSame(['plastic'], array_column(array_column($teacherHello['recycling'], 'payload'), 'material_class'));

        [$admin] = $this->upgrade($port, RealtimeToken::issue($adminId, time() + 120));
        $adminHello = $this->readMessage($admin);
        $this->assertCount(2, $adminHello['recycling']);

        $this->seedRecyclingUpdate($db, 'points_awarded', ['student_id' => 2, 'class_id' => $classB, 'points' => 15]);
        $this->seedRecyclingUpdate($db, 'points_awarded', ['student_id' => 1, 'class_id' => $classA, 'points' => 10]);
        $this->seedRecyclingUpdate($db, 'validated', ['student_id' => 2, 'class_id' => $classB, 'material_class' => 'metal']);
        $this->seedRecyclingUpdate($db, 'validated', ['student_id' => 1, 'class_id' => $classA, 'material_class' => 'plastic']);

        $teacherFrames = $this->collectMessages($teacher, 1.5);
        $teacherTypes = array_column(array_column($teacherFrames, 'update'), 'type');
        $this->assertSame(['points_awarded', 'validated'], $teacherTypes);
        foreach ($teacherFrames as $frame) {
            $this->assertSame($classA, $frame['update']['payload']['class_id']);
        }
        $this->assertSame('plastic', $teacherFrames[1]['update']['payload']['material_class']);

        $adminFrames = $this->collectMessages($admin, 1.5);
        $this->assertCount(4, array_filter($adminFrames, fn ($f) => $f['type'] === 'recycling'));

        fclose($teacher);
        fclose($admin);
    }

    // ------------------------------------------------------------------ helpers

    private function freshFileDatabase(): string
    {
        $db = storage_path('framework/testing/realtime-'.uniqid().'.sqlite');
        @mkdir(dirname($db), 0777, true);
        touch($db);

        config(['database.connections.realtime_file' => [
            'driver' => 'sqlite',
            'database' => $db,
            'prefix' => '',
        ]]);

        $this->artisan('migrate', ['--database' => 'realtime_file', '--force' => true]);

        return $db;
    }

    /** Inserta un tap completo (lector + estudiante + tarjeta + evento). */
    private function seedTap(string $db, string $time, string $studentName, ?int $classId = null): int
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        $readerId = $conn->table('readers')->where('label', 'Live Reader')->value('id');
        if ($readerId === null) {
            $readerId = $conn->table('readers')->insertGetId([
                'label' => 'Live Reader',
                'api_key' => 'realtime-test-key-'.uniqid(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $studentId = $conn->table('students')->insertGetId([
            'name' => $studentName,
            'class_id' => $classId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cardId = $conn->table('cards')->insertGetId([
            'credential_uid' => 'LIVE'.strtoupper(bin2hex(random_bytes(4))),
            'student_id' => $studentId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $conn->table('events')->insertGetId([
            'card_id' => $cardId,
            'reader_id' => $readerId,
            'class_id' => $classId,
            'occurred_at' => now()->toDateString()." {$time}:00",
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedUser(string $db, string $role, ?int $studentId = null): int
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        return (int) $conn->table('users')->insertGetId([
            'name' => 'Realtime '.ucfirst($role),
            'email' => 'realtime-'.uniqid().'@ecostation.test',
            'password' => 'irrelevant',
            'remember_token' => null,
            'role' => $role,
            'must_change_password' => 0,
            'student_id' => $studentId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Un curso asignado al docente por la tabla pivote. */
    private function seedClass(string $db, int $teacherUserId, ?string $name = null): int
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        $classId = (int) $conn->table('classes')->insertGetId([
            'name' => $name ?? 'Scope Test '.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $conn->table('teacher_class')->insert([
            'user_id' => $teacherUserId,
            'class_id' => $classId,
        ]);

        return $classId;
    }

    private function seedRecyclingUpdate(string $db, string $type, array $payload): int
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        return (int) $conn->table('recycling_updates')->insertGetId([
            'type' => $type,
            'payload' => json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedPendingPairing(string $db, string $studentName): void
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        $studentId = $conn->table('students')->insertGetId([
            'name' => $studentName,
            'class_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $conn->table('pending_pairings')->insert([
            'student_id' => $studentId,
            'reader_id' => null,
            'card_id' => null,
            'expires_at' => now()->addSeconds(45)->format('Y-m-d H:i:s'),
            'consumed_at' => null,
            'last_rejected_uid' => null,
            'last_rejected_reason' => null,
            'last_rejected_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedPairCard($conn, string $uid): int
    {
        $now = now()->format('Y-m-d H:i:s');

        return (int) $conn->table('cards')->insertGetId([
            'credential_uid' => $uid,
            'student_id' => $conn->table('students')->where('name', 'LIVE PAIR Student')->value('id'),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function startServer(string $db): ?int
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = random_int(21000, 39000);

            $env = array_merge(getenv() ?: [], [
                'APP_ENV' => 'testing',
                'APP_DEBUG' => 'false',
                'APP_KEY' => self::APP_KEY,
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $db,
                'REALTIME_POLL_MS' => '100',
            ]);

            $process = new Process(
                [PHP_BINARY, 'artisan', 'realtime:serve', '--host=127.0.0.1', sprintf('--port=%d', $port)],
                base_path(),
                $env,
            );
            $process->start();

            for ($i = 0; $i < 60; $i++) {
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe !== false) {
                    fclose($probe);
                    $this->server = ['process' => $process, 'port' => $port, 'db' => $db];

                    return $port;
                }
                if (! $process->isRunning()) {
                    break;
                }
                usleep(100000);
            }

            $diagnostics = $process->getOutput().$process->getErrorOutput();
            @fwrite(STDERR, "realtime:serve falló en el puerto {$port}: {$diagnostics}\n");
            $process->stop(0);
        }

        return null;
    }

    private function stopServer(): void
    {
        if ($this->server === null) {
            return;
        }
        $this->server['process']->stop(0);
        @unlink($this->server['db']);
        $this->server = null;
    }

    /** Realiza el upgrade HTTP; devuelve [socket, cabecera de respuesta]. */
    private function upgrade(int $port, string $token): array
    {
        $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5);
        $this->assertIsResource($sock, "conexión fallida: {$errstr} ({$errno})");
        stream_set_timeout($sock, 5);

        $key = base64_encode(random_bytes(16));
        fwrite($sock,
            'GET /app?token='.urlencode($token)." HTTP/1.1\r\n"
            ."Host: 127.0.0.1:{$port}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n"
        );

        $head = '';
        while (strpos($head, "\r\n\r\n") === false) {
            $line = fgets($sock, 256);
            if ($line === false) {
                break;
            }
            $head .= $line;
        }

        return [$sock, $head];
    }

    private function readMessage($sock): ?array
    {
        return $this->readMessageIfAny($sock, 5.0);
    }

    private function readMessageIfAny($sock, float $budget): ?array
    {
        $buffer = '';
        $deadline = microtime(true) + $budget;

        while (microtime(true) < $deadline) {
            $result = WsFrame::decode($buffer);
            if ($result['frame'] !== null) {
                if ($result['frame']['opcode'] === WsFrame::OP_TEXT) {
                    $message = json_decode($result['frame']['payload'], true);

                    return is_array($message) ? $message : null;
                }
                $buffer = substr($buffer, $result['consumed']);

                continue;
            }
            if ($result['error'] !== null) {
                return null;
            }

            $chunk = @fread($sock, 8192);
            if ($chunk === false || ($chunk === '' && feof($sock))) {
                return null;
            }
            $buffer .= $chunk;
        }

        return null;
    }

    /**
     * Reúne todos los frames de texto dentro del presupuesto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectMessages($sock, float $budget): array
    {
        $messages = [];
        $buffer = '';
        $deadline = microtime(true) + $budget;

        while (microtime(true) < $deadline) {
            $result = WsFrame::decode($buffer);
            if ($result['error'] !== null) {
                break;
            }
            if ($result['frame'] !== null) {
                $buffer = substr($buffer, $result['consumed']);
                if ($result['frame']['opcode'] === WsFrame::OP_TEXT) {
                    $message = json_decode($result['frame']['payload'], true);
                    if (is_array($message)) {
                        $messages[] = $message;
                    }
                }

                continue;
            }

            $chunk = @fread($sock, 8192);
            if ($chunk === false || ($chunk === '' && feof($sock))) {
                break;
            }
            $buffer .= $chunk;
        }

        return $messages;
    }
}
