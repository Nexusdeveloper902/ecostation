#include <unity.h>

#include <cstring>
#include <ArduinoJson.h>

#include <string>

#include "PayloadBuilder.h"

// --- tap payload ------------------------------------------------------------

void test_tap_payload_has_required_fields(void) {
    const std::string json = EcoStation::buildTapPayload("A1B2C3D4");

    TEST_ASSERT_NOT_NULL(strstr(json.c_str(), "\"credential_uid\""));
    TEST_ASSERT_NOT_NULL(strstr(json.c_str(), "A1B2C3D4"));
}

void test_tap_payload_omits_timestamp_when_absent(void) {
    const std::string json = EcoStation::buildTapPayload("A1B2C3D4");

    TEST_ASSERT_NULL(strstr(json.c_str(), "client_timestamp"));
}

void test_tap_payload_includes_timestamp_when_given(void) {
    const std::string json =
        EcoStation::buildTapPayload("A1B2C3D4", "2026-09-05T07:58:00-05:00");

    TEST_ASSERT_NOT_NULL(strstr(json.c_str(), "\"client_timestamp\""));
    TEST_ASSERT_NOT_NULL(strstr(json.c_str(), "2026-09-05T07:58:00-05:00"));
}

void test_tap_payload_is_valid_json(void) {
    const std::string json = EcoStation::buildTapPayload("DEADBEEF42");

    // Round-trip through ArduinoJson (the same parser the device uses).
    JsonDocument doc;
    TEST_ASSERT_TRUE(deserializeJson(doc, json) == DeserializationError::Ok);
    const char* uid = doc["credential_uid"];
    TEST_ASSERT_EQUAL_STRING("DEADBEEF42", uid);
}

void test_tap_payload_escapes_special_characters(void) {
    // A UID must never break the JSON envelope.
    const std::string json = EcoStation::buildTapPayload("AB\"CD");

    JsonDocument doc;
    TEST_ASSERT_TRUE(deserializeJson(doc, json) == DeserializationError::Ok);
    const char* uid = doc["credential_uid"];
    TEST_ASSERT_EQUAL_STRING("AB\"CD", uid);
}

// --- pair payload -----------------------------------------------------------

void test_pair_payload_has_credential_uid_only(void) {
    const std::string json = EcoStation::buildPairPayload("1234ABCD");

    JsonDocument doc;
    TEST_ASSERT_TRUE(deserializeJson(doc, json) == DeserializationError::Ok);
    const char* uid = doc["credential_uid"];
    TEST_ASSERT_EQUAL_STRING("1234ABCD", uid);
    TEST_ASSERT_TRUE(doc["client_timestamp"].isNull());
    // Legacy body: no kind field for physical credentials.
    TEST_ASSERT_TRUE(doc["credential_kind"].isNull());
}

void test_pair_payload_carries_hce_kind_for_phone_credentials(void) {
    const std::string json = EcoStation::buildPairPayload("TEST-ANDROID-001", "hce");

    JsonDocument doc;
    TEST_ASSERT_TRUE(deserializeJson(doc, json) == DeserializationError::Ok);
    const char* uid = doc["credential_uid"];
    TEST_ASSERT_EQUAL_STRING("TEST-ANDROID-001", uid);
    const char* kind = doc["credential_kind"];
    TEST_ASSERT_EQUAL_STRING("hce", kind);
}

void test_pair_payload_ignores_non_hce_kind(void) {
    // Anything but "hce" sends the legacy body (old callers unchanged).
    const std::string json = EcoStation::buildPairPayload("A1B2C3D4", "physical");

    JsonDocument doc;
    TEST_ASSERT_TRUE(deserializeJson(doc, json) == DeserializationError::Ok);
    TEST_ASSERT_TRUE(doc["credential_kind"].isNull());
}

// TASK-015 (ADR-018): the relayed proof and the wrapped key.
static EcoStation::HceProof sampleProof(bool withKey) {
    EcoStation::HceProof p;
    p.nonceHex = "0123456789abcdef";
    p.macHex = "ba6d0fbf5106f79257727819d19a17b7e15abc4b8a0d1bfe71cd76090488a295";
    if (withKey) {
        p.keyNonceHex = "000102030405060708090a0b0c0d0e0f";
        p.keyWrappedHex = "c5bb9fe15dff66a8636366dd4e73e0a3146601e3b3c36472961de142a9a914c2";
    }
    return p;
}

