/**
 * config/esp32cam.h — STATION board (env `esp32cam`): AI-Thinker ESP32-CAM
 * (OV3660) with camera + RC522 aboard. Board pins (RC522, LED) and the
 * station-only parts live in this one file.
 *
 *   RC522:  SDA/SS → 13 | SCK → 14 | MOSI → 15 | MISO → 2 | RST → 3V3
 *           (bench temp: RST strapped to 3V3, soft reset only —
 *           PIN_RC522_RST stays -1 so the firmware never drives a pin
 *           for it. SCK/MOSI/MISO/SS double as the SD-slot pins; the
 *           SD card is intentionally unused — never init SD_MMC/SD.)
 *   GPIO4:  ONBOARD FLASH LED — NEVER drive it (firmware leaves it alone).
 *   PSRAM:  GPIO16/17 RESERVED — never use 16 for RC522 RST.
 *   Buzzer: ABSENT (-1). No free pin on this bench — re-enable ONLY on a
 *           genuinely free pin.
 *   LEDs:   single red LED on GPIO33 (active-LOW on AI-Thinker). GPIO25/26
 *           are camera VSYNC/SIOD — not LEDs on this board.
 *   Button: shutter between GPIO12 and GND (internal pull-up, active-LOW).
 *           NEVER tie GPIO12 HIGH — MTDI strapping (flash voltage).
 */
#pragma once

#include "config/common.h"

// --- RC522 (same SPI numbers as the reader bench) --------------------------
#define PIN_RC522_SCK   14
#define PIN_RC522_MISO  2
#define PIN_RC522_MOSI  15
#define PIN_RC522_SS    13
// Bench temp: RC522 RST strapped to 3V3 (soft reset only) — GPIO4 is the
// onboard flash LED and must not be driven. The MFRC522 library treats
// 255 (UINT8_MAX, UNUSED_PIN) as "no RST pin"; (uint8_t)-1 is exactly that.
#define PIN_RC522_RST   -1

// --- One LED ----------------------------------------------------------------
// TASK-010 (LED follow-up): polarity is a CONFIG value, not a buried
// assumption. AI-Thinker genuine boards: red LED on GPIO33 is ACTIVE-LOW
// (ON = pin LOW). Some clone boards invert it — on those the LED sits
// SOLID ON with the default and every pattern looks inverted. Flip this
// one define to 0 on such a board; nothing else changes.
#define PIN_STATION_LED              33
#define PIN_STATION_LED_ACTIVE_LOW   1


// --- Station feedback: one LED (above), no buzzer ---------------
#define PIN_CAM_BUZZER    -1    // no free pin on this bench (GPIO4 is the flash LED)

// --- LED diagnostics quick reference (full table in docs/HARDWARE_SETUP.md) -
//   white flash LED solid ON → pre-2026-09-07 build or RC522 RST still
//       wired to GPIO4 (it must be strapped to 3V3);
//   red LED SOLID ON → the board is likely an inverted-polarity clone →
//       set PIN_STATION_LED_ACTIVE_LOW to 0;
//   red LED short blips (1×/2×/3× per 2 s) → NORMAL heartbeat grammar,
//       not a fault (1 = operating, 2 = pairing, 3 = degraded).

// --- Shutter button ---------------------------------------------------------
#define PIN_SHUTTER_BUTTON  12   // active-LOW to GND — confirm wiring
#define SHUTTER_DEBOUNCE_MS 50   // contact-settle window per press

// --- Recycling transaction windows (anti-steal timeouts, relaxed) -----------
// Bottle-first: BUTTON capture → tap window. Card-first: tap → auto-capture
// after the delay below (BUTTON/ENTER captures immediately). Shorter than
// the backend's 300 s hold so a stray next person can't claim your bottle,
// but long enough to tap relaxed. Wrap-safe millis() compares.
#define PENDING_CAPTURE_TIMEOUT_MS 90000  // 90 s to tap after bottle capture
#define ARMED_EVENT_TIMEOUT_MS     90000  // 90 s outer limit for a card-first arm
#define CARD_FIRST_AUTO_CAPTURE_DELAY_MS 5000  // tap → wait → auto photo (place the bottle)

// --- Camera recovery: init is heavy (sensor + PSRAM frame buffers), so a
// failed camera retries on a slower cadence than the RC522. Runtime death
// (fb_get keeps failing after a working boot) is detected by consecutive
// capture failures: CAMERA_FAILURES_BEFORE_REINIT in a row flips the
// healthy flag so this same re-init cadence engages. millis()-based,
// non-blocking; the station stays alive meanwhile.
#define CAMERA_REINIT_INTERVAL_MS 30000
#define CAMERA_FAILURES_BEFORE_REINIT 3  // consecutive fb_get failures before re-init

// --- Camera bus pin map (AI-Thinker, from the verified reference) ----------
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27

#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5

#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22
