/**
 * test_station_config.cpp — the ESP32-CAM station hardware map, pinned.
 *
 * These are the bench-verified numbers (DIAG-CAM 2026-09-07: VersionReg
 * 0x92 stable + raw SPI agree). If anyone moves a pin, this fails at
 * COMPILE time via static_assert — before any flash. Also covers the two
 * station FeedbackKinds and their patterns (host-testable, Arduino-free).
 */
#include <unity.h>

#include "config/esp32cam.h"

// --- authoritative station RC522 wiring (do NOT change: validated) ---------
static_assert(PIN_RC522_SS == 13, "station SS must be GPIO13");
static_assert(PIN_RC522_SCK == 14, "station SCK must be GPIO14");
static_assert(PIN_RC522_MOSI == 15, "station MOSI must be GPIO15");
static_assert(PIN_RC522_MISO == 2, "station MISO must be GPIO2");
// Bench temp: RC522 RST strapped to 3V3 (soft reset only) — GPIO4 is the
// onboard flash LED and must stay undriven. Restore to a free GPIO when
// RST is wired back to the MCU.
static_assert(PIN_RC522_RST < 0, "station RST must stay un-driven (3V3 strap; GPIO4 is the flash LED)");

// --- safety invariants ------------------------------------------------------
static_assert(PIN_CAM_BUZZER < 0, "buzzer must stay absent (no free pin on this bench)");
static_assert(PIN_SHUTTER_BUTTON == 12, "shutter must be GPIO12 (to GND, never 3V3)");

// --- station LED (TASK-010 LED follow-up: the unexpected-LED fix, pinned) ---
// Polarity is a CONFIG value now (inverted-polarity clone boards flip one
// define; genuine AI-Thinker is active-LOW). The pin must stay GPIO33 and
// must never collide with the RC522 bus or the shutter.
static_assert(PIN_STATION_LED == 33, "station LED must be GPIO33 (red LED)");
static_assert(PIN_STATION_LED_ACTIVE_LOW == 0 || PIN_STATION_LED_ACTIVE_LOW == 1,
              "station LED polarity must be 0 (active-HIGH) or 1 (active-LOW)");
static_assert(PIN_STATION_LED != PIN_RC522_SS && PIN_STATION_LED != PIN_RC522_SCK
                  && PIN_STATION_LED != PIN_RC522_MOSI && PIN_STATION_LED != PIN_RC522_MISO
                  && PIN_STATION_LED != PIN_SHUTTER_BUTTON,
              "station LED pin collides with another driven pin");
static_assert(PIN_STATION_LED != 4 && PIN_RC522_SS != 4 && PIN_RC522_SCK != 4
                  && PIN_RC522_MOSI != 4 && PIN_RC522_MISO != 4 && PIN_RC522_RST != 4
                  && PIN_SHUTTER_BUTTON != 4,
              "GPIO4 is the onboard FLASH LED — no firmware pin may drive it");

// --- camera bus spot-checks (AI-Thinker map must survive refactors) ---------
static_assert(Y2_GPIO_NUM == 5, "camera Y2");
static_assert(XCLK_GPIO_NUM == 0, "camera XCLK");
static_assert(SIOD_GPIO_NUM == 26, "camera SIOD");
static_assert(VSYNC_GPIO_NUM == 25, "camera VSYNC");

#include "FeedbackPatterns.h"
static void station_capture_success_has_solid_pattern() {
    auto pattern = EcoStation::eventLedPattern(EcoStation::FeedbackKind::CaptureSuccess);
    TEST_ASSERT_FALSE(pattern.empty());
    TEST_ASSERT_EQUAL_size_t(1, pattern.size());
    TEST_ASSERT_TRUE(pattern[0].on);
}

// TASK-010 (LED follow-up): every idle pattern spends the LONGEST phase
// OFF — a healthy station's LED is dark most of the time (short blips are
// the heartbeat grammar). If a pattern ever becomes mostly-ON, the LED
// would read as "unexpectedly lit" and this test fails at compile-run time.
static void station_idle_patterns_are_mostly_off() {
    auto states = {EcoStation::FeedbackKind::IdleOperation,
                   EcoStation::FeedbackKind::IdlePairing,
                   EcoStation::FeedbackKind::StationDegraded};
    for (auto state : states) {
        auto pattern = EcoStation::modeLedPattern(state);
        uint32_t onMs = 0, offMs = 0;
        for (auto& phase : pattern) {
            (phase.on ? onMs : offMs) += phase.durationMs;
        }
        TEST_ASSERT_TRUE(offMs > onMs);
        TEST_ASSERT_TRUE(offMs >= 1400);   // a quiet gap of ~2 s between blips
    }
}

static void station_degraded_pattern_is_distinct() {
    auto degraded = EcoStation::modeLedPattern(EcoStation::FeedbackKind::StationDegraded);
    auto op = EcoStation::modeLedPattern(EcoStation::FeedbackKind::IdleOperation);
    auto pair = EcoStation::modeLedPattern(EcoStation::FeedbackKind::IdlePairing);
    auto boot = EcoStation::modeLedPattern(EcoStation::FeedbackKind::BootConnecting);
    TEST_ASSERT_FALSE(degraded.empty());
    TEST_ASSERT_TRUE(degraded != op);
    TEST_ASSERT_TRUE(degraded != pair);
    TEST_ASSERT_TRUE(degraded != boot);
}

// Compile-time pins above already run when this file builds; this runtime
// test makes the native suite report them as a case.
static void station_config_compiles() {
    TEST_ASSERT_EQUAL(13, PIN_RC522_SS);
}

void runStationConfigTests() {
    RUN_TEST(station_capture_success_has_solid_pattern);
    RUN_TEST(station_degraded_pattern_is_distinct);
    RUN_TEST(station_idle_patterns_are_mostly_off);
    RUN_TEST(station_config_compiles);
}
