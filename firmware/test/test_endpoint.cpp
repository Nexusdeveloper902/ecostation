/**
 * test_endpoint.cpp — TASK-013: the discovered-endpoint contract.
 * test_endpoint.cpp — TASK-013: el contrato del endpoint descubierto.
 *
 * The SELECTION policy (which DNS-SD answer the device dials) lives in
 * StationCore precisely so these tests pin it: a wrong pick here sends
 * every tap at a dead address until the next rediscovery, and the failure
 * looks exactly like a Wi-Fi outage on the bench. The Arduino-only query
 * (ESPmDNS mapping) is covered by the bench checklist instead.
 * / La política de SELECCIÓN vive en StationCore para que estas pruebas
 * la fijen: una mala elección manda cada toque a una dirección muerta y
 * el fallo parece una caída de Wi-Fi. La consulta (solo Arduino) la cubre
 * la lista de verificación.
 */
#include <unity.h>

#include <string>

#include "EcoStationEndpoint.h"

using namespace EcoStation;

static EcoStationCandidate candidate(const char* ip, uint16_t port,
                                     const char* protocol = "") {
    EcoStationCandidate c;
    c.ip = ip;
    c.port = port;
    c.protocolTxt = protocol;
    return c;
}

// A single healthy answer dials host:port over plain HTTP.
static void endpoint_builds_the_http_base_url() {
    EcoStationCandidate c = candidate("192.168.1.50", 8000, "1");
    EcoStationEndpoint out;
    TEST_ASSERT_TRUE(pickEcoStationEndpoint(&c, 1, out));
    TEST_ASSERT_TRUE(out.valid);
    TEST_ASSERT_EQUAL_STRING("192.168.1.50", out.host.c_str());
    TEST_ASSERT_EQUAL_UINT16(8000, out.port);
    TEST_ASSERT_EQUAL_STRING("http://192.168.1.50:8000",
                             out.baseUrl().c_str());
    TEST_ASSERT_EQUAL_STRING("http://192.168.1.50:8000/api/v1/events/tap",
                             out.urlFor("/api/v1/events/tap").c_str());
}

// The first USABLE answer wins: blanks, 0.0.0.0 and zero ports are skipped.
static void selection_skips_unusable_answers() {
    EcoStationCandidate cs[4];
    cs[0] = candidate("", 8000, "1");
    cs[1] = candidate("0.0.0.0", 8000, "1");
    cs[2] = candidate("192.168.1.9", 0, "1");
    cs[3] = candidate("192.168.43.91", 8000, "1");
    EcoStationEndpoint out;
    TEST_ASSERT_TRUE(pickEcoStationEndpoint(cs, 4, out));
    TEST_ASSERT_EQUAL_STRING("192.168.43.91", out.host.c_str());
    TEST_ASSERT_EQUAL_UINT16(8000, out.port);
}

// Nothing usable (or nothing at all) yields an invalid endpoint — the
// caller must keep the compiled fallback, never a blank URL.
static void selection_with_no_usable_answer_is_invalid() {
    EcoStationCandidate cs[2];
    cs[0] = candidate("0.0.0.0", 8000, "1");
    cs[1] = candidate("192.168.1.9", 0, "1");
    EcoStationEndpoint out;
    TEST_ASSERT_FALSE(pickEcoStationEndpoint(cs, 2, out));
    TEST_ASSERT_FALSE(out.valid);

    TEST_ASSERT_FALSE(pickEcoStationEndpoint(0, 0, out));
    TEST_ASSERT_FALSE(out.valid);
}

// protocol TXT gate: absent stays accepted (minimal advertisements keep
// working), "1" is accepted, anything else is a future wire — rejected.
static void protocol_txt_gates_future_wires() {
    TEST_ASSERT_TRUE(ecoStationProtocolCompatible(""));
    TEST_ASSERT_TRUE(ecoStationProtocolCompatible("1"));
    TEST_ASSERT_FALSE(ecoStationProtocolCompatible("2"));

    EcoStationCandidate cs[2];
    cs[0] = candidate("192.168.1.50", 8000, "2");
    cs[1] = candidate("192.168.43.91", 8000, "");
    EcoStationEndpoint out;
    TEST_ASSERT_TRUE(pickEcoStationEndpoint(cs, 2, out));
    TEST_ASSERT_EQUAL_STRING("192.168.43.91", out.host.c_str());
}

