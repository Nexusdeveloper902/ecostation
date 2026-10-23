/**
 * test_request_signer.cpp — Pulse-HMAC device→backend signing (ADR-016).
 * test_request_signer.cpp — firma Pulse-HMAC dispositivo→backend (ADR-016).
 *
 * These tests pin the Authorization VALUE byte-for-byte against B2B-Core's
 * DeviceRequestSigner golden vector (DeviceHmacAuthTest there pins the
 * same literals): any drift on either side breaks interop loudly here,
 * instead of silently 401ing fielded readers after a flash.
 * / Fijan el VALOR de Authorization byte a byte contra el vector dorado
 * de B2B-Core: cualquier deriva rompe aquí, no en lectores ya instalados.
 */
#include <unity.h>

#include <string>

#include "RequestSigner.h"

using namespace EcoStation;

// Golden vector (shared with B2B-Core DeviceHmacAuthTest):
// secret "test-secret-000000000000000001", POST /api/v1/events/tap,
// nonce 0123456789abcdef, body {"credential_uid":"QHHIKKOSD6UU"}.
static const char kSecret[] = "test-secret-000000000000000001";
static const char kMethod[] = "POST";
static const char kPath[] = "/api/v1/events/tap";
static const char kNonce[] = "0123456789abcdef";
static const char kBody[] = "{\"credential_uid\":\"QHHIKKOSD6UU\"}";

// sha256hex(body) matches the backend's hash('sha256', $body).
static void body_hash_matches_the_backend() {
    TEST_ASSERT_EQUAL_STRING(
        "56d9e707d82e2d35fce5841b43c860a3b86a49336f04f96023782a52d8f66fb6",
        Signer::sha256Hex(kBody).c_str());
}

// kid = sha256hex(secret)[0:16] — public fingerprint, same as backend.
static void key_fingerprint_matches_the_backend() {
    TEST_ASSERT_EQUAL_STRING("b46b73a6f9444ea4",
                             Signer::keyFingerprint(kSecret).c_str());
}

// HMAC-SHA256(secret, canonical) — the exact backend signature.
static void request_signature_matches_the_backend() {
    TEST_ASSERT_EQUAL_STRING(
        "4ec7367d8b340da73914b806992ac6544cc54c9887777cddc6f827b02566c4f1",
        Signer::requestSignature(kSecret, kMethod, kPath, kNonce, kBody).c_str());
}

// Full header VALUE shape the middleware parses.
static void authorization_value_has_the_exact_wire_shape() {
    TEST_ASSERT_EQUAL_STRING(
        "Pulse-HMAC b46b73a6f9444ea4:0123456789abcdef:"
        "4ec7367d8b340da73914b806992ac6544cc54c9887777cddc6f827b02566c4f1",
        Signer::authorizationValue(kSecret, kMethod, kPath, kNonce, kBody).c_str());
}

// The signature is body-bound: one flipped byte → different signature
// (a captured signature cannot be retargeted at another card/image).
static void signature_changes_when_the_body_changes() {
    const std::string other = "{\"credential_uid\":\"QHHIKKOSD6UV\"}";
    TEST_ASSERT_TRUE(Signer::requestSignature(kSecret, kMethod, kPath, kNonce, other) !=
                     Signer::requestSignature(kSecret, kMethod, kPath, kNonce, kBody));
}

// …and nonce-bound: same body, fresh nonce → different signature
// (every request is single-use; replays 401 server-side).
static void signature_changes_when_the_nonce_changes() {
    TEST_ASSERT_TRUE(Signer::requestSignature(kSecret, kMethod, kPath, "fedcba9876543210", kBody) !=
                     Signer::requestSignature(kSecret, kMethod, kPath, kNonce, kBody));
}

// Binary-safe: multipart JPEG bodies may contain NULs — the hash covers
// the exact bytes, length-delimited, never C-string-truncated.
static void body_hash_is_binary_safe() {
    const std::string withNul("AB\x00" "CD", 5);
    const std::string withoutNul("ABCD", 4);
    TEST_ASSERT_TRUE(Signer::sha256Hex(withNul) != Signer::sha256Hex(withoutNul));
    TEST_ASSERT_EQUAL_UINT32(64, (uint32_t)Signer::sha256Hex(withNul).size());
}

void runRequestSignerTests() {
    RUN_TEST(body_hash_matches_the_backend);
    RUN_TEST(key_fingerprint_matches_the_backend);
    RUN_TEST(request_signature_matches_the_backend);
    RUN_TEST(authorization_value_has_the_exact_wire_shape);
    RUN_TEST(signature_changes_when_the_body_changes);
    RUN_TEST(signature_changes_when_the_nonce_changes);
    RUN_TEST(body_hash_is_binary_safe);
}
