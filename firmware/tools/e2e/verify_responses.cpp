/**
 * verify_responses.cpp — E2E harness (host side).
 * verify_responses.cpp — arnés E2E (lado host).
 *
 * Reads the REAL HTTP responses captured from a running EcoStation web/ app
 * (status + body pairs written by scripts/e2e_web.sh) and runs them through
 * the firmware's own ResponseParser — asserting the device would take the
 * correct action for every documented case.
 * / Lee respuestas HTTP REALES capturadas de una app web/ en ejecución y las
 * pasa por el ResponseParser del firmware — verifica que el dispositivo
 * actuaría correctamente en cada caso documentado.
 *
 * Case file format (one per line):
 *   <name>|<kind:tap|pair|capture|status>|<http_status>|<bodyFile>|<expected>
 * Body files must be UTF-8. `-` means "no body".
 *
 * Kinds:
 *   tap      expected: success | success_awaiting | not_recognized | auth | ...
 *            success_awaiting = Success && awaitingClassification && eventId > 0
 *   pair     expected: success | no_session | already_paired | auth | ...
 *   capture  passes when parseCaptureId(body) > 0
 *   status   passes when the HTTP status equals <expected>
 *
 * Output: PASS/FAIL per line; exits 0 only when every case passes.
 *
 * Usage: verify_responses <cases_file>
 */
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <fstream>
#include <sstream>
#include <string>
#include <vector>

#include "ResponseParser.h"

namespace {

enum class Kind { Tap, Pair, Capture, Status };

bool parseKind(const std::string& text, Kind& out) {
    if (text == "tap") { out = Kind::Tap; return true; }
    if (text == "pair") { out = Kind::Pair; return true; }
    if (text == "capture") { out = Kind::Capture; return true; }
    if (text == "status") { out = Kind::Status; return true; }
    return false;
}

struct Case {
    std::string name;
    Kind kind = Kind::Tap;
    int status = 0;
    std::string body;
    std::string expected;
};

const char* tapOutcomeName(EcoStation::TapOutcome o) {
    switch (o) {
        case EcoStation::TapOutcome::Success: return "success";
        case EcoStation::TapOutcome::CardNotRecognized: return "not_recognized";
        case EcoStation::TapOutcome::AuthFailure: return "auth";
        case EcoStation::TapOutcome::ValidationError: return "validation";
        case EcoStation::TapOutcome::ServerError: return "server";
        case EcoStation::TapOutcome::NetworkError: return "network";
        default: return "unknown";
    }
}

const char* pairOutcomeName(EcoStation::PairOutcome o) {
    switch (o) {
        case EcoStation::PairOutcome::Success: return "success";
        case EcoStation::PairOutcome::NoActiveSession: return "no_session";
        case EcoStation::PairOutcome::AlreadyPaired: return "already_paired";
        case EcoStation::PairOutcome::ProofRejected: return "proof_rejected";
        case EcoStation::PairOutcome::AuthFailure: return "auth";
        case EcoStation::PairOutcome::ValidationError: return "validation";
        case EcoStation::PairOutcome::ServerError: return "server";
        case EcoStation::PairOutcome::NetworkError: return "network";
        default: return "unknown";
    }
}

/** The tap outcome name the device acts on, refined for the recycling flow. */
std::string classifyTap(const EcoStation::TapResult& r) {
    if (r.outcome == EcoStation::TapOutcome::Success && r.awaitingClassification && r.eventId > 0) {
        return "success_awaiting";
    }
    return tapOutcomeName(r.outcome);
}

}  // namespace

int main(int argc, char** argv) {
    if (argc != 2) {
        std::fprintf(stderr, "usage: %s <cases_file>\n", argv[0]);
        return 2;
    }

    const std::string casesPath = argv[1];
    std::ifstream in(casesPath);
    if (!in) {
        std::fprintf(stderr, "cannot open %s\n", casesPath.c_str());
        return 1;
    }

    int total = 0;
    int passed = 0;

    std::string line;
    while (std::getline(in, line)) {
        if (line.empty() || line[0] == '#') continue;

        std::vector<std::string> parts;
        std::stringstream ss(line);
        std::string part;
        while (std::getline(ss, part, '|')) parts.push_back(part);
        if (parts.size() < 4) continue;

        Case c;
        c.name = parts[0];
        if (!parseKind(parts[1], c.kind)) {
            std::printf("  FAIL  %-22s -> tipo de caso desconocido (%s)\n", c.name.c_str(), parts[1].c_str());
            ++total;
            continue;
        }
        c.status = std::atoi(parts[2].c_str());
        c.expected = parts.size() > 4 ? parts[4] : "";
        if (!parts[3].empty() && parts[3] != "-") {
            std::ifstream bf(parts[3], std::ios::binary);
            std::ostringstream bs;
            bs << bf.rdbuf();
            c.body = bs.str();
        }

        ++total;
        std::string actual;
        std::string detail;
        bool ok = false;

        switch (c.kind) {
            case Kind::Tap: {
                const EcoStation::TapResult r = EcoStation::parseTapResponse(c.status, c.body);
                actual = classifyTap(r);
                if (r.outcome == EcoStation::TapOutcome::Success) {
                    detail = r.studentFirstName + "/" + r.eventType + "/event_id=" + std::to_string(r.eventId);
                } else {
                    detail = r.message;
                }
                ok = (c.expected == actual);
                break;
            }
            case Kind::Pair: {
                const EcoStation::PairResult r = EcoStation::parsePairResponse(c.status, c.body);
                actual = pairOutcomeName(r.outcome);
                if (r.outcome == EcoStation::PairOutcome::Success) {
                    detail = r.pairedStudentName + "/student_id=" + std::to_string(r.studentId);
                } else {
                    detail = r.message;
                }
                ok = (c.expected == actual);
                break;
            }
            case Kind::Capture: {
                const long id = EcoStation::parseCaptureId(c.body);
                actual = id > 0 ? "capture" : "no_capture";
                detail = "capture_id=" + std::to_string(id);
                ok = id > 0;
                break;
            }
            case Kind::Status: {
                actual = "status=" + std::to_string(c.status);
                detail = c.body.size() > 120 ? c.body.substr(0, 120) : c.body;
                const int want = std::atoi(c.expected.c_str());
                ok = (c.status == want);
                break;
            }
        }

        if (ok) {
            ++passed;
            std::printf("  PASS  %-22s -> %-14s [%s]\n", c.name.c_str(), actual.c_str(), detail.c_str());
        } else {
            std::printf("  FAIL  %-22s -> %-14s (expected %s) [%s]\n",
                        c.name.c_str(), actual.c_str(), c.expected.c_str(), detail.c_str());
        }
    }

    std::printf("\nfirmware-parser verdicts: %d/%d correct\n", passed, total);
    return passed == total && total > 0 ? 0 : 1;
}
