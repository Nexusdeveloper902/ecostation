/**
 * CardDebouncer.h — re-read suppression, millis()-based, pure C++.
 * CardDebouncer.h — supresión de relecturas, basado en millis(), C++ puro.
 *
 * Rules / Reglas:
 *   - A card RESTING on the antenna (same uid seen poll after poll, never
 *     absent) yields exactly ONE event — it is never re-accepted until it
 *     is removed. (poll() == false → the caller must invoke markAbsent().)
 *   - A DIFFERENT uid is accepted immediately (the previous card must have
 *     left the reader anyway).
 *   - The SAME uid re-presented after an ABSENCE is accepted only once the
 *     cooldown window has elapsed since the last accept (a deliberate
 *     human re-tap), and never before it.
 * / Una tarjeta APOYADA en la antena (mismo uid visto sondeo tras sondeo)
 * produce exactamente UN evento — no se reacepta hasta retirarla. Un uid
 * DISTINTO se acepta de inmediato. El mismo uid re-presentado tras una
 * AUSENCIA se acepta solo pasado el periodo de enfriamiento.
 */
#pragma once

#include <cstdint>
#include <string>

namespace EcoStation {

class CardDebouncer {
public:
    explicit CardDebouncer(uint32_t cooldownMs) : cooldownMs_(cooldownMs) {}

    /**
     * True when this (uid, nowMs) pair must be processed as a real tap.
     * Call ONLY when the reader reports a card present.
     */
    bool shouldProcess(const std::string& uid, uint32_t nowMs) {
        if (uid != lastUid_) {
            // A different card: accept (the previous one had to leave first).
            accept(uid, nowMs);
            return true;
        }
        // Same card as last time: only a deliberate re-tap counts.
        if (!sawAbsentSinceAccept_) {
            return false;  // resting on the antenna — never re-fire
        }
        if (elapsed(nowMs, lastAcceptedMs_) < cooldownMs_) {
            return false;  // too soon after the last accept
        }
        accept(uid, nowMs);
        return true;
    }

    /** Call whenever the reader reports NO card present. */
    void markAbsent() {
        sawAbsentSinceAccept_ = true;
    }

    /** Forget the last tap (e.g. on mode change). */
    void reset() {
        lastUid_.clear();
        lastAcceptedMs_ = 0;
        sawAbsentSinceAccept_ = true;
    }

private:
    static uint32_t elapsed(uint32_t now, uint32_t then) {
        // millis() wraps ~every 49.7 days; unsigned arithmetic is wrap-safe.
        return now - then;
    }

    void accept(const std::string& uid, uint32_t nowMs) {
        lastUid_ = uid;
        lastAcceptedMs_ = nowMs;
        sawAbsentSinceAccept_ = false;
    }

    uint32_t cooldownMs_;
    std::string lastUid_;
    uint32_t lastAcceptedMs_ = 0;
    bool sawAbsentSinceAccept_ = true;
};

}  // namespace EcoStation