void test_tap_payload_relays_the_hce_proof(void) {
    const std::string json = EcoStation::buildTapPayload("PLS-K3Y7V3CT0R5Z", "", sampleProof(false));

    JsonDocument doc;
    TEST_ASSERT_TRUE(deserializeJson(doc, json) == DeserializationError::Ok);
    TEST_ASSERT_EQUAL_STRING("PLS-K3Y7V3CT0R5Z", doc["credential_uid"].as<const char*>());
    TEST_ASSERT_EQUAL_STRING("0123456789abcdef", doc["hce_nonce"].as<const char*>());
    TEST_ASSERT_EQUAL_STRING(sampleProof(false).macHex.c_str(), doc["hce_mac"].as<const char*>());
    // Tap never carries key material, and physical taps stay byte-identical.
    TEST_ASSERT_TRUE(doc["hce_key_wrapped"].isNull());
    TEST_ASSERT_EQUAL_STRING("{\"credential_uid\":\"04A1B2C3\"}",
                             EcoStation::buildTapPayload("04A1B2C3").c_str());
}

void test_tap_payload_never_carries_a_key_even_if_the_proof_has_one(void) {
    const std::string json = EcoStation::buildTapPayload("PLS-K3Y7V3CT0R5Z", "", sampleProof(true));
    TEST_ASSERT_NULL(strstr(json.c_str(), "hce_key"));
}

void test_pair_payload_carries_the_hce_key_hand_off(void) {
    const std::string json = EcoStation::buildPairPayload("PLS-K3Y7V3CT0R5Z", "hce", sampleProof(true));

    JsonDocument doc;
    TEST_ASSERT_TRUE(deserializeJson(doc, json) == DeserializationError::Ok);
    TEST_ASSERT_EQUAL_STRING("hce", doc["credential_kind"].as<const char*>());
    TEST_ASSERT_EQUAL_STRING("0123456789abcdef", doc["hce_nonce"].as<const char*>());
    TEST_ASSERT_EQUAL_STRING(sampleProof(true).macHex.c_str(), doc["hce_mac"].as<const char*>());
    TEST_ASSERT_EQUAL_STRING(sampleProof(true).keyWrappedHex.c_str(), doc["hce_key_wrapped"].as<const char*>());
    TEST_ASSERT_EQUAL_STRING("000102030405060708090a0b0c0d0e0f", doc["hce_key_nonce"].as<const char*>());
}

void test_physical_pair_payload_ignores_any_proof(void) {
    const std::string json = EcoStation::buildPairPayload("A1B2C3D4", "physical", sampleProof(true));
    TEST_ASSERT_EQUAL_STRING("{\"credential_uid\":\"A1B2C3D4\"}", json.c_str());
}

void test_associate_payload_relays_the_hce_proof(void) {
    const std::string json = EcoStation::buildAssociatePayload("PLS-K3Y7V3CT0R5Z", sampleProof(false));
    TEST_ASSERT_NOT_NULL(strstr(json.c_str(), "\"hce_nonce\":\"0123456789abcdef\""));
    TEST_ASSERT_EQUAL_STRING("{\"credential_uid\":\"A1B2C3D4\"}",
                             EcoStation::buildAssociatePayload("A1B2C3D4").c_str());
}

void runPayloadTests() {
    RUN_TEST(test_tap_payload_has_required_fields);
    RUN_TEST(test_tap_payload_omits_timestamp_when_absent);
    RUN_TEST(test_tap_payload_includes_timestamp_when_given);
    RUN_TEST(test_tap_payload_is_valid_json);
    RUN_TEST(test_tap_payload_escapes_special_characters);
    RUN_TEST(test_pair_payload_has_credential_uid_only);
    RUN_TEST(test_pair_payload_carries_hce_kind_for_phone_credentials);
    RUN_TEST(test_pair_payload_ignores_non_hce_kind);
    RUN_TEST(test_tap_payload_relays_the_hce_proof);
    RUN_TEST(test_tap_payload_never_carries_a_key_even_if_the_proof_has_one);
    RUN_TEST(test_pair_payload_carries_the_hce_key_hand_off);
    RUN_TEST(test_physical_pair_payload_ignores_any_proof);
    RUN_TEST(test_associate_payload_relays_the_hce_proof);
}
