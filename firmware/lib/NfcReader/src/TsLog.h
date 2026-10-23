/**
 * TsLog.h — a Print decorator that prefixes millis() at each line start.
 * TsLog.h — decorador Print que antepone millis() al inicio de línea.
 *
 * Bench request: correlating RF events in time (how long between RATS
 * and SELECT? did that answer arrive before or after removal?). Wraps
 * any Print (Serial in production); the reader's diagnostics sink
 * (Rc522NfcReader log_) is the only consumer — tap-result lines stay
 * bare on purpose (backend round-trips, timestamps add noise there).
 * Single-threaded loop assumed (no interleave possible today).
 * Device-only (Arduino Print) — no host test, by construction.
 */
#pragma once

#include <Arduino.h>

namespace EcoStation {

class TsLog : public Print {
public:
    explicit TsLog(Print& out) : out_(out), fresh_(true) {}

    size_t write(uint8_t c) override {
        if (fresh_) {
            fresh_ = false;
            out_.print('[');
            out_.print(millis());
            out_.print(F("] "));
        }
        const size_t n = out_.write(c);
        if (c == '\n') {
            fresh_ = true;
        }
        return n;
    }

private:
    Print& out_;
    bool fresh_;
};

}  // namespace EcoStation
