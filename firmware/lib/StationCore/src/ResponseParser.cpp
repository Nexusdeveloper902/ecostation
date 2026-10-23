#include "ResponseParser.h"

#include <ArduinoJson.h>

namespace EcoStation {

namespace {

// Extract the server-side message for logging (best-effort; display-only).
std::string extractMessage(const std::string& body) {
    JsonDocument doc;
    if (deserializeJson(doc, body) != DeserializationError::Ok) {
        return body.substr(0, 120);
    }
    const char* msg = doc["message"];  // null when absent
    return msg != nullptr ? std::string(msg) : std::string();
}

}  // namespace

TapResult parseTapResponse(int httpStatus, const std::string& body) {
    TapResult result;
    result.message = extractMessage(body);

    if (httpStatus < 0) {
        result.outcome = TapOutcome::NetworkError;
        return result;
    }

    switch (httpStatus) {
        case 200: {
            JsonDocument doc;
            if (deserializeJson(doc, body) != DeserializationError::Ok) {
                result.outcome = TapOutcome::UnknownError;
                return result;
            }
            const char* status = doc["status"];  // null when absent
            if (status != nullptr && std::string(status) == "ok") {
                result.outcome = TapOutcome::Success;
                result.eventId = doc["event_id"] | -1;
                result.eventType = doc["event_type"] | "";
                result.studentFirstName = doc["student_first_name"] | "";
                const char* nextStep = doc["next_step"];  // null when absent
                result.awaitingClassification =
                    nextStep != nullptr && std::string(nextStep) == "awaiting_classification";
            } else {
                // 200 with an unexpected body — treat as unknown, stay responsive.
                result.outcome = TapOutcome::UnknownError;
            }
            return result;
        }
        case 401:
            result.outcome = TapOutcome::AuthFailure;
            return result;
        case 403:  // TASK-015: phone credential's relayed proof refused
        case 404:
            // Unknown card or non-active card — same rejection feedback;
            // the message distinguishes them for the serial log.
            result.outcome = TapOutcome::CardNotRecognized;
            return result;
        case 422:
            result.outcome = TapOutcome::ValidationError;
            return result;
        default:
            result.outcome = (httpStatus >= 500) ? TapOutcome::ServerError
                                                 : TapOutcome::UnknownError;
            return result;
    }
}

PairResult parsePairResponse(int httpStatus, const std::string& body) {
    PairResult result;
    result.message = extractMessage(body);

    if (httpStatus < 0) {
        result.outcome = PairOutcome::NetworkError;
        return result;
    }

    switch (httpStatus) {
        case 200: {
            JsonDocument doc;
            if (deserializeJson(doc, body) != DeserializationError::Ok) {
                result.outcome = PairOutcome::UnknownError;
                return result;
            }
            const char* status = doc["status"];  // null when absent
            if (status != nullptr && std::string(status) == "ok") {
                result.outcome = PairOutcome::Success;
                result.studentId = doc["student_id"] | -1;
                result.pairedStudentName = doc["paired_student_name"] | "";
            } else {
                result.outcome = PairOutcome::UnknownError;
            }
            return result;
        }
        case 401:
            result.outcome = PairOutcome::AuthFailure;
            return result;
        case 403:
            // TASK-015: the phone's handed-over key did not verify its own
            // proof (or was replayed). The window stays armed.
            result.outcome = PairOutcome::ProofRejected;
            return result;
        case 409:
            result.outcome = PairOutcome::NoActiveSession;
            return result;
        case 422:
            // Per the pairing contract the 422 is "card already paired";
            // generic validation 422s collapse into the same rejection.
            result.outcome = PairOutcome::AlreadyPaired;
            return result;
        default:
            result.outcome = (httpStatus >= 500) ? PairOutcome::ServerError
                                                 : PairOutcome::UnknownError;
            return result;
    }
}

long parseCaptureId(const std::string& body) {
    // PHP notices/warnings, a UTF-8 BOM or a proxy banner can precede the
    // JSON: deserialize from the first '{' instead of losing the capture id.
    const size_t brace = body.find('{');
    if (brace == std::string::npos) {
        return -1;
    }
    JsonDocument doc;
    if (deserializeJson(doc, body.c_str() + brace) != DeserializationError::Ok) {
        return -1;
    }
    const long id = doc["capture_id"] | -1L;
    return id > 0 ? id : -1;
}

}  // namespace EcoStation
