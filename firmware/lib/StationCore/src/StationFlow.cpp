#include "StationFlow.h"

#include <cstdlib>

namespace EcoStation {

StationFlow::StationFlow(const FlowTimings& t) : timings_(t) {}

Expired StationFlow::expire(uint32_t now) {
    // Wrap-safe: unsigned subtraction handles the ~49.7 day millis() wrap.
    Expired out;
    if (pending_ > 0 &&
        (uint32_t)(now - pendingAt_) >= timings_.pendingCaptureTimeoutMs) {
        out.captureId = pending_;
        pending_ = -1;
    }
    if (armed_ > 0 &&
        (uint32_t)(now - armedAt_) >= timings_.armedEventTimeoutMs) {
        out.eventId = armed_;
        armed_ = -1;
        autoDone_ = false;
    }
    return out;
}

TapRoute StationFlow::routeTap(ModeKind mode) const {
    if (mode == ModeKind::Pairing) {
        return TapRoute::Pair;  // pairing never associates a pending bottle
    }
    if (pending_ > 0) {
        return TapRoute::Associate;
    }
    return TapRoute::Tap;
}

ArmResult StationFlow::onTapResult(const TapResult& r, uint32_t now) {
    if (r.outcome != TapOutcome::Success || !r.awaitingClassification ||
        r.eventId <= 0) {
        return ArmResult::NotArmed;
    }
    if (armed_ == r.eventId) {
        // Re-tap of the SAME event (backend dedup, or a retry after a failed
        // upload): keep the arm exactly as it is. Re-arming would reset
        // autoDone_ and re-classify an event that may already be classified.
        return ArmResult::AlreadyArmed;
    }
    const bool hadArm = armed_ > 0;
    armed_ = r.eventId;
    armedAt_ = now;
    autoDone_ = false;
    return hadArm ? ArmResult::Replaced : ArmResult::Armed;
}

bool StationFlow::armFromConsole(const std::string& digits, uint32_t now) {
    // The trigger accepts digits-only strings — including "0" and 60-digit
    // monsters that atol clamps. Event ids are small positive integers;
    // anything else is a typo and must be REFUSED.
    if (digits.size() > 9) {
        return false;
    }
    const long id = std::strtol(digits.c_str(), 0, 10);
    if (id <= 0) {
        return false;
    }
    armed_ = id;
    armedAt_ = now;
    autoDone_ = false;
    return true;
}

bool StationFlow::autoCaptureDue(uint32_t now) {
    if (armed_ <= 0 || autoDone_) {
        return false;
    }
    if ((uint32_t)(now - armedAt_) >= timings_.autoCaptureDelayMs) {
        autoDone_ = true;  // one shot per arm, even if the upload fails
        return true;
    }
    return false;
}

CaptureRoute StationFlow::routeCapture() const {
    return armed_ > 0 ? CaptureRoute::Classify : CaptureRoute::Capture;
}

void StationFlow::onClassifyResult(bool transportOk, int status) {
    if (transportOk && status == 200) {
        armed_ = -1;  // one-shot on SUCCESS only
        autoDone_ = false;
    }
    // Upload failure (Wi-Fi drop, backend down, 5xx): the arm STAYS
    // (retryable with BUTTON/ENTER), bounded by armedEventTimeoutMs.
}

long StationFlow::onCaptureResult(bool transportOk, int status, long captureId,
                                  uint32_t now) {
    if (!transportOk || status != 200 || captureId <= 0) {
        return -1;  // keep any previous pending id so the operator can retry
    }
    const long replaced = (pending_ > 0 && pending_ != captureId) ? pending_
                                                                  : -1;
    pending_ = captureId;
    pendingAt_ = now;
    return replaced;
}

bool StationFlow::onAssociateResult(bool transportOk, int status,
                                    const std::string& body) {
    if (pending_ <= 0) {
        return false;
    }
    if (transportOk && status == 200) {
        pending_ = -1;  // resolved — next tap arms a new card-first
        return true;
    }
    if (transportOk && status == 404) {
        // A 404 is NOT always "capture gone": the backend also 404s on an
        // unknown/inactive CARD, and that must keep the pending capture
        // alive. Clear only when the capture itself is gone/expired or
        // already resolved; any other 404 keeps it.
        const bool captureGone =
            body.find("no_pending_capture") != std::string::npos;
        const bool alreadyResolved =
            body.find("already_associated") != std::string::npos;
        if (captureGone || alreadyResolved) {
            pending_ = -1;
            return true;
        }
    }
    // transport/network errors keep the pending id so the operator can retry
    return false;
}

void StationFlow::reset() {
    pending_ = -1;
    pendingAt_ = 0;
    armed_ = -1;
    armedAt_ = 0;
    autoDone_ = false;
}

long StationFlow::armedEventId() const { return armed_; }

long StationFlow::pendingCaptureId() const { return pending_; }

}  // namespace EcoStation
