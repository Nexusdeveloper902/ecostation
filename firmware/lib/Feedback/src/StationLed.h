/**
 * StationLed.h — one-pin FeedbackController for the ESP32-CAM station board.
 *
 * The CAM board has a single usable LED (red, GPIO33, active-LOW):
 * GPIO25/26 are camera VSYNC/SIOD and GPIO4 is the onboard flash LED
 * (firmware leaves it alone; RC522 RST is strapped to 3V3). One pin
 * carries both channels by precedence: a one-shot event preempts the
 * continuous state, which resumes when the event finishes. Patterns
 * come from FeedbackPatterns — no new blink vocabulary here.
 */
#pragma once

#include <Arduino.h>

#include "FeedbackController.h"

namespace EcoStation {

class StationLed : public FeedbackController {
public:
    explicit StationLed(uint8_t ledPin, bool activeLow = true)
        : ledPin_(ledPin), activeLow_(activeLow) {}

    void begin() {
        pinMode(ledPin_, OUTPUT);
        drive(false);
    }

    void indicate(FeedbackKind continuousState) override {
        continuousState_ = continuousState;
        if (!eventPlaying_) {
            player_.start(modeLedPattern(continuousState_), /*loop=*/true);
        }
    }

    void showEvent(const FeedbackSignal& signal) override {
        eventPlaying_ = true;
        player_.start(eventLedPattern(signal.kind), /*loop=*/false);
    }

    void tick(uint32_t nowMs) override {
        bool level = false;
        if (player_.step(nowMs, level)) {
            drive(level);
        } else if (eventPlaying_) {
            // One-shot finished: fall back to the continuous state.
            eventPlaying_ = false;
            player_.start(modeLedPattern(continuousState_), /*loop=*/true);
            drive(false);
        }
    }

private:
    void drive(bool on) {
        // Active-LOW (AI-Thinker red LED): ON phase pulls the pin LOW.
        digitalWrite(ledPin_, (on != activeLow_) ? HIGH : LOW);
    }

    uint8_t ledPin_;
    bool activeLow_;
    FeedbackKind continuousState_ = FeedbackKind::BootConnecting;
    PatternPlayer player_;
    bool eventPlaying_ = false;
};

}  // namespace EcoStation
