#include "Modes.h"

#include "PayloadBuilder.h"

namespace EcoStation {

// TASK-004: the hints below are the device teaching its own flow. The
// "45 s" in the pairing hint mirrors the B2B-Core default
// (presence.pairing_window_seconds, ADR-020); it is display-only
// guidance — the authoritative window is always the backend's.
// / Las pistas enseñan el flujo del dispositivo. El "45 s" refleja el
// valor por defecto del backend; es solo guía visual.
const char* OperationMode::hint() const {
    return "acerca una tarjeta o teléfono EMPAREJADO y deja la botella";
}

const char* PairingMode::hint() const {
    return "arma primero una sesión en la web (admin, ventana de 45 s),\n"
           "     luego acerca una tarjeta NUEVA o el teléfono con «Vincular este teléfono» abierto";
}

ApiCall OperationMode::onCardTap(const std::string& credentialUid, const std::string&,
                                 const HceProof& proof) {
    // credentialKind intentionally ignored: the tap lookup is
    // credential_uid-only (the HCE phone's RF UID is never identity).
    // TASK-015: the phone's relayed proof rides along — B2B-Core checks it.
    ApiCall call;
    call.type = ApiCallType::Tap;
    call.path = "/api/v1/events/tap";
    call.jsonBody = buildTapPayload(credentialUid, "", proof);
    return call;
}

FeedbackSignal OperationMode::interpret(const TapResult& result) const {
    FeedbackSignal signal;
    signal.detail = result.message;

    switch (result.outcome) {
        case TapOutcome::Success:
            signal.kind = FeedbackKind::TapSuccess;
            signal.detail = result.studentFirstName;
            return signal;
        case TapOutcome::CardNotRecognized:
            signal.kind = FeedbackKind::TapRejected;
            return signal;
        case TapOutcome::AuthFailure:
            signal.kind = FeedbackKind::AuthError;
            return signal;
        case TapOutcome::NetworkError:
            signal.kind = FeedbackKind::NetworkError;
            return signal;
        case TapOutcome::ServerError:
        case TapOutcome::UnknownError:
        case TapOutcome::ValidationError:
        default:
            signal.kind = FeedbackKind::ServerError;
            return signal;
    }
}

ApiCall PairingMode::onCardTap(const std::string& credentialUid,
                               const std::string& credentialKind,
                               const HceProof& proof) {
    ApiCall call;
    call.type = ApiCallType::PairCard;
    call.path = "/api/v1/admin/cards/pair";
    call.jsonBody = buildPairPayload(credentialUid, credentialKind, proof);
    return call;
}

FeedbackSignal PairingMode::interpret(const PairResult& result) const {
    FeedbackSignal signal;
    signal.detail = result.message;

    switch (result.outcome) {
        case PairOutcome::Success:
            signal.kind = FeedbackKind::PairSuccess;
            signal.detail = result.pairedStudentName;
            return signal;
        case PairOutcome::NoActiveSession:
            signal.kind = FeedbackKind::PairNoSession;
            return signal;
        case PairOutcome::AlreadyPaired:
        case PairOutcome::ProofRejected:  // TASK-015: same "rejected, window still armed" signal
            signal.kind = FeedbackKind::PairAlreadyPaired;
            return signal;
        case PairOutcome::AuthFailure:
            signal.kind = FeedbackKind::AuthError;
            return signal;
        case PairOutcome::NetworkError:
            signal.kind = FeedbackKind::NetworkError;
            return signal;
        case PairOutcome::ServerError:
        case PairOutcome::UnknownError:
        case PairOutcome::ValidationError:
        default:
            signal.kind = FeedbackKind::ServerError;
            return signal;
    }
}

}  // namespace EcoStation
