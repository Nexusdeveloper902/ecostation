/**
 * EcoStationEndpoint.h — the discovered EcoStation server address (pure C++).
 * EcoStationEndpoint.h — la dirección descubierta del servidor EcoStation (C++ puro).
 *
 * StationCore stays FREE of Arduino headers so this contract is
 * host-testable (native env, test_endpoint.cpp). The Arduino-only
 * query itself lives in lib/Discovery (ESPmDNS) and maps its results
 * onto EcoStationCandidate below; the SELECTION policy lives here so
 * every device image shares it and the host tests pin it.
 * / StationCore sigue LIBRE de cabeceras de Arduino para que este
 * contrato sea testeable en el host. La consulta (solo Arduino) vive en
 * lib/Discovery (ESPmDNS) y mapea sus resultados a EcoStationCandidate;
 * la política de SELECCIÓN vive aquí para que toda imagen la comparta.
 *
 * DNS-SD contract (must match web/ scripts/mdns/ecostation.service and
 * the serve.sh advertisement):
 *   service _ecostation._tcp.local, TXT version=1 protocol=1 api=/api.
 * The service NAME is the server identity: any answer to that query is
 * an EcoStation server. The `protocol` TXT is a compatibility gate — a
 * result carrying protocol != "1" is rejected (a future wire change); a
 * result with no protocol TXT is accepted (minimal advertisements keep
 * working).
 */
#pragma once

#include <cstdint>
#include <string>

// Manual server address cap: long enough for "http://192.168.100.100:65535"
// and short enough that a pasted paragraph is refused outright.
#ifndef ECOSTATION_SERVER_URL_MAX
#define ECOSTATION_SERVER_URL_MAX 64
#endif

namespace EcoStation {

// --- DNS-SD identity (shared with the server advertisement) ----------------
#define ECOSTATION_MDNS_SERVICE_NAME "ecostation"  // queried as _ecostation (+ proto below)
#define ECOSTATION_MDNS_SERVICE_PROTO "tcp"        // queried as _tcp
#define ECOSTATION_MDNS_LABEL "_ecostation._tcp.local"  // log/operator label only
#define ECOSTATION_DEFAULT_API_PORT 8000           // artisan serve default; TXT never carries it
#define ECOSTATION_PROTOCOL_VERSION "1"            // TXT protocol=… this firmware speaks

/** One DNS-SD answer, already mapped off the Arduino types. */
struct EcoStationCandidate {
    std::string ip;            // dotted IPv4, e.g. "192.168.1.50"
    uint16_t port = 0;         // SRV port
    std::string protocolTxt;   // TXT protocol value, "" when not advertised
};

/** The endpoint the HTTP layer dials. Invalid = keep the compiled fallback. */
struct EcoStationEndpoint {
    std::string host;
    uint16_t port = 0;
    bool valid = false;

    std::string baseUrl() const {
        return "http://" + host + ":" + std::to_string(port);
    }

    std::string urlFor(const std::string& path) const {
        return baseUrl() + path;
    }

