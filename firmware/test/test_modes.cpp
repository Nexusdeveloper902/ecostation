#include <unity.h>

#include <cstring>

#include <string>

#include "Modes.h"

// --- mode strategy: which call a tap becomes --------------------------------

void test_operation_mode_routes_tap_to_events_endpoint(void) {
    EcoStation::OperationMode mode;
    EcoStation::ApiCall call = mode.onCardTap("A1B2C3D4");

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::ApiCallType::Tap),
                          static_cast<int>(call.type));
    TEST_ASSERT_EQUAL_STRING("/api/v1/events/tap", call.path.c_str());
    TEST_ASSERT_NOT_NULL(strstr(call.jsonBody.c_str(), "\"credential_uid\""));
    TEST_ASSERT_NOT_NULL(strstr(call.jsonBody.c_str(), "A1B2C3D4"));
    TEST_ASSERT_NULL(strstr(call.jsonBody.c_str(), "client_timestamp"));
}

void test_pairing_mode_routes_tap_to_pair_endpoint(void) {
    EcoStation::PairingMode mode;
    EcoStation::ApiCall call = mode.onCardTap("A1B2C3D4");

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::ApiCallType::PairCard),
                          static_cast<int>(call.type));
    TEST_ASSERT_EQUAL_STRING("/api/v1/admin/cards/pair", call.path.c_str());
    TEST_ASSERT_NOT_NULL(strstr(call.jsonBody.c_str(), "\"credential_uid\""));
    TEST_ASSERT_NULL(strstr(call.jsonBody.c_str(), "client_timestamp"));
}

void test_pairing_mode_forwards_hce_kind(void) {
    EcoStation::PairingMode mode;
    EcoStation::ApiCall call = mode.onCardTap("TEST-ANDROID-001", "hce");

    TEST_ASSERT_EQUAL_STRING("/api/v1/admin/cards/pair", call.path.c_str());
    TEST_ASSERT_NOT_NULL(strstr(call.jsonBody.c_str(), "TEST-ANDROID-001"));
    TEST_ASSERT_NOT_NULL(strstr(call.jsonBody.c_str(), "\"credential_kind\""));
    TEST_ASSERT_NOT_NULL(strstr(call.jsonBody.c_str(), "hce"));
}

void test_operation_mode_ignores_credential_kind(void) {
    // The tap lookup is credential_uid-only by design (RF UID ≠ identity),
    // so the kind never reaches the tap wire format — even for phones.
    EcoStation::OperationMode mode;
    EcoStation::ApiCall call = mode.onCardTap("TEST-ANDROID-001", "hce");

    TEST_ASSERT_EQUAL_STRING("/api/v1/events/tap", call.path.c_str());
    TEST_ASSERT_NOT_NULL(strstr(call.jsonBody.c_str(), "TEST-ANDROID-001"));
    TEST_ASSERT_NULL(strstr(call.jsonBody.c_str(), "credential_kind"));
}

void test_both_modes_forward_the_relayed_proof(void) {
    EcoStation::HceProof proof;
    proof.nonceHex = "0123456789abcdef";
    proof.macHex = std::string(64, 'a');
    proof.keyNonceHex = std::string(32, 'b');
    proof.keyWrappedHex = std::string(64, 'c');

    EcoStation::OperationMode op;
    const EcoStation::ApiCall tap = op.onCardTap("PLS-K3Y7V3CT0R5Z", "hce", proof);
    TEST_ASSERT_NOT_NULL(strstr(tap.jsonBody.c_str(), "\"hce_mac\""));
    TEST_ASSERT_NULL(strstr(tap.jsonBody.c_str(), "hce_key_wrapped"));

    EcoStation::PairingMode pair;
    const EcoStation::ApiCall p = pair.onCardTap("PLS-K3Y7V3CT0R5Z", "hce", proof);
    TEST_ASSERT_NOT_NULL(strstr(p.jsonBody.c_str(), "\"hce_mac\""));
    TEST_ASSERT_NOT_NULL(strstr(p.jsonBody.c_str(), "\"hce_key_wrapped\""));
}

void test_mode_labels_are_spanish(void) {
    EcoStation::OperationMode op;
    EcoStation::PairingMode pair;

    TEST_ASSERT_EQUAL_INT(0, strcmp(op.label(), "OPERACION"));
    TEST_ASSERT_EQUAL_INT(0, strcmp(pair.label(), "EMPAREJAR"));
    TEST_ASSERT_NULL(strstr(op.label(), "/"));
    TEST_ASSERT_NULL(strstr(pair.label(), "/"));
}

void test_mode_kind_polymorphism(void) {
    EcoStation::OperationMode op;
    EcoStation::PairingMode pair;
    EcoStation::Mode* modes[] = {&op, &pair};

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::ModeKind::Operation),
                          static_cast<int>(modes[0]->kind()));
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::ModeKind::Pairing),
                          static_cast<int>(modes[1]->kind()));
}

// --- result → feedback mapping (operation mode) ------------------------------

