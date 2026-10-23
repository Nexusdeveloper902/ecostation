/**
 * StationFlow.h — the station's card-first / bottle-first transaction state.
 * StationFlow.h — el estado de la transacción tarjeta-primero / botella-primero.
 *
 * station.cpp used to keep this state inline (four members, five branches)
 * and no host test could reach it. Lifting it into pure C++ makes both
 * flows testable: the anti-steal windows, the one-shot auto-capture, the
 * associate 404 rules and the mode-switch reset.
 * / station.cpp tenía este estado en línea y ninguna prueba de host podía
 * alcanzarlo. Extraerlo a C++ puro permite probar ambos flujos: las
 * ventanas anti-robo, la auto-captura de un solo disparo, las reglas del
 * 404 al asociar y el reinicio al cambiar de modo.
 *
 * No Arduino headers: the native suite compiles this file directly.
 */
#pragma once

#include <cstdint>
#include <string>

#include "CoreTypes.h"

namespace EcoStation {

/** What a card tap means right now. */
enum class TapRoute {
    Pair,       // PAIRING mode: always pair, even with a pending capture
    Associate,  // a bottle-first capture is pending: close it with this card
    Tap,        // normal path: POST /events/tap
};

/** What a capture means right now. */
enum class CaptureRoute {
    Classify,  // an event is armed: classify for it (card-first)
    Capture,   // bottle-first: upload the image alone
};

/** Result of onTapResult / armFromConsole, for the serial log. */
enum class ArmResult {
    NotArmed,      // the tap did not arm (not success/awaiting, or bad id)
    Armed,         // a new event was armed
    AlreadyArmed,  // same event retapped: keep the current arm untouched
    Replaced,      // a different event replaced the previous arm
};

/** Ids just dropped by expire(), for the caller's log lines. */
struct Expired {
    long captureId = -1;
    long eventId = -1;
};

/** The station's three timing knobs (from config/common.h on the device). */
struct FlowTimings {
    uint32_t pendingCaptureTimeoutMs;
    uint32_t armedEventTimeoutMs;
    uint32_t autoCaptureDelayMs;
};

class StationFlow {
public:
    explicit StationFlow(const FlowTimings& t);

    /** Expire stale transactions; wrap-safe (now - startedAt) >= timeout. */
    Expired expire(uint32_t now);

    /** PAIRING → Pair; pending capture → Associate; else Tap. */
    TapRoute routeTap(ModeKind mode) const;

    /** Arm only on Success && awaitingClassification && eventId > 0. */
    ArmResult onTapResult(const TapResult& r, uint32_t now);

    /**
     * 'e <id>': rejects size() > 9, value <= 0, or a pending bottle-first
     * capture (never two transactions in flight — state left unchanged).
     */
    bool armFromConsole(const std::string& digits, uint32_t now);

    /** True exactly once per arm, at elapsed >= autoCaptureDelayMs. */
    bool autoCaptureDue(uint32_t now);

    /** Armed → Classify, else Capture. */
    CaptureRoute routeCapture() const;

    /**
     * Clears the arm ONLY on transportOk && 200 (retries keep it). Any
     * attempt for the current arm also consumes its automatic attempt,
     * so a failed manual capture never refires the auto photo.
     */
    void onClassifyResult(bool transportOk, int status);

    /**
     * On 200 && captureId > 0 stores the pending capture and returns the
     * id it replaced (or -1). Failures keep the previous pending.
     */
    long onCaptureResult(bool transportOk, int status, long captureId,
                         uint32_t now);

    /**
     * True when the pending capture was cleared. A 200 always clears it.
     * A 404 clears it only when the body says no_pending_capture or
     * already_associated; any other 404 (unknown card) and any transport
     * failure keep it.
     */
    bool onAssociateResult(bool transportOk, int status,
                           const std::string& body);

    /** Mode switch: drop the pending capture and the arm. */
    void reset();

    long armedEventId() const;
    long pendingCaptureId() const;

private:
    FlowTimings timings_;

    long pending_ = -1;
    uint32_t pendingAt_ = 0;

    long armed_ = -1;
    uint32_t armedAt_ = 0;
    bool autoDone_ = false;
};

}  // namespace EcoStation
