/**
 * HceProtocol.h — the Pulse Android-HCE credential protocol (reader side).
 * HceProtocol.h — protocolo de credencial HCE de Android de Pulse (lado lector).
 *
 * Byte-exact mirror of the Android ApduProtocol object and the canonical
 * spec in docs/HCE_PROTOCOL.md: SELECT AID F0010203040506, then an
 * 8-byte CHALLENGE answered with len + credId + HMAC-SHA256(K_cred,
 * credId || nonce) + 9000.
 *
 * TASK-015 (ADR-018): K_cred is PER CREDENTIAL and this reader never
 * holds it. The reader RELAYS the transcript (nonce + MAC) and B2B-Core
 * verifies it with that credential's key. In PAIRING mode only, the
 * ENROLL APDU collects the phone's key once (inside the phone's
 * user-opened enrollment window) and wrapKeyHex() seals it under this
 * reader's API key before it touches Wi-Fi.
 *
 * Pure C++ (no Arduino headers) so the whole application protocol —
 * builders, parsers, the relay proof and the key wrap — is host-testable
 * in the `native` env. The RF/ISO-DEP transport (RATS, I-blocks) stays in
 * Rc522NfcReader, which owns the MFRC522Extended driver.
 * / C++ puro (sin cabeceras de Arduino): todo el protocolo de aplicación
 * es testeable en el host. El transporte RF/ISO-DEP vive en Rc522NfcReader.
 */
#pragma once

#include <cstddef>
#include <cstdint>
#include <string>

namespace EcoStation {
namespace Hce {

// --- Application layer ------------------------------------------------------
// Proprietary 'F' category AID: valid, consistent on both sides, collides
// with nothing production. Changing it breaks every installed phone app.
static const uint8_t AID[] = {0xF0, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06};
static const size_t AID_LEN = sizeof(AID);

static const uint8_t CLA_PROTO = 0x00;  // SELECT AID
static const uint8_t INS_SELECT = 0xA4;
static const uint8_t CLA_AUTH = 0x80;  // prototype auth commands
static const uint8_t INS_CHALLENGE = 0x10;
static const uint8_t INS_ENROLL = 0x20;  // TASK-015: one-time key hand-off

static const size_t NONCE_LEN = 8;    // reader challenge length
static const size_t HMAC_LEN = 32;    // HMAC-SHA256 length
static const size_t MAX_CRED_LEN = 32;  // longest credential id accepted
static const size_t KEY_LEN = 32;     // per-credential HMAC key
static const size_t KEY_NONCE_LEN = 16;  // key-wrap nonce (32 hex chars)

// RC522 FIFO = 64 B: every message below fits one I-block (~57 B INF),
// so no phone-side chaining is ever needed.

/** Builds: 00 A4 04 00 Lc AID (no Le). Returns bytes written (12), 0 when cap is short. */
size_t buildSelectAid(uint8_t* out, size_t cap);

/** Builds: 80 10 00 00 Lc nonce (no Le). Returns bytes written (13), 0 when cap is short. */
size_t buildChallenge(const uint8_t nonce[NONCE_LEN], uint8_t* out, size_t cap);

/**
 * Builds: 80 20 00 00 20 (Le = 32, no data). Returns bytes written (5),
 * 0 when cap is short. Sent ONLY in pairing mode, after CHALLENGE.
 */
size_t buildEnroll(uint8_t* out, size_t cap);

/** True when the response ends in 90 00 (at least the status word present). */
bool isOk(const uint8_t* resp, size_t len);

enum class SelectOutcome {
    Selected,    // "HCE-OK" + 9000: a Pulse HCE phone answered
    UnknownAid,  // 6A82: something else answered (wrong app / AID mismatch)
    Malformed,   // anything else: truncated, wrong payload, wrong SW
};

/** Classifies one SELECT AID response. Never throws. */
SelectOutcome parseSelectResponse(const uint8_t* resp, size_t len);

struct ChallengeResponse {
    bool ok = false;
    std::string credId;  // application-level identity (NOT the RF UID)
    uint8_t mac[HMAC_LEN];
};

/**
 * Parses one CHALLENGE response: len(1) + credId + HMAC(32) + 9000.
 * Returns true (and fills out) only on an exact-length, 9000-terminated
 * frame. The RF UID plays no role here by construction — these parsers
 * do not even take one. / El UID RF no interviene por construcción.
 */
bool parseChallengeResponse(const uint8_t* resp, size_t len, ChallengeResponse& out);

/**
 * True when the phone answered 6A88: it has no provisioned key yet (or
 * lost it — reinstall). The operator fix is enrollment, not a retry.
 */
bool isKeyMissing(const uint8_t* resp, size_t len);

/**
 * Parses one ENROLL response: key(32) + 9000, exact length. Fills key
 * only on success. 6985 = the phone's enrollment window is closed.
 */
bool parseEnrollResponse(const uint8_t* resp, size_t len, uint8_t key[KEY_LEN]);

/** HMAC-SHA256 (compact implementation, no dependencies). */
void hmacSha256(const uint8_t* key, size_t keyLen,
                const uint8_t* msg, size_t msgLen,
                uint8_t out[HMAC_LEN]);

/**
 * Raw SHA-256 of a byte string (same compact implementation the HMAC
 * above is built on). Exposed for the device→backend request signer
 * (RequestSigner), which needs sha256hex(body) — no second SHA-256
 * copy lives anywhere in this repo.
 */
void sha256Bytes(const uint8_t* data, size_t len, uint8_t out[HMAC_LEN]);

/**
 * Wrap a phone key for the pairing request (hex, 64 chars):
 *   key XOR HMAC-SHA256(readerSecret,
 *       "pulse-hce-key-wrap/v1\n" || credId || "\n" || keyNonceHex)
 * keyNonceHex must be fresh per pairing (lowercase hex, 32 chars). The
 * request that carries it is Pulse-HMAC signed, so it is also integrity
 * protected; B2B-Core (HceCredentialAuth) derives the same pad.
 */
std::string wrapKeyHex(const std::string& readerSecret,
                       const std::string& credId,
                       const std::string& keyNonceHex,
                       const uint8_t key[KEY_LEN]);

}  // namespace Hce
}  // namespace EcoStation
