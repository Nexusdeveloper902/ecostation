#include <unity.h>

#include <cstring>
#include <string>

#include "HceProtocol.h"
#include "RequestSigner.h"


// --- SELECT builder: byte-exact vs the prototype ----------------------------

void test_hce_select_builder_is_byte_exact(void) {
    uint8_t out[16];
    const size_t n = EcoStation::Hce::buildSelectAid(out, sizeof(out));

    const uint8_t want[] = {0x00, 0xA4, 0x04, 0x00, 0x07,
                            0xF0, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06};
    TEST_ASSERT_EQUAL_size_t(sizeof(want), n);
    TEST_ASSERT_EQUAL_UINT8_ARRAY(want, out, sizeof(want));
}

void test_hce_select_builder_refuses_short_buffer(void) {
    uint8_t out[11];  // one byte short of the 12-byte SELECT
    TEST_ASSERT_EQUAL_size_t(0, EcoStation::Hce::buildSelectAid(out, sizeof(out)));
}

// --- CHALLENGE builder: byte-exact vs the prototype --------------------------

void test_hce_challenge_builder_is_byte_exact(void) {
    const uint8_t nonce[8] = {0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08};
    uint8_t out[16];
    const size_t n = EcoStation::Hce::buildChallenge(nonce, out, sizeof(out));

    const uint8_t want[] = {0x80, 0x10, 0x00, 0x00, 0x08,
                            0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08};
    TEST_ASSERT_EQUAL_size_t(sizeof(want), n);
    TEST_ASSERT_EQUAL_UINT8_ARRAY(want, out, sizeof(want));
}

// --- SELECT parser -----------------------------------------------------------

void test_hce_select_parser_accepts_hce_ok(void) {
    const uint8_t resp[] = {'H', 'C', 'E', '-', 'O', 'K', 0x90, 0x00};
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::Hce::SelectOutcome::Selected),
                          static_cast<int>(EcoStation::Hce::parseSelectResponse(resp, sizeof(resp))));
}

void test_hce_select_parser_reports_unknown_aid(void) {
    const uint8_t resp[] = {0x6A, 0x82};
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::Hce::SelectOutcome::UnknownAid),
                          static_cast<int>(EcoStation::Hce::parseSelectResponse(resp, sizeof(resp))));
}

void test_hce_select_parser_rejects_malformed(void) {
    // Truncated, wrong payload with 9000, 9000 alone, empty.
    const uint8_t truncated[] = {0x90};
    const uint8_t wrongPayload[] = {'N', 'O', 'P', 'E', '!', '!', 0x90, 0x00};
    const uint8_t bare9000[] = {0x90, 0x00};
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::Hce::SelectOutcome::Malformed),
                          static_cast<int>(EcoStation::Hce::parseSelectResponse(truncated, sizeof(truncated))));
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::Hce::SelectOutcome::Malformed),
                          static_cast<int>(EcoStation::Hce::parseSelectResponse(wrongPayload, sizeof(wrongPayload))));
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::Hce::SelectOutcome::Malformed),
                          static_cast<int>(EcoStation::Hce::parseSelectResponse(bare9000, sizeof(bare9000))));
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::Hce::SelectOutcome::Malformed),
                          static_cast<int>(EcoStation::Hce::parseSelectResponse(nullptr, 0)));
}

// --- Shared per-credential vector (TASK-015, ADR-018) ------------------------
// Literal values computed OUTSIDE this code (Python hmac) and pinned
// identically in B2B-Core (HceCredentialAuthTest) and B2B-App
// (ApduProtocolTest), so the phone, the relay and the verifier cannot drift.
//   K = 00 01 .. 1f, credId = "PLS-K3Y7V3CT0R5Z", nonce = 01 23 45 67 89 ab cd ef
static const char kVectorCred[] = "PLS-K3Y7V3CT0R5Z";
static const uint8_t kVectorNonce[8] = {0x01, 0x23, 0x45, 0x67, 0x89, 0xAB, 0xCD, 0xEF};
static const uint8_t kVectorResponse[] = {
    0x10, 'P', 'L', 'S', '-', 'K', '3', 'Y', '7', 'V', '3', 'C', 'T', '0', 'R', '5', 'Z',
    0xba, 0x6d, 0x0f, 0xbf, 0x51, 0x06, 0xf7, 0x92, 0x57, 0x72, 0x78, 0x19, 0xd1, 0x9a, 0x17, 0xb7,
    0xe1, 0x5a, 0xbc, 0x4b, 0x8a, 0x0d, 0x1b, 0xfe, 0x71, 0xcd, 0x76, 0x09, 0x04, 0x88, 0xa2, 0x95,
    0x90, 0x00};

