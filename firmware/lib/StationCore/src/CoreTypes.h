/**
 * CoreTypes.h — shared value types of the reader firmware.
 * CoreTypes.h — tipos compartidos del firmware del lector.
 *
 * StationCore is deliberately FREE of Arduino headers so every rule in
 * this library can be unit-tested on the host (PlatformIO native env)
 * and reused by the station image.
 * StationCore no incluye cabeceras de Arduino para que toda esta lógica
 * sea testeable en el host y reutilizable por la imagen de la estación.
 */
#pragma once

#include <cstdint>
#include <string>

namespace EcoStation {

// ---------------------------------------------------------------------------
// Operating modes / Modos de operación
// ---------------------------------------------------------------------------
enum class ModeKind {
    Operation,  // normal use: tap a paired card → presence event / uso normal
    Pairing,    // associate a fresh card's UID with a student / emparejar
};

// ---------------------------------------------------------------------------
// HCE relay proof (TASK-015, ADR-018) / Prueba HCE retransmitida
// ---------------------------------------------------------------------------
// What an HCE tap carries to the backend. The reader does NOT verify the
// phone (it holds no HCE key): it relays its own challenge nonce and the
// phone's MAC, and B2B-Core checks them against that credential's key.
// In PAIRING mode the phone's key rides along too — never raw, always
// wrapped under this reader's API key (Hce::wrapKeyHex). Empty for
// physical cards, so their payloads stay byte-identical.
// / Lo que lleva un toque HCE al backend: el lector NO verifica, retransmite.
struct HceProof {
    std::string nonceHex;       // 16 hex — the reader's CHALLENGE nonce
    std::string macHex;         // 64 hex — the phone's HMAC, unverified here
    std::string keyWrappedHex;  // 64 hex — pairing only
    std::string keyNonceHex;    // 32 hex — pairing only (fresh per pairing)

    bool present() const { return !nonceHex.empty() && !macHex.empty(); }
    bool hasKey() const { return !keyWrappedHex.empty() && !keyNonceHex.empty(); }
    void clear() {
        nonceHex.clear();
        macHex.clear();
        keyWrappedHex.clear();
        keyNonceHex.clear();
    }
};

// ---------------------------------------------------------------------------
// API calls the firmware can make / Llamadas que el firmware puede hacer
// ---------------------------------------------------------------------------
enum class ApiCallType {
    Tap,       // POST /api/v1/events/tap          (operation mode)
    PairCard,  // POST /api/v1/admin/cards/pair     (pairing mode)
};

struct ApiCall {
    ApiCallType type = ApiCallType::Tap;
    std::string path;      // URL path, e.g. "/api/v1/events/tap"
    std::string jsonBody;  // serialized JSON request payload
};

// ---------------------------------------------------------------------------
// Tap outcomes (operation mode) / Resultados de tap (modo operación)
// ---------------------------------------------------------------------------
enum class TapOutcome {
    Success,           // 200 {"status":"ok"}
    CardNotRecognized, // 404 (unknown card, or card not active); 403 (phone proof refused)
    AuthFailure,       // 401 missing/invalid reader key
    ValidationError,   // 422 (malformed payload — should not happen in practice)
    ServerError,       // any other 5xx
    NetworkError,      // transport-level failure (timeout, DNS, connection)
    UnknownError       // anything unrecognized — device must stay responsive
};

struct TapResult {
    TapOutcome outcome = TapOutcome::UnknownError;
    std::string studentFirstName;    // on success — device feedback
    std::string eventType;           // e.g. CLASS_ATTENDANCE
    int32_t eventId = -1;            // backend event id
    bool awaitingClassification = false;  // recycling readers: next_step
    std::string message;             // raw server message, for the serial log
};

// ---------------------------------------------------------------------------
// Pairing outcomes (pairing mode) / Resultados de emparejamiento (modo emparejar)
// ---------------------------------------------------------------------------
enum class PairOutcome {
    Success,          // 200 {"status":"ok"} — card linked to student
    NoActiveSession,  // 409 — no armed pairing session
    AlreadyPaired,    // 422 — credential_uid already linked to a card
    ProofRejected,    // 403 — phone's key/proof did not verify (TASK-015)
    AuthFailure,      // 401 missing/invalid reader key
    ValidationError,  // other 422 (malformed body)
    ServerError,      // any other 5xx
    NetworkError,     // transport-level failure
    UnknownError      // anything unrecognized — device must stay responsive
};

struct PairResult {
    PairOutcome outcome = PairOutcome::UnknownError;
    std::string pairedStudentName;  // on success — show/log the student
    int32_t studentId = -1;
    std::string message;            // raw server message, for the serial log
};

// ---------------------------------------------------------------------------
// Feedback signals / Señales de retroalimentación
// ---------------------------------------------------------------------------
// The single vocabulary the FeedbackController understands. Mode logic and
// networking NEVER touch LEDs directly — they emit FeedbackSignals.
// Vocabulario único que entiende el FeedbackController. La lógica de modos y
// la red NUNCA tocan LEDs directamente — emiten FeedbackSignals.
enum class FeedbackKind {
    // continuous states / estados continuos
    BootConnecting,   // boot + Wi-Fi association in progress
    IdleOperation,    // idle in operation mode
    IdlePairing,      // idle in pairing mode
    StationDegraded,  // station alive but a subsystem is down (camera/NFC/net)
    // operation-mode events / eventos del modo operación
    TapSuccess,       // presence event logged
    TapRejected,      // 404 — card not recognized / not active
    CaptureSuccess,   // station: image captured + uploaded (HTTP 200)
    // pairing-mode events / eventos del modo emparejar
    PairSuccess,      // card paired to the student
    PairNoSession,    // 409 — no pairing session active
    PairAlreadyPaired,// 422 — card already linked
    // operator console events / eventos de la consola del operador
    ModeSwitched,     // correct mode password — mode toggled (TASK-003)
    ModeRejected,     // wrong mode password (TASK-003)
    // cross-mode failures / fallos comunes
    AuthError,        // 401 — reader key rejected
    NetworkError,     // transport failure
    ServerError       // unexpected backend response
};

struct FeedbackSignal {
    FeedbackKind kind = FeedbackKind::TapSuccess;
    std::string detail;  // human-facing extra (student name, message) → log
};

}  // namespace EcoStation
