/**
 * config/common.h — timing knobs shared by every board target.
 * config/common.h — constantes de tiempo compartidas por todas las placas.
 *
 * All non-blocking, millis()-based. Per-board files (esp32dev.h,
 * esp32cam.h) hold pins; this file holds only numbers both firmwares
 * agree on. / Todo no bloqueante, basado en millis(). Los archivos por
 * placa tienen los pines; aquí solo números comunes.
 */
#pragma once

// --- Network ---------------------------------------------------------------
#define WIFI_CONNECT_TIMEOUT_MS        15000  // initial connect attempt window
#define WIFI_RECONNECT_INTERVAL_MS     10000  // retry cadence after a drop
#define HTTP_TIMEOUT_MS                10000  // per-request HTTP timeout

// --- Pulse service discovery (TASK-013, _pulse._tcp.local) ------------------
// One DNS-SD query blocks ~3 s inside the ESP-IDF core, so discovery runs
// ONLY at boot and on transport failure — never per tap, never on a timer.
#define ECOSTATION_DISCOVERY_BOOT_ATTEMPTS    2      // queries at boot, then the compiled fallback
#define ECOSTATION_REDISCOVER_COOLDOWN_MS     15000  // min gap between failure-triggered re-queries

// --- NFC reader ------------------------------------------------------------
#define RC522_REINIT_INTERVAL_MS  5000  // PCD_Init retry cadence (see Rc522NfcReader)

// --- Presence pipeline -----------------------------------------------------
#define CARD_COOLDOWN_MS               2000   // same-UID re-read debounce
#define BOOT_SERIAL_WAIT_MS            2500   // wait for Serial Monitor attach

// --- Operator console (TASK-003) -------------------------------------------
#define MODE_CONSOLE_MAX_WRONG_ATTEMPTS  3     // wrong passwords before lockout
#define MODE_CONSOLE_LOCKOUT_MS           10000 // console lock after the wrongs
#define SERIAL_LINE_MAX_LENGTH            64    // serial input line cap (chars)