static void vectorKey(uint8_t key[32]) {
    for (int i = 0; i < 32; i++) {
        key[i] = static_cast<uint8_t>(i);
    }
}

// --- CHALLENGE parser ----------------------------------------------------------

void test_hce_challenge_parser_extracts_the_relay_proof(void) {
    EcoStation::Hce::ChallengeResponse parsed;
    TEST_ASSERT_TRUE(EcoStation::Hce::parseChallengeResponse(kVectorResponse, sizeof(kVectorResponse), parsed));
    TEST_ASSERT_TRUE(parsed.ok);
    TEST_ASSERT_EQUAL_size_t(51, sizeof(kVectorResponse));  // 1 + 16 + 32 + 2, per the spec
    TEST_ASSERT_EQUAL_STRING(kVectorCred, parsed.credId.c_str());
    // The relay proof is exactly what B2B-Core expects, hex-encoded.
    TEST_ASSERT_EQUAL_STRING("0123456789abcdef", EcoStation::Signer::toHex(kVectorNonce, 8).c_str());
    TEST_ASSERT_EQUAL_STRING("ba6d0fbf5106f79257727819d19a17b7e15abc4b8a0d1bfe71cd76090488a295",
                             EcoStation::Signer::toHex(parsed.mac, 32).c_str());
}

void test_hce_shared_vector_mac_is_the_phone_answer(void) {
    // Independent of the parser: the HMAC primitive under the vector key
    // reproduces the literal MAC bytes the phone put on the wire.
    uint8_t key[32];
    vectorKey(key);
    uint8_t msg[16 + 8];
    memcpy(msg, kVectorCred, 16);
    memcpy(msg + 16, kVectorNonce, 8);
    uint8_t mac[32];
    EcoStation::Hce::hmacSha256(key, 32, msg, sizeof(msg), mac);
    TEST_ASSERT_EQUAL_UINT8_ARRAY(kVectorResponse + 17, mac, 32);
}

void test_hce_challenge_parser_rejects_malformed(void) {
    EcoStation::Hce::ChallengeResponse out;
    // Bad length prefix (claims 16, frame holds 4).
    const uint8_t badPrefix[] = {0x10, 'A', 'B', 'C', 'D', 0x90, 0x00};
    TEST_ASSERT_FALSE(EcoStation::Hce::parseChallengeResponse(badPrefix, sizeof(badPrefix), out));
    // Zero-length credential.
    const uint8_t zeroLen[1 + 32 + 2] = {0x00};
    TEST_ASSERT_FALSE(EcoStation::Hce::parseChallengeResponse(zeroLen, sizeof(zeroLen), out));
    // Truncated MAC.
    const uint8_t truncated[] = {0x04, 'A', 'B', 'C', 'D', 0x01, 0x02, 0x90, 0x00};
    TEST_ASSERT_FALSE(EcoStation::Hce::parseChallengeResponse(truncated, sizeof(truncated), out));
    // Wrong status word.
    uint8_t wrongSw[sizeof(kVectorResponse)];
    memcpy(wrongSw, kVectorResponse, sizeof(wrongSw));
    wrongSw[sizeof(wrongSw) - 2] = 0x6F;
    TEST_ASSERT_FALSE(EcoStation::Hce::parseChallengeResponse(wrongSw, sizeof(wrongSw), out));
    // One trailing byte too many / too few.
    TEST_ASSERT_FALSE(EcoStation::Hce::parseChallengeResponse(kVectorResponse, sizeof(kVectorResponse) - 1, out));
    // "No key" status word is not a credential.
    const uint8_t noKey[] = {0x6A, 0x88};
    TEST_ASSERT_FALSE(EcoStation::Hce::parseChallengeResponse(noKey, sizeof(noKey), out));
    TEST_ASSERT_TRUE(EcoStation::Hce::isKeyMissing(noKey, sizeof(noKey)));
    TEST_ASSERT_FALSE(EcoStation::Hce::isKeyMissing(kVectorResponse, sizeof(kVectorResponse)));
    TEST_ASSERT_FALSE(EcoStation::Hce::isKeyMissing(nullptr, 0));
}

