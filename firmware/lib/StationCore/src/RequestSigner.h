/**
 * RequestSigner.h — Pulse-HMAC device→backend request signing (reader side).
 * RequestSigner.h — firma HMAC de peticiones dispositivo→backend (lado lector).
 *
 * Pure C++ (no Arduino headers): the whole Authorization VALUE is built
 * here so the native host tests pin it byte-for-byte against B2B-Core's
 * DeviceRequestSigner golden vector (ADR-062 there, ADR-016 here).
 * / C++ puro (sin cabeceras de Arduino): todo el VALOR de Authorization
 * se construye aquí para que las pruebas del host lo fijen byte a byte
 * contra el vector dorado de B2B-Core.
 *
 * Wire format (both sides must match EXACTLY — see ADR-016):
 *   Authorization: Pulse-HMAC <kid>:<nonce>:<sig>
 *   kid = sha256hex(secret)[0:16]            (PUBLIC fingerprint, not a secret)
 *   nonce = 16..64 chars [A-Za-z0-9]         (fresh per request, single-use
 *                                             server-side: replays 401)
 *   sig = HMAC-SHA256(secret, "METHOD\npath[?query]\nnonce\nsha256hex(body)")
 *
 * The secret (READER_API_KEY) NEVER leaves the device as itself anymore —
 * a hotspot capture holds one single-use signature, useless for any other
 * request. Nonce generation (esp_random on device) stays at the call site:
 * this unit only signs. Secrets are never logged — callers log booleans.
 */
#pragma once

#include <cstddef>
#include <cstdint>
#include <string>

namespace EcoStation {
namespace Signer {

// Fingerprint length: hex chars of sha256(secret) sent in clear.
static const size_t KID_LEN = 16;
// HMAC-SHA256 signature length in hex chars.
static const size_t SIG_HEXLEN = 64;

/** Lowercase hex of raw bytes. Never throws. */
std::string toHex(const uint8_t* data, size_t len);

/** sha256hex(bytes) — the body/kid hash primitive. */
std::string sha256Hex(const std::string& data);

/** Public key fingerprint: sha256hex(secret)[0:16]. Safe to send in clear. */
std::string keyFingerprint(const std::string& secret);

/**
 * HMAC-SHA256(secret, "METHOD\npath\nnonce\nsha256hex(body)"), hex.
 * `path` is the origin-form path[?query] placed on the request line —
 * it must be the SAME string the backend routes (getPathInfo + query).
 */
std::string requestSignature(const std::string& secret,
                             const std::string& method,
                             const std::string& path,
                             const std::string& nonce,
                             const std::string& body);

/** The full `Pulse-HMAC kid:nonce:sig` Authorization VALUE. */
std::string authorizationValue(const std::string& secret,
                               const std::string& method,
                               const std::string& path,
                               const std::string& nonce,
                               const std::string& body);

}  // namespace Signer
}  // namespace EcoStation