// The compiled API_BASE_URL fallback parses back into an endpoint.
static void fallback_base_url_parses() {
    EcoStationEndpoint out =
        EcoStationEndpoint::fromBaseUrl("http://192.0.2.11:8000");
    TEST_ASSERT_TRUE(out.valid);
    TEST_ASSERT_EQUAL_STRING("192.0.2.11", out.host.c_str());
    TEST_ASSERT_EQUAL_UINT16(8000, out.port);
    TEST_ASSERT_EQUAL_STRING("http://192.0.2.11:8000",
                             out.baseUrl().c_str());
}

// Garbage fallbacks stay invalid (missing host, bad port) — and a bare
// host without a port means plain port 80.
static void fallback_base_url_rejects_garbage() {
    TEST_ASSERT_FALSE(EcoStationEndpoint::fromBaseUrl("http://:8000").valid);
    TEST_ASSERT_FALSE(EcoStationEndpoint::fromBaseUrl("http://host:abc").valid);
    TEST_ASSERT_FALSE(EcoStationEndpoint::fromBaseUrl("http://").valid);

    EcoStationEndpoint bare =
        EcoStationEndpoint::fromBaseUrl("http://ecostation-server");
    TEST_ASSERT_TRUE(bare.valid);
    TEST_ASSERT_EQUAL_UINT16(80, bare.port);
}

// --- Task 4: EcoStation identity and the manual-address policy -------------

static void mdns_identity_is_ecostation() {
    TEST_ASSERT_EQUAL_STRING("ecostation", ECOSTATION_MDNS_SERVICE_NAME);
    TEST_ASSERT_EQUAL_STRING("_ecostation._tcp.local", ECOSTATION_MDNS_LABEL);
}

static void manual_url_is_normalized() {
    std::string out;
    TEST_ASSERT_TRUE(validManualServerUrl("192.168.1.50:8000", out));
    TEST_ASSERT_EQUAL_STRING("http://192.168.1.50:8000", out.c_str());
    TEST_ASSERT_TRUE(validManualServerUrl("http://10.0.0.5", out));
    TEST_ASSERT_EQUAL_STRING("http://10.0.0.5:80", out.c_str());
}

static void manual_url_rejects_unusable_addresses() {
    std::string out = "untouched";
    const char* bad[] = {"http://localhost:8000", "127.0.0.1:8000", "http://0.0.0.0:8000",
                         "https://10.0.0.5", "http://10.0.0.5:99999", "http://:8000", "", "basura::",
                         "http://10.0.0.5:8000/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"};
    for (const char* b : bad) {
        TEST_ASSERT_FALSE_MESSAGE(validManualServerUrl(b, out), b);
    }
    TEST_ASSERT_EQUAL_STRING("untouched", out.c_str());
}

static void console_url_pins_the_server() {
    EcoStationEndpoint disc; disc.host = "10.0.0.9"; disc.port = 8000; disc.valid = true;
    std::string url;
    TEST_ASSERT_FALSE(shouldDiscover(true, "http://10.0.0.5:8000"));
    TEST_ASSERT_TRUE(shouldDiscover(true, ""));
    TEST_ASSERT_FALSE(shouldDiscover(false, ""));
    TEST_ASSERT_TRUE(chooseServer("http://10.0.0.5:8000", disc, "http://1.1.1.1:8000", url) == ServerSource::Console);
    TEST_ASSERT_EQUAL_STRING("http://10.0.0.5:8000", url.c_str());
    TEST_ASSERT_TRUE(chooseServer("", disc, "http://1.1.1.1:8000", url) == ServerSource::Discovered);
    TEST_ASSERT_EQUAL_STRING("http://10.0.0.9:8000", url.c_str());
    TEST_ASSERT_TRUE(chooseServer("", EcoStationEndpoint(), "http://1.1.1.1:8000", url) == ServerSource::Secrets);
    TEST_ASSERT_EQUAL_STRING("http://1.1.1.1:8000", url.c_str());
}

void runEndpointTests() {
    RUN_TEST(endpoint_builds_the_http_base_url);
    RUN_TEST(selection_skips_unusable_answers);
    RUN_TEST(selection_with_no_usable_answer_is_invalid);
    RUN_TEST(protocol_txt_gates_future_wires);
    RUN_TEST(fallback_base_url_parses);
    RUN_TEST(fallback_base_url_rejects_garbage);
    RUN_TEST(mdns_identity_is_ecostation);
    RUN_TEST(manual_url_is_normalized);
    RUN_TEST(manual_url_rejects_unusable_addresses);
    RUN_TEST(console_url_pins_the_server);
}