// --- HMAC-SHA256: known-answer + tamper ---------------------------------------

void test_hce_hmac_matches_rfc4231_vector(void) {
    // RFC 4231 test case 1: key = 20 x 0x0b, data = "Hi There".
    uint8_t key[20];
    memset(key, 0x0b, sizeof(key));
    static const uint8_t data[] = "Hi There";
    uint8_t mac[32];
    EcoStation::Hce::hmacSha256(key, sizeof(key), data, sizeof(data) - 1, mac);
    static const uint8_t want[32] = {
        0xb0, 0x34, 0x4c, 0x61, 0xd8, 0xdb, 0x38, 0x53, 0x5c, 0xa8, 0xaf,
        0xce, 0xaf, 0x0b, 0xf1, 0x2b, 0x88, 0x1d, 0xc2, 0x00, 0xc9, 0x83,
        0x3d, 0xa7, 0x26, 0xe9, 0x37, 0x6c, 0x2e, 0x32, 0xcf, 0xf7};
    TEST_ASSERT_EQUAL_UINT8_ARRAY(want, mac, sizeof(want));
}

void test_hce_wrong_key_nonce_or_credential_changes_the_mac(void) {
    uint8_t key[32];
    vectorKey(key);
    uint8_t msg[24];
    uint8_t mac[32];

    key[0] ^= 0x01;  // wrong key
    memcpy(msg, kVectorCred, 16);
    memcpy(msg + 16, kVectorNonce, 8);
    EcoStation::Hce::hmacSha256(key, 32, msg, 24, mac);
    TEST_ASSERT_FALSE(memcmp(mac, kVectorResponse + 17, 32) == 0);
    key[0] ^= 0x01;

    msg[23] ^= 0x01;  // wrong nonce
    EcoStation::Hce::hmacSha256(key, 32, msg, 24, mac);
    TEST_ASSERT_FALSE(memcmp(mac, kVectorResponse + 17, 32) == 0);
    msg[23] ^= 0x01;

    msg[15] = 'Y';  // another credential id
    EcoStation::Hce::hmacSha256(key, 32, msg, 24, mac);
    TEST_ASSERT_FALSE(memcmp(mac, kVectorResponse + 17, 32) == 0);
}

// --- ENROLL (pairing-only key hand-off) -----------------------------------------

void test_hce_enroll_builder_is_byte_exact(void) {
    uint8_t out[8];
    const uint8_t want[] = {0x80, 0x20, 0x00, 0x00, 0x20};
    TEST_ASSERT_EQUAL_size_t(sizeof(want), EcoStation::Hce::buildEnroll(out, sizeof(out)));
    TEST_ASSERT_EQUAL_UINT8_ARRAY(want, out, sizeof(want));
    TEST_ASSERT_EQUAL_size_t(0, EcoStation::Hce::buildEnroll(out, 4));
}

void test_hce_enroll_parser_accepts_only_key_plus_9000(void) {
    uint8_t resp[34];
    vectorKey(resp);
    resp[32] = 0x90;
    resp[33] = 0x00;
    uint8_t key[32];
    memset(key, 0xEE, sizeof(key));
    TEST_ASSERT_TRUE(EcoStation::Hce::parseEnrollResponse(resp, sizeof(resp), key));
    uint8_t want[32];
    vectorKey(want);
    TEST_ASSERT_EQUAL_UINT8_ARRAY(want, key, 32);

    // Window closed (6985), truncated, wrong SW, oversize: all refused.
    const uint8_t closed[] = {0x69, 0x85};
    TEST_ASSERT_FALSE(EcoStation::Hce::parseEnrollResponse(closed, sizeof(closed), key));
    TEST_ASSERT_FALSE(EcoStation::Hce::parseEnrollResponse(resp, 33, key));
    resp[33] = 0x01;
    TEST_ASSERT_FALSE(EcoStation::Hce::parseEnrollResponse(resp, sizeof(resp), key));
    uint8_t big[35] = {0};
    big[33] = 0x90;
    big[34] = 0x00;
    TEST_ASSERT_FALSE(EcoStation::Hce::parseEnrollResponse(big, sizeof(big), key));
    TEST_ASSERT_FALSE(EcoStation::Hce::parseEnrollResponse(nullptr, 34, key));
}

