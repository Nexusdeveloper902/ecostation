#include "PayloadBuilder.h"

#include <ArduinoJson.h>

namespace EcoStation {

namespace {

// TASK-015: the relayed HCE transcript (never verified on the reader).
void addProof(JsonDocument& doc, const HceProof& proof) {
    if (proof.present()) {
        doc["hce_nonce"] = proof.nonceHex;
        doc["hce_mac"] = proof.macHex;
    }
}

}  // namespace

std::string buildTapPayload(const std::string& credentialUid,
                             const std::string& clientTimestampIso,
                             const HceProof& proof) {
    JsonDocument doc;  // ArduinoJson 7 default document

    doc["credential_uid"] = credentialUid;
    if (!clientTimestampIso.empty()) {
        // Optional device clock, ISO 8601. Absent → backend uses server time.
        doc["client_timestamp"] = clientTimestampIso;
    }
    addProof(doc, proof);

    std::string out;
    serializeJson(doc, out);
    return out;
}

std::string buildPairPayload(const std::string& credentialUid,
                             const std::string& credentialKind,
                             const HceProof& proof) {
    JsonDocument doc;

    doc["credential_uid"] = credentialUid;
    if (credentialKind == "hce") {
        // The backend stores this as cards.kind (display/audit only —
        // tap lookup stays credential_uid-only). Omitted otherwise so
        // physical-card readers send the legacy body unchanged.
        doc["credential_kind"] = credentialKind;
        // TASK-015: a phone credential is paired WITH its own key — the
        // backend refuses an hce pairing without this hand-off.
        addProof(doc, proof);
        if (proof.hasKey()) {
            doc["hce_key_wrapped"] = proof.keyWrappedHex;
            doc["hce_key_nonce"] = proof.keyNonceHex;
        }
    }

    std::string out;
    serializeJson(doc, out);
    return out;
}

std::string buildAssociatePayload(const std::string& credentialUid, const HceProof& proof) {
    // Same wire shape as pair (single credential_uid field), different
    // endpoint and lifecycle — kept explicit rather than aliased so the
    // two contracts can evolve independently.
    JsonDocument doc;

    doc["credential_uid"] = credentialUid;
    addProof(doc, proof);

    std::string out;
    serializeJson(doc, out);
    return out;
}

}  // namespace EcoStation