void test_operation_interpret_success(void) {
    EcoStation::OperationMode mode;
    EcoStation::TapResult r;
    r.outcome = EcoStation::TapOutcome::Success;
    r.studentFirstName = "Maria";

    EcoStation::FeedbackSignal s = mode.interpret(r);
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::FeedbackKind::TapSuccess),
                          static_cast<int>(s.kind));
    TEST_ASSERT_EQUAL_STRING("Maria", s.detail.c_str());
}

void test_operation_interpret_all_failure_kinds(void) {
    EcoStation::OperationMode mode;

    struct Case {
        EcoStation::TapOutcome in;
        EcoStation::FeedbackKind want;
    };
    const Case cases[] = {
        {EcoStation::TapOutcome::CardNotRecognized, EcoStation::FeedbackKind::TapRejected},
        {EcoStation::TapOutcome::AuthFailure, EcoStation::FeedbackKind::AuthError},
        {EcoStation::TapOutcome::NetworkError, EcoStation::FeedbackKind::NetworkError},
        {EcoStation::TapOutcome::ValidationError, EcoStation::FeedbackKind::ServerError},
        {EcoStation::TapOutcome::ServerError, EcoStation::FeedbackKind::ServerError},
        {EcoStation::TapOutcome::UnknownError, EcoStation::FeedbackKind::ServerError},
    };

    for (const Case& c : cases) {
        EcoStation::TapResult r;
        r.outcome = c.in;
        EcoStation::FeedbackSignal s = mode.interpret(r);
        TEST_ASSERT_EQUAL_INT(static_cast<int>(c.want), static_cast<int>(s.kind));
    }
}

// --- result → feedback mapping (pairing mode) --------------------------------

void test_pairing_interpret_success(void) {
    EcoStation::PairingMode mode;
    EcoStation::PairResult r;
    r.outcome = EcoStation::PairOutcome::Success;
    r.pairedStudentName = "Maria González";

    EcoStation::FeedbackSignal s = mode.interpret(r);
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::FeedbackKind::PairSuccess),
                          static_cast<int>(s.kind));
    TEST_ASSERT_EQUAL_STRING("Maria González", s.detail.c_str());
}

void test_pairing_interpret_all_failure_kinds(void) {
    EcoStation::PairingMode mode;

    struct Case {
        EcoStation::PairOutcome in;
        EcoStation::FeedbackKind want;
    };
    const Case cases[] = {
        {EcoStation::PairOutcome::NoActiveSession, EcoStation::FeedbackKind::PairNoSession},
        {EcoStation::PairOutcome::AlreadyPaired, EcoStation::FeedbackKind::PairAlreadyPaired},
        {EcoStation::PairOutcome::AuthFailure, EcoStation::FeedbackKind::AuthError},
        {EcoStation::PairOutcome::NetworkError, EcoStation::FeedbackKind::NetworkError},
        {EcoStation::PairOutcome::ValidationError, EcoStation::FeedbackKind::ServerError},
        {EcoStation::PairOutcome::ServerError, EcoStation::FeedbackKind::ServerError},
        {EcoStation::PairOutcome::UnknownError, EcoStation::FeedbackKind::ServerError},
    };

    for (const Case& c : cases) {
        EcoStation::PairResult r;
        r.outcome = c.in;
        EcoStation::FeedbackSignal s = mode.interpret(r);
        TEST_ASSERT_EQUAL_INT(static_cast<int>(c.want), static_cast<int>(s.kind));
    }
}

// --- mode hints (TASK-004): the device teaches its own flow ---------------

void test_pairing_hint_teaches_arm_first_flow(void) {
    EcoStation::PairingMode pair;
    const char* hint = pair.hint();

    TEST_ASSERT_NOT_NULL(strstr(hint, "arma primero una sesión"));
}

void test_mode_hints_distinct_nonempty(void) {
    EcoStation::OperationMode op;
    EcoStation::PairingMode pair;

    TEST_ASSERT_TRUE(strlen(op.hint()) > 0);
    TEST_ASSERT_TRUE(strlen(pair.hint()) > 0);
    TEST_ASSERT_NOT_NULL(strstr(op.hint(), "EMPAREJADO"));
    // And the two modes never share guidance text
    TEST_ASSERT_TRUE(strcmp(op.hint(), pair.hint()) != 0);
}

void runModeTests() {
    RUN_TEST(test_operation_mode_routes_tap_to_events_endpoint);
    RUN_TEST(test_pairing_mode_routes_tap_to_pair_endpoint);
    RUN_TEST(test_pairing_mode_forwards_hce_kind);
    RUN_TEST(test_both_modes_forward_the_relayed_proof);
    RUN_TEST(test_operation_mode_ignores_credential_kind);
    RUN_TEST(test_mode_labels_are_spanish);
    RUN_TEST(test_mode_kind_polymorphism);
    RUN_TEST(test_pairing_hint_teaches_arm_first_flow);
    RUN_TEST(test_mode_hints_distinct_nonempty);
    RUN_TEST(test_operation_interpret_success);
    RUN_TEST(test_operation_interpret_all_failure_kinds);
    RUN_TEST(test_pairing_interpret_success);
    RUN_TEST(test_pairing_interpret_all_failure_kinds);
}