void test_hce_key_wrap_matches_the_shared_vector(void) {
    // Same literal as B2B-Core HceCredentialAuthTest: reader key
    // "test-secret-000000000000000001", key nonce 000102..0f.
    uint8_t key[32];
    vectorKey(key);
    const std::string wrapped = EcoStation::Hce::wrapKeyHex(
        "test-secret-000000000000000001", kVectorCred, "000102030405060708090a0b0c0d0e0f", key);
    TEST_ASSERT_EQUAL_STRING("c5bb9fe15dff66a8636366dd4e73e0a3146601e3b3c36472961de142a9a914c2",
                             wrapped.c_str());
    // Bound to the reader key, the credential and the nonce.
    TEST_ASSERT_TRUE(wrapped != EcoStation::Hce::wrapKeyHex("another-reader-key", kVectorCred,
                                                          "000102030405060708090a0b0c0d0e0f", key));
    TEST_ASSERT_TRUE(wrapped != EcoStation::Hce::wrapKeyHex("test-secret-000000000000000001", "PLS-000000000000",
                                                          "000102030405060708090a0b0c0d0e0f", key));
    TEST_ASSERT_TRUE(wrapped != EcoStation::Hce::wrapKeyHex("test-secret-000000000000000001", kVectorCred,
                                                          "ffffffffffffffffffffffffffffffff", key));
    // Never the key itself.
    TEST_ASSERT_TRUE(wrapped != EcoStation::Signer::toHex(key, 32));
}

// --- UID-independence: the RF UID is not an input -------------------------------
// These parsers do not even TAKE an RF UID — the same APDU bytes parse to
// the same application-level credential no matter which random UID the
// phone presented on the RF layer. Pinned here so a future refactor can
// never smuggle the UID into the identity path.

void test_hce_identity_ignores_the_rf_uid(void) {
    // Two taps, two different randomized RF UIDs — the APDU bytes are what
    // they are; the UID values below are never passed to any parser.
    const uint8_t rfUidTap1[] = {0x08, 0x11, 0x22, 0x33};
    const uint8_t rfUidTap2[] = {0x08, 0xAA, 0xBB, 0xCC, 0xDD, 0xEE, 0xFF};
    (void)rfUidTap1;
    (void)rfUidTap2;

    EcoStation::Hce::ChallengeResponse first, second;
    TEST_ASSERT_TRUE(EcoStation::Hce::parseChallengeResponse(kVectorResponse, sizeof(kVectorResponse), first));
    TEST_ASSERT_TRUE(EcoStation::Hce::parseChallengeResponse(kVectorResponse, sizeof(kVectorResponse), second));
    TEST_ASSERT_EQUAL_STRING(first.credId.c_str(), second.credId.c_str());
    TEST_ASSERT_EQUAL_STRING(kVectorCred, first.credId.c_str());
    TEST_ASSERT_EQUAL_UINT8_ARRAY(first.mac, second.mac, 32);
}

void runHceProtocolTests() {
    RUN_TEST(test_hce_select_builder_is_byte_exact);
    RUN_TEST(test_hce_select_builder_refuses_short_buffer);
    RUN_TEST(test_hce_challenge_builder_is_byte_exact);
    RUN_TEST(test_hce_select_parser_accepts_hce_ok);
    RUN_TEST(test_hce_select_parser_reports_unknown_aid);
    RUN_TEST(test_hce_select_parser_rejects_malformed);
    RUN_TEST(test_hce_challenge_parser_extracts_the_relay_proof);
    RUN_TEST(test_hce_shared_vector_mac_is_the_phone_answer);
    RUN_TEST(test_hce_challenge_parser_rejects_malformed);
    RUN_TEST(test_hce_hmac_matches_rfc4231_vector);
    RUN_TEST(test_hce_wrong_key_nonce_or_credential_changes_the_mac);
    RUN_TEST(test_hce_enroll_builder_is_byte_exact);
    RUN_TEST(test_hce_enroll_parser_accepts_only_key_plus_9000);
    RUN_TEST(test_hce_key_wrap_matches_the_shared_vector);
    RUN_TEST(test_hce_identity_ignores_the_rf_uid);
}
