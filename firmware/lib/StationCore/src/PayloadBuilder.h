/**
 * PayloadBuilder.h — constructs the JSON request bodies the B2B-Core API
 * expects. Pure C++ (ArduinoJson runs on host and device alike), so the
 * exact wire format is unit-tested on the host.
 * PayloadBuilder.h — construye los cuerpos JSON que espera la API de
 * B2B-Core. C++ puro (ArduinoJson funciona igual en host y dispositivo).
 */
#pragma once

#include <string>

#include "CoreTypes.h"

namespace EcoStation {

/**
 * POST /api/v1/events/tap body: {"credential_uid": "...", "client_timestamp": "..."}.
 * TASK-015: a present HceProof adds "hce_nonce" + "hce_mac" (the relayed
 * CHALLENGE transcript B2B-Core verifies); physical taps are unchanged.
 */
std::string buildTapPayload(const std::string& credentialUid,
                             const std::string& clientTimestampIso = "",
                             const HceProof& proof = HceProof());

/**
 * POST /api/v1/admin/cards/pair body: {"credential_uid": "..."}.
 * HCE integration: kind "hce" adds {"credential_kind": "hce"} (HOW the
 * credential was captured — an application-level HCE credential id, not
 * an RF UID) plus, since TASK-015, the key hand-off: "hce_nonce",
 * "hce_mac", "hce_key_wrapped", "hce_key_nonce". Any other/empty kind
 * sends the legacy body byte-for-byte.
 */
std::string buildPairPayload(const std::string& credentialUid,
                             const std::string& credentialKind = "",
                             const HceProof& proof = HceProof());

/** POST /api/v1/recycling/captures/{id}/associate body: {"credential_uid": "..."}
 *  (TASK-008: the bottle-first resolution call — B2B-Core TASK-025.)
 *  TASK-015: a present HceProof adds "hce_nonce" + "hce_mac". */
std::string buildAssociatePayload(const std::string& credentialUid,
                                  const HceProof& proof = HceProof());

}  // namespace EcoStation
