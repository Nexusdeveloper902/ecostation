#include <unity.h>

#include <cstring>
#include <ArduinoJson.h>

#include <string>

#include "Modes.h"
#include "ResponseParser.h"

// --- helpers ---------------------------------------------------------------

static std::string tapBodyOk() {
    return R"({"status":"ok","event_id":1042,"event_type":"CLASS_ATTENDANCE",)"
           R"("student_first_name":"Maria","next_step":null})";
}

static std::string tapBodyOkRecycling() {
    return R"({"status":"ok","event_id":1043,"event_type":"RECYCLING_DEPOSIT",)"
           R"("student_first_name":"Diego","next_step":"awaiting_classification"})";
}

static std::string errBody(const char* msg) {
    return std::string(R"({"status":"error","message":")") + msg + R"("})";
}

// --- tap parsing: every documented case -------------------------------------

void test_tap_success(void) {
    EcoStation::TapResult r = EcoStation::parseTapResponse(200, tapBodyOk());

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::Success),
                          static_cast<int>(r.outcome));
    TEST_ASSERT_EQUAL_INT32(1042, r.eventId);
    TEST_ASSERT_EQUAL_STRING("CLASS_ATTENDANCE", r.eventType.c_str());
    TEST_ASSERT_EQUAL_STRING("Maria", r.studentFirstName.c_str());
    TEST_ASSERT_FALSE(r.awaitingClassification);
}

void test_tap_success_recycling_next_step(void) {
    EcoStation::TapResult r = EcoStation::parseTapResponse(200, tapBodyOkRecycling());

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::Success),
                          static_cast<int>(r.outcome));
    TEST_ASSERT_TRUE(r.awaitingClassification);
    TEST_ASSERT_EQUAL_STRING("Diego", r.studentFirstName.c_str());
}

void test_tap_unknown_card_404(void) {
    EcoStation::TapResult r =
        EcoStation::parseTapResponse(404, errBody("Card not recognized"));

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::CardNotRecognized),
                          static_cast<int>(r.outcome));
    TEST_ASSERT_EQUAL_STRING("Card not recognized", r.message.c_str());
}

void test_tap_inactive_card_404_collapses_to_rejection(void) {
    // Same HTTP code, different message text: the outcome kind is stable
    // because parsing is locale/text independent.
    EcoStation::TapResult r =
        EcoStation::parseTapResponse(404, errBody("Tarjeta no está activa"));

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::CardNotRecognized),
                          static_cast<int>(r.outcome));
    TEST_ASSERT_EQUAL_STRING("Tarjeta no está activa", r.message.c_str());
}

void test_tap_auth_failure_401(void) {
    EcoStation::TapResult r =
        EcoStation::parseTapResponse(401, errBody("Invalid bearer token"));

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::AuthFailure),
                          static_cast<int>(r.outcome));
}

void test_tap_validation_422(void) {
    EcoStation::TapResult r = EcoStation::parseTapResponse(422, errBody("..."));

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::ValidationError),
                          static_cast<int>(r.outcome));
}

void test_tap_server_error_500(void) {
    EcoStation::TapResult r = EcoStation::parseTapResponse(500, "Internal Server Error");

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::ServerError),
                          static_cast<int>(r.outcome));
}

void test_tap_network_error_negative_status(void) {
    EcoStation::TapResult r = EcoStation::parseTapResponse(-1, "");

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::NetworkError),
                          static_cast<int>(r.outcome));
}

void test_tap_network_error_timeout(void) {
    // HTTPClient's HTTPC_ERROR_READ_TIMEOUT is -1 / connection refused -113.
    EcoStation::TapResult r = EcoStation::parseTapResponse(-113, "");

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::NetworkError),
                          static_cast<int>(r.outcome));
}

void test_tap_garbage_body_on_200_is_unknown_not_crash(void) {
    EcoStation::TapResult r = EcoStation::parseTapResponse(200, "not json at all");

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::UnknownError),
                          static_cast<int>(r.outcome));
}

void test_tap_empty_body_404_stays_responsive(void) {
    EcoStation::TapResult r = EcoStation::parseTapResponse(404, "");

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::CardNotRecognized),
                          static_cast<int>(r.outcome));
}

// --- pair parsing: every documented case -------------------------------------

static std::string pairBodyOk() {
    return R"({"status":"ok","paired_student_name":"Maria González","student_id":3})";
}

void test_pair_success(void) {
    EcoStation::PairResult r = EcoStation::parsePairResponse(200, pairBodyOk());

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::PairOutcome::Success),
                          static_cast<int>(r.outcome));
    TEST_ASSERT_EQUAL_STRING("Maria González", r.pairedStudentName.c_str());
    TEST_ASSERT_EQUAL_INT32(3, r.studentId);
}

