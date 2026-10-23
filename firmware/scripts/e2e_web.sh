#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# e2e_web.sh — verificación E2E del firmware contra la app real de `web/`.
#
# Qué demuestra (Tarea 10, sin hardware):
#   1. Las cargas EXACTAS que construye el firmware (PayloadBuilder y
#      CapturePayload de lib/StationCore) y sus firmas Pulse-HMAC son
#      aceptadas por los endpoints de dispositivo de `web/` (tap, classify,
#      capture, associate y pair), incluidos los dos flujos completos.
#   2. Las respuestas REALES de cada caso, pasadas por el ResponseParser del
#      firmware, producen exactamente el resultado que el dispositivo usa
#      para retroalimentar al usuario (13/13).
#
# Qué NO demuestra: patrones del LED, asociación Wi-Fi, lecturas del RC522 —
# eso es la lista de verificación de banco (docs/ESTACION.md).
#
# Uso:
#   ./scripts/e2e_web.sh                        # web/ junto al firmware
#   WEB=/ruta/a/web ./scripts/e2e_web.sh
#   E2E_PORT=8188 ECO_PHP=php8.3 ./scripts/e2e_web.sh
#
# Requiere: g++, curl, PHP con GD, y `pio test -e native` ejecutado al menos
# una vez (deja ArduinoJson en .pio/libdeps/native). No toca web/ ni su .env:
# crea una base SQLite desechable y le pasa todo por entorno.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

FW_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
WEB="${WEB:-$FW_ROOT/../web}"
PHP_BIN="${ECO_PHP:-php}"
PORT="${E2E_PORT:-8177}"
BASE_URL="http://127.0.0.1:${PORT}"

WORK="$(mktemp -d)"
SERVER_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
        # artisan serve lanza un `php -S` hijo: se cierra el grupo completo.
        pkill -TERM -P "$SERVER_PID" 2>/dev/null || true
        kill -TERM "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    rm -rf "$WORK"
}
trap cleanup EXIT

echo "== E2E firmware <-> web/ =="

# --- 0. Comprobaciones previas --------------------------------------------
if [ ! -f "$WEB/artisan" ]; then
    echo "web/ no encontrado: $WEB (define WEB=/ruta/a/web)"
    exit 2
fi

if [ ! -f "$WEB/vendor/autoload.php" ]; then
    echo "web/ sin dependencias: ejecuta ./run setup en web/"
    exit 2
fi

WEB="$(cd -- "$WEB" && pwd)"

command -v g++ > /dev/null 2>&1 || { echo "g++ requerido: instálalo para compilar el arnés E2E"; exit 1; }
command -v curl > /dev/null 2>&1 || { echo "curl requerido: instálalo para hablar con web/"; exit 1; }

JSON_INC=""
for d in "$FW_ROOT/.pio/libdeps/native/ArduinoJson/src" \
         "$FW_ROOT/.pio/libdeps/esp32cam/ArduinoJson/src"; do
    if [ -d "$d" ]; then JSON_INC="$d"; break; fi
done
if [ -z "$JSON_INC" ]; then
    echo "ArduinoJson no encontrado en .pio/libdeps: ejecuta primero 'pio test -e native'"
    exit 1
fi

"$PHP_BIN" -r 'exit(extension_loaded("gd") ? 0 : 1);' \
    || { echo "PHP sin GD: no se puede generar la imagen de prueba"; exit 1; }

echo "   web/:   $WEB"
echo "   puerto: $BASE_URL"

# --- 1. Compilar el arnés contra la librería REAL del firmware ------------
echo "== compilando el arnés contra lib/StationCore =="
SRC="$FW_ROOT/lib/StationCore/src"
BIN="$WORK/bin"
HDR="$WORK/hdr"
BODY="$WORK/body"
RESP="$WORK/resp"
PAY="$WORK/payloads"
mkdir -p "$BIN" "$HDR" "$BODY" "$RESP" "$PAY"

g++ -std=gnu++17 -Wall -Wextra \
    -I "$SRC" -I "$JSON_INC" \
    "$FW_ROOT/tools/e2e/build_payloads.cpp" \
    "$SRC/PayloadBuilder.cpp" \
    -o "$BIN/build_payloads"

g++ -std=gnu++17 -Wall -Wextra \
    -I "$SRC" -I "$JSON_INC" \
    "$FW_ROOT/tools/e2e/device_request.cpp" \
    "$SRC/RequestSigner.cpp" \
    "$SRC/HceProtocol.cpp" \
    "$SRC/CapturePayload.cpp" \
    -o "$BIN/device_request"

