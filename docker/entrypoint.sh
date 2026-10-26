#!/usr/bin/env bash
#
# EcoStation container entrypoint.
#
# One image, three processes: the HTTP server, the realtime feed and the
# scheduler. The SQLite database and the generated APP_KEY live in /data
# (a named volume); photos live in the storage volume.
set -Eeuo pipefail

cd /var/www/html

DATA_DIR="${DATA_DIR:-/data}"
DB_FILE="${DB_DATABASE:-$DATA_DIR/ecostation.sqlite}"

mkdir -p \
    "$DATA_DIR" \
    storage/app/private \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# APP_KEY encrypts the HCE credential secrets. If it changes, every paired
# phone must be paired again. Persist it next to the database so `down` and
# `up` keep working; `down -v` intentionally resets both.
if [ -z "${APP_KEY:-}" ]; then
    if [ -s "$DATA_DIR/app.key" ]; then
        APP_KEY="$(cat "$DATA_DIR/app.key")"
    else
        APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        umask 077
        printf '%s' "$APP_KEY" > "$DATA_DIR/app.key"
    fi
    export APP_KEY
fi

export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="$DB_FILE"
export CACHE_STORE="${CACHE_STORE:-database}"
export SESSION_DRIVER="${SESSION_DRIVER:-database}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"

FRESH=0
if [ ! -s "$DB_FILE" ]; then
    touch "$DB_FILE"
    FRESH=1
fi

echo "[entrypoint] migrando la base de datos…"
php artisan migrate --force

if [ "$FRESH" = "1" ]; then
    echo "[entrypoint] primera base de datos: sembrando cuentas y estación demo…"
    php artisan db:seed --force

    if [ "${ECOSTATION_DEMO_ACTIVITY:-1}" = "1" ]; then
        echo "[entrypoint] sembrando actividad de demostración…"
        php artisan db:seed --class=DemoActivitySeeder --force || true
    fi
fi

# A previous image may have cached config; never let a stale cache hide the
# environment of this container.
php artisan optimize:clear >/dev/null 2>&1 || true

PIDS=()
cleanup() {
    trap - EXIT INT TERM
    for pid in "${PIDS[@]:-}"; do
        [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null && kill "$pid" 2>/dev/null || true
    done
    wait 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "[entrypoint] escuchando en 0.0.0.0:8000 (web), 0.0.0.0:${REALTIME_PORT:-8081} (feed)"
php artisan serve --host=0.0.0.0 --port=8000 &
PIDS+=("$!")

php artisan realtime:serve --host=0.0.0.0 --port="${REALTIME_PORT:-8081}" &
PIDS+=("$!")

php artisan schedule:work &
PIDS+=("$!")

STATUS=0
wait -n || STATUS=$?
echo "[entrypoint] un proceso terminó; cerrando el stack (status=$STATUS)" >&2
cleanup
exit "$STATUS"