void test_pair_no_active_session_409(void) {
    EcoStation::PairResult r =
        EcoStation::parsePairResponse(409, errBody("No pairing session active"));

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::PairOutcome::NoActiveSession),
                          static_cast<int>(r.outcome));
    TEST_ASSERT_EQUAL_STRING("No pairing session active", r.message.c_str());
}

void test_pair_already_paired_422(void) {
    EcoStation::PairResult r =
        EcoStation::parsePairResponse(422, errBody("Card already paired"));

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::PairOutcome::AlreadyPaired),
                          static_cast<int>(r.outcome));
    TEST_ASSERT_EQUAL_STRING("Card already paired", r.message.c_str());
}

void test_pair_spanish_already_paired_text_does_not_change_outcome(void) {
    EcoStation::PairResult r =
        EcoStation::parsePairResponse(422, errBody("La tarjeta ya está emparejada"));

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::PairOutcome::AlreadyPaired),
                          static_cast<int>(r.outcome));
}

void test_pair_auth_failure_401(void) {
    EcoStation::PairResult r =
        EcoStation::parsePairResponse(401, errBody("Invalid bearer token"));

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::PairOutcome::AuthFailure),
                          static_cast<int>(r.outcome));
}

void test_pair_network_error(void) {
    EcoStation::PairResult r = EcoStation::parsePairResponse(-1, "");

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::PairOutcome::NetworkError),
                          static_cast<int>(r.outcome));
}

void test_pair_server_error(void) {
    EcoStation::PairResult r = EcoStation::parsePairResponse(503, errBody("maintenance"));

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::PairOutcome::ServerError),
                          static_cast<int>(r.outcome));
}

void test_pair_garbage_body_is_unknown_not_crash(void) {
    EcoStation::PairResult r = EcoStation::parsePairResponse(200, "{{{");

    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::PairOutcome::UnknownError),
                          static_cast<int>(r.outcome));
}

// TASK-015: a refused phone proof is a rejection the operator must see.
void test_tap_phone_proof_refused_403_is_a_card_rejection(void) {
    EcoStation::TapResult r =
        EcoStation::parseTapResponse(403, errBody("Phone credential could not be verified"));
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::TapOutcome::CardNotRecognized),
                          static_cast<int>(r.outcome));
    TEST_ASSERT_EQUAL_STRING("Phone credential could not be verified", r.message.c_str());
}

void test_pair_phone_proof_refused_403(void) {
    EcoStation::PairResult r =
        EcoStation::parsePairResponse(403, errBody("Phone credential could not be verified"));
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::PairOutcome::ProofRejected),
                          static_cast<int>(r.outcome));
    EcoStation::PairingMode mode;
    TEST_ASSERT_EQUAL_INT(static_cast<int>(EcoStation::FeedbackKind::PairAlreadyPaired),
                          static_cast<int>(mode.interpret(r).kind));
}

void test_capture_id_is_parsed(void) {
    TEST_ASSERT_EQUAL(42, EcoStation::parseCaptureId("{\"status\":\"ok\",\"capture_id\":42,\"next_step\":\"present_card\"}"));
    TEST_ASSERT_EQUAL(7, EcoStation::parseCaptureId("{ \"capture_id\" : 7 }"));
    TEST_ASSERT_EQUAL(-1, EcoStation::parseCaptureId("{\"status\":\"ok\"}"));
    TEST_ASSERT_EQUAL(-1, EcoStation::parseCaptureId("{\"capture_id\":0}"));
    TEST_ASSERT_EQUAL(-1, EcoStation::parseCaptureId("<html>500</html>"));
    TEST_ASSERT_EQUAL(-1, EcoStation::parseCaptureId(""));
}

void runResponseTests() {
    RUN_TEST(test_tap_success);
    RUN_TEST(test_tap_success_recycling_next_step);
    RUN_TEST(test_tap_unknown_card_404);
    RUN_TEST(test_tap_inactive_card_404_collapses_to_rejection);
    RUN_TEST(test_tap_auth_failure_401);
    RUN_TEST(test_tap_validation_422);
    RUN_TEST(test_tap_server_error_500);
    RUN_TEST(test_tap_network_error_negative_status);
    RUN_TEST(test_tap_network_error_timeout);
    RUN_TEST(test_tap_garbage_body_on_200_is_unknown_not_crash);
    RUN_TEST(test_tap_empty_body_404_stays_responsive);
    RUN_TEST(test_tap_phone_proof_refused_403_is_a_card_rejection);
    RUN_TEST(test_pair_phone_proof_refused_403);
    RUN_TEST(test_pair_success);
    RUN_TEST(test_pair_no_active_session_409);
    RUN_TEST(test_pair_already_paired_422);
    RUN_TEST(test_pair_spanish_already_paired_text_does_not_change_outcome);
    RUN_TEST(test_pair_auth_failure_401);
    RUN_TEST(test_pair_network_error);
    RUN_TEST(test_pair_server_error);
    RUN_TEST(test_pair_garbage_body_is_unknown_not_crash);
    RUN_TEST(test_capture_id_is_parsed);
}