g++ -std=gnu++17 -Wall -Wextra \
    -I "$SRC" -I "$JSON_INC" \
    "$FW_ROOT/tools/e2e/verify_responses.cpp" \
    "$SRC/ResponseParser.cpp" \
    "$SRC/PayloadBuilder.cpp" \
    "$SRC/Modes.cpp" \
    "$SRC/FeedbackPatterns.cpp" \
    -o "$BIN/verify_responses"

echo "   build_payloads, device_request y verify_responses listos"

# --- 2. Base de datos desechable + servidor -------------------------------
echo "== preparando base de datos desechable =="
DB_FILE="$WORK/e2e.sqlite"
touch "$DB_FILE"

if [ -z "${APP_KEY:-}" ]; then
    if command -v openssl > /dev/null 2>&1; then
        APP_KEY="base64:$(openssl rand -base64 32)"
    else
        APP_KEY="base64:$("$PHP_BIN" -r 'echo base64_encode(random_bytes(32));')"
    fi
fi

export APP_KEY
export APP_URL="$BASE_URL"
export DB_CONNECTION=sqlite
export DB_DATABASE="$DB_FILE"
export DB_FOREIGN_KEYS=true
export CACHE_STORE=database
export SESSION_DRIVER=database
export QUEUE_CONNECTION=sync
export FILESYSTEM_DISK=local
export RECYCLING_CLASSIFIER_DRIVER=stub
export PAIRING_WINDOW_SECONDS=45
# No escribe en web/storage/logs: los errores van a la salida del servidor.
export LOG_CHANNEL=stderr

( cd "$WEB" && "$PHP_BIN" artisan migrate --seed --force ) > "$WORK/migrate.log" 2>&1 \
    || { echo "migrate --seed falló:"; tail -n 30 "$WORK/migrate.log"; exit 1; }
echo "   migrada y sembrada ($DB_FILE)"

# --- 3. Fixtures: clave de la estación, tarjeta conocida, estudiante nuevo -
echo "== extrayendo fixtures =="
FIXTURES="$(cd "$WEB" && "$PHP_BIN" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = \Illuminate\Support\Facades\DB::table("readers")->orderBy("id")->value("api_key");
$uid = \Illuminate\Support\Facades\DB::table("cards")->orderBy("id")->value("credential_uid");
$student = \App\Models\Student::create(["name" => "E2E Estudiante"]);

echo $key, "\n", $uid, "\n", $student->id, "\n";
')"
mapfile -t FIXED <<< "$FIXTURES"
KEY="${FIXED[0]:-}"
KNOWN_UID="${FIXED[1]:-}"
SID="${FIXED[2]:-}"

if [ -z "$KEY" ] || [ -z "$KNOWN_UID" ] || [ -z "$SID" ]; then
    echo "fixtures incompletos: ¿sembró DemoSeeder? (clave='$KEY' uid='$KNOWN_UID' sid='$SID')"
    exit 1
fi
echo "   tarjeta conocida: $KNOWN_UID   estudiante nuevo: #$SID"

FRESH_UID="E2EFRESH00001"
FRESH_UID_2="E2EFRESH00002"

