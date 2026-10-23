/**
 * FeedbackPatterns.h — LED pattern vocabulary + a non-blocking pattern
 * player. Pure C++ with injected time, so the exact blink patterns are
 * host-testable. / Vocabulario de patrones LED + reproductor de patrones
 * no bloqueante. C++ puro con tiempo inyectado: los patrones exactos son
 * testeables en el host.
 *
 * Two channels:
 *   - MODE LED: continuous, loops forever, indicates the current mode.
 *   - EVENT LED: one-shot patterns for tap/pair results, then goes quiet.
 *
 * Pattern table (documented identically in docs/HARDWARE_SETUP.md):
 *   MODE LED  operation : 100 ms ON every 2000 ms (slow heartbeat blip)
 *   MODE LED  pairing   : 100 ms ON, 100 OFF, 100 ON, then 1700 OFF (double blip / 2 s)
 *   MODE LED  connecting: 100 ON / 100 OFF forever (rapid blink)
 *   MODE LED  degraded  : triple blip every 2 s (station alive, subsystem down)
 *   EVENT LED patterns (one-shot):
 *     success          : 1500 ms solid ON
 *     tap rejected     : 2x (200 ON / 200 OFF)
 *     no session (409) : 3x (200 ON / 200 OFF)
 *     already paired   : 4x (200 ON / 200 OFF)
 *     network error    : 5x (120 ON / 120 OFF)
 *     auth error       : 6x (120 ON / 120 OFF)
 *     server error     : long 2000 ms ON
 *     mode switched    : 2x (500 ON / 250 OFF) — operator ack (TASK-003)
 *     mode rejected    : 2x (80 ON / 80 OFF) — wrong password (TASK-003)
 */
#pragma once

#include <cstdint>
#include <vector>

#include "CoreTypes.h"

namespace EcoStation {

struct LedPhase {
    uint32_t durationMs;
    bool on;

    bool operator==(const LedPhase& other) const {
        return durationMs == other.durationMs && on == other.on;
    }
};

/** Continuous (looping) pattern for the MODE LED given the current state. */
std::vector<LedPhase> modeLedPattern(FeedbackKind state);

/** One-shot pattern for the EVENT LED given an event feedback kind. */
std::vector<LedPhase> eventLedPattern(FeedbackKind kind);

/**
 * Plays a pattern without blocking: feed it the current millis() and it
 * reports the LED level. When `loop` is false, the player finishes after
 * the last phase (LED off).
 * Reproduce un patrón sin bloquear: recibe millis() y devuelve el nivel
 * del LED. Sin loop, termina tras la última fase (LED apagado).
 */
class PatternPlayer {
public:
    void start(const std::vector<LedPhase>& pattern, bool loop) {
        pattern_ = pattern;
        loop_ = loop;
        started_ = false;
        finished_ = pattern.empty();
    }

    void stop() {
        pattern_.clear();
        finished_ = true;
    }

    /** Returns the LED level at nowMs; stays safe when no pattern is set. */
    bool step(uint32_t nowMs, bool& levelOut) {
        if (finished_ || pattern_.empty()) {
            levelOut = false;
            return false;  // nothing playing
        }

        if (!started_) {
            started_ = true;
            phaseStart_ = nowMs;
            index_ = 0;
        }

        uint32_t inPhase = nowMs - phaseStart_;  // wrap-safe unsigned math
        while (inPhase >= pattern_[index_].durationMs) {
            inPhase -= pattern_[index_].durationMs;
            index_ = (index_ + 1) % pattern_.size();
            phaseStart_ = nowMs - inPhase;
            if (index_ == 0 && !loop_) {
                finished_ = true;
                levelOut = false;
                return false;  // one-shot completed
            }
        }

        levelOut = pattern_[index_].on;
        return true;
    }

private:
    std::vector<LedPhase> pattern_;
    bool loop_ = false;
    bool started_ = false;
    bool finished_ = true;
    uint32_t phaseStart_ = 0;
    size_t index_ = 0;
};

}  // namespace EcoStation