    /** Parse the compiled API_BASE_URL fallback ("http://host[:port][/…]"). */
    static EcoStationEndpoint fromBaseUrl(const std::string& baseUrl) {
        EcoStationEndpoint out;
        std::string rest = baseUrl;
        const std::string scheme = "http://";
        if (rest.compare(0, scheme.size(), scheme) == 0) {
            rest = rest.substr(scheme.size());
        }
        const size_t slash = rest.find('/');
        const std::string hostPort =
            (slash == std::string::npos) ? rest : rest.substr(0, slash);
        if (hostPort.empty()) {
            return out;
        }
        const size_t colon = hostPort.rfind(':');
        if (colon == std::string::npos) {
            out.host = hostPort;
            out.port = 80;
        } else {
            out.host = hostPort.substr(0, colon);
            const std::string portStr = hostPort.substr(colon + 1);
            if (out.host.empty() || portStr.empty()) {
                return out;
            }
            long port = 0;
            for (size_t i = 0; i < portStr.size(); ++i) {
                if (portStr[i] < '0' || portStr[i] > '9') {
                    return out;
                }
                port = port * 10 + (portStr[i] - '0');
            }
            if (port <= 0 || port > 65535) {
                return out;
            }
            out.port = static_cast<uint16_t>(port);
        }
        out.valid = true;
        return out;
    }
};

/** Compatibility gate: absent TXT stays accepted, anything but "1" is out. */
inline bool ecoStationProtocolCompatible(const std::string& protocolTxt) {
    return protocolTxt.empty() ||
           protocolTxt == ECOSTATION_PROTOCOL_VERSION;
}

/**
 * Selection policy: first candidate with a usable IPv4, a non-zero port
 * and a compatible protocol TXT wins. Returns false when nothing qualifies.
 */
inline bool pickEcoStationEndpoint(const EcoStationCandidate* candidates,
                                   int count, EcoStationEndpoint& out) {
    out = EcoStationEndpoint();
    if (candidates == 0 || count <= 0) {
        return false;
    }
    for (int i = 0; i < count; ++i) {
        const EcoStationCandidate& c = candidates[i];
        if (c.ip.empty() || c.ip == "0.0.0.0" || c.port == 0) {
            continue;
        }
        if (!ecoStationProtocolCompatible(c.protocolTxt)) {
            continue;
        }
        out.host = c.ip;
        out.port = c.port;
        out.valid = true;
        return true;
    }
    return false;
}

// --- Manual server address (operator fallback, `s <url>`) ------------------

/**
 * Validate a manually typed server address and normalize it.
 *
 * Accepted: "host:port", "host" and "http://host[:port]" — a bare
 * host:port becomes "http://host:port". Rejected: HTTPS (the firmware
 * speaks plain HTTP), localhost/loopback/bind-all hosts (the device
 * would talk to itself), empty or unparseable text, out-of-range ports
 * and anything longer than ECOSTATION_SERVER_URL_MAX.
 *
 * On success normalizedOut = endpoint.baseUrl(); on failure it is left
 * untouched (callers keep the address they already had).
 */
inline bool validManualServerUrl(const std::string& url,
                                 std::string& normalizedOut) {
    if (url.size() > ECOSTATION_SERVER_URL_MAX) {
        return false;
    }
    const std::string https = "https://";
    if (url.compare(0, https.size(), https) == 0) {
        return false;
    }
    const EcoStationEndpoint endpoint = EcoStationEndpoint::fromBaseUrl(url);
    if (!endpoint.valid) {
        return false;
    }
    if (endpoint.host == "localhost" ||
        endpoint.host.compare(0, 4, "127.") == 0 ||
        endpoint.host == "0.0.0.0") {
        return false;
    }
    normalizedOut = endpoint.baseUrl();
    return true;
}

/** Where the address the device dials came from (boot log + `s`). */
enum class ServerSource { Console, Discovered, Secrets };

/**
 * mDNS runs only at boot and on transport failure, never on a timer.
 * A manual address pins the server: discovery must not override what the
 * operator typed.
 */
inline bool shouldDiscover(bool mdnsEnabled, const std::string& consoleUrl) {
    return mdnsEnabled && consoleUrl.empty();
}

/**
 * Priority: manual console address → discovered endpoint → secrets.h URL.
 * baseUrlOut always receives the chosen address (the compiled fallback is
 * the last resort even when it does not parse as an endpoint).
 */
inline ServerSource chooseServer(const std::string& consoleUrl,
                                 const EcoStationEndpoint& discovered,
                                 const std::string& secretsUrl,
                                 std::string& baseUrlOut) {
    if (!consoleUrl.empty()) {
        baseUrlOut = consoleUrl;
        return ServerSource::Console;
    }
    if (discovered.valid) {
        baseUrlOut = discovered.baseUrl();
        return ServerSource::Discovered;
    }
    baseUrlOut = secretsUrl;
    return ServerSource::Secrets;
}

}  // namespace EcoStation