arm_pairing() { # <student_id>
    ( cd "$WEB" && "$PHP_BIN" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(\App\Services\PairingService::class)->arm(\App\Models\Student::findOrFail((int) $argv[1]));
echo "sesión armada\n";
' "$1" )
}

# Imagen real (GD): la regla `image` de Laravel la valida con getimagesize.
"$PHP_BIN" -r 'imagejpeg(imagecreatetruecolor(64, 64), $argv[1]);' "$WORK/bottle.jpg" \
    || { echo "no se pudo generar la imagen de prueba"; exit 1; }

# Cargas byte-idénticas a las del dispositivo.
"$BIN/build_payloads" "$KNOWN_UID" "$FRESH_UID" "$PAY" | sed 's/^/   /'
# Cuerpo de emparejar con la tarjeta YA emparejada (caso 12).
mkdir -p "$WORK/payloads_known"
"$BIN/build_payloads" "$KNOWN_UID" "$KNOWN_UID" "$WORK/payloads_known" > /dev/null

printf '{"credential_uid":"TOTALLYUNKNOWN"}' > "$BODY/unknown_card.json"

# --- 4. Servidor ----------------------------------------------------------
if curl -sf --max-time 2 "$BASE_URL/up" > /dev/null 2>&1; then
    echo "el puerto $PORT ya está en uso: define E2E_PORT con otro puerto"
    exit 1
fi

echo "== arrancando artisan serve en $BASE_URL =="
( cd "$WEB" && exec "$PHP_BIN" artisan serve --host=127.0.0.1 --port="$PORT" --tries=1 --no-reload ) \
    > "$WORK/serve.log" 2>&1 &
SERVER_PID=$!

READY=0
for _ in $(seq 1 30); do
    if curl -sf --max-time 2 "$BASE_URL/up" > /dev/null 2>&1; then
        READY=1
        break
    fi
    kill -0 "$SERVER_PID" 2>/dev/null || break
    sleep 1
done
if [ "$READY" != "1" ]; then
    echo "web/ no respondió en /up tras 30 s:"
    tail -n 30 "$WORK/serve.log"
    exit 1
fi
echo "   servidor sano (ventana de emparejamiento: 45 s)"

# --- 5. Pequeños ayudantes ------------------------------------------------
prepare_json() { # <secret> <path> <src_body> <out_body> <hdr_file> [nonce]
    local secret="$1" path="$2" src="$3" out="$4" hdr="$5" nonce="${6:-}"
    if [ -n "$nonce" ]; then
        "$BIN/device_request" "$secret" json "$path" "$src" "$out" --nonce "$nonce" > "$hdr"
    else
        "$BIN/device_request" "$secret" json "$path" "$src" "$out" > "$hdr"
    fi
}

signed_case() { # <name> <kind> <path> <body> <expected> <hdr_file>
    local name="$1" kind="$2" path="$3" body="$4" expected="$5" hdr="$6"
    local resp="$RESP/$name.json"
    local status
    status="$(curl -sS --max-time 30 -o "$resp" -w '%{http_code}' \
        -X POST "$BASE_URL$path" \
        -H "Authorization: $(sed -n '1p' "$hdr")" \
        -H "Content-Type: $(sed -n '2p' "$hdr")" \
        -H "Accept: application/json" \
        --data-binary "@$body")"
    printf '%s|%s|%s|%s|%s\n' "$name" "$kind" "$status" "$resp" "$expected" >> "$CASES"
    printf '   %-20s HTTP %s\n' "$name" "$status"
}

json_field() { # <json_file> <field>
    "$PHP_BIN" -r '
$data = json_decode((string) file_get_contents($argv[1]), true);
echo is_array($data) && array_key_exists($argv[2], $data) ? $data[$argv[2]] : "";
' "$1" "$2"
}

CASES="$WORK/cases.txt"
: > "$CASES"

# --- 6. Los 13 casos, en orden -------------------------------------------
echo "== casos firmados =="

# 1. Tap de una tarjeta conocida (guarda event_id para clasificar).
prepare_json "$KEY" /api/v1/events/tap "$PAY/tap_payload.json" "$BODY/tap_known.json" "$HDR/tap_known_card.hdr"
signed_case tap_known_card tap /api/v1/events/tap "$BODY/tap_known.json" success_awaiting "$HDR/tap_known_card.hdr"
EVENT_ID="$(json_field "$RESP/tap_known_card.json" event_id)"
if [ -z "$EVENT_ID" ] || [ "$EVENT_ID" -le 0 ]; then
    echo "tap_known_card no devolvió event_id"
    exit 1
fi
echo "   event_id=$EVENT_ID"

# 2. Clasificar la foto para ese evento (tarjeta primero).
"$BIN/device_request" "$KEY" classify "$WORK/bottle.jpg" "$EVENT_ID" "$BODY/classify.json" > "$HDR/classify_card_first.hdr"
signed_case classify_card_first status /api/v1/recycling/classify "$BODY/classify.json" 200 "$HDR/classify_card_first.hdr"

# 3. Tarjeta desconocida.
prepare_json "$KEY" /api/v1/events/tap "$BODY/unknown_card.json" "$BODY/tap_unknown.json" "$HDR/tap_unknown_card.hdr"
signed_case tap_unknown_card tap /api/v1/events/tap "$BODY/tap_unknown.json" not_recognized "$HDR/tap_unknown_card.hdr"

# 4. Clave equivocada.
prepare_json "wrong-secret" /api/v1/events/tap "$PAY/tap_payload.json" "$BODY/tap_bad_key.json" "$HDR/tap_bad_key.hdr"
signed_case tap_bad_key tap /api/v1/events/tap "$BODY/tap_bad_key.json" auth "$HDR/tap_bad_key.hdr"

# 5. Repetición exacta de la cabecera y el cuerpo del caso 1 (mismo nonce).
signed_case tap_replayed_nonce tap /api/v1/events/tap "$BODY/tap_known.json" auth "$HDR/tap_known_card.hdr"

# 6. Captura «botella primero» (guarda capture_id).
"$BIN/device_request" "$KEY" capture "$WORK/bottle.jpg" "$BODY/capture.json" > "$HDR/capture_bottle_first.hdr"
signed_case capture_bottle_first capture /api/v1/recycling/capture "$BODY/capture.json" capture "$HDR/capture_bottle_first.hdr"
CAPTURE_ID="$(json_field "$RESP/capture_bottle_first.json" capture_id)"
if [ -z "$CAPTURE_ID" ] || [ "$CAPTURE_ID" -le 0 ]; then
    echo "capture_bottle_first no devolvió capture_id"
    exit 1
fi
echo "   capture_id=$CAPTURE_ID"

# 7. Asociar la captura con la tarjeta conocida.
prepare_json "$KEY" "/api/v1/recycling/captures/$CAPTURE_ID/associate" \
    "$PAY/associate_payload.json" "$BODY/associate.json" "$HDR/associate_known.hdr"
signed_case associate_known status "/api/v1/recycling/captures/$CAPTURE_ID/associate" \
    "$BODY/associate.json" 200 "$HDR/associate_known.hdr"

# 8. Repetir la asociación con un nonce nuevo: la captura ya no está pendiente.
prepare_json "$KEY" "/api/v1/recycling/captures/$CAPTURE_ID/associate" \
    "$PAY/associate_payload.json" "$BODY/associate_again.json" "$HDR/associate_again.hdr"
signed_case associate_again status "/api/v1/recycling/captures/$CAPTURE_ID/associate" \
    "$BODY/associate_again.json" 404 "$HDR/associate_again.hdr"

# 9. Emparejar sin sesión armada.
prepare_json "$KEY" /api/v1/admin/cards/pair "$PAY/pair_payload.json" "$BODY/pair_no_session.json" "$HDR/pair_no_session.hdr"
signed_case pair_no_session pair /api/v1/admin/cards/pair "$BODY/pair_no_session.json" no_session "$HDR/pair_no_session.hdr"

# 10. Sesión armada + tarjeta nueva: empareja.
arm_pairing "$SID" | sed 's/^/   /'
prepare_json "$KEY" /api/v1/admin/cards/pair "$PAY/pair_payload.json" "$BODY/pair_success.json" "$HDR/pair_success.hdr"
signed_case pair_success pair /api/v1/admin/cards/pair "$BODY/pair_success.json" success "$HDR/pair_success.hdr"

# 11. La sesión se consumió: otra tarjeta nueva recibe 409.
printf '{"credential_uid":"%s"}' "$FRESH_UID_2" > "$BODY/pair_consumed_src.json"
prepare_json "$KEY" /api/v1/admin/cards/pair "$BODY/pair_consumed_src.json" "$BODY/pair_consumed.json" "$HDR/pair_consumed.hdr"
signed_case pair_consumed pair /api/v1/admin/cards/pair "$BODY/pair_consumed.json" no_session "$HDR/pair_consumed.hdr"

# 12. Sesión nueva + tarjeta YA emparejada: 422, sin reasignar.
arm_pairing "$SID" | sed 's/^/   /'
prepare_json "$KEY" /api/v1/admin/cards/pair "$WORK/payloads_known/pair_payload.json" \
    "$BODY/pair_already_paired.json" "$HDR/pair_already_paired.hdr"
signed_case pair_already_paired pair /api/v1/admin/cards/pair "$BODY/pair_already_paired.json" already_paired "$HDR/pair_already_paired.hdr"

# 13. Clave equivocada al emparejar.
prepare_json "wrong-secret" /api/v1/admin/cards/pair "$PAY/pair_payload.json" \
    "$BODY/pair_bad_key.json" "$HDR/pair_bad_key.hdr"
signed_case pair_bad_key pair /api/v1/admin/cards/pair "$BODY/pair_bad_key.json" auth "$HDR/pair_bad_key.hdr"

# --- 7. Veredictos del parser REAL del firmware ---------------------------
echo "== veredictos del ResponseParser sobre respuestas reales =="
RESULT=0
"$BIN/verify_responses" "$CASES" || RESULT=$?

if [ "$RESULT" = "0" ]; then
    echo "RESULTADO: E2E OK"
else
    echo "RESULTADO: E2E FALLÓ"
fi
exit "$RESULT"
