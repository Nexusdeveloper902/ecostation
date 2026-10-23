/**
 * ApiClient.h — HTTP transport interface to the EcoStation backend.
 * ApiClient.h — interfaz de transporte HTTP hacia el backend EcoStation.
 *
 * The interface keeps the transport swappable (HTTPClient today, a future
 * TLS/queueing layer later) and gives tests an obvious seam. Identity is
 * the station's own API key, which never rides the wire: every POST is
 * signed with Pulse-HMAC (ADR-016), so a captured request carries one
 * single-use signature and nothing reusable.
 * / La interfaz mantiene el transporte intercambiable y da a las pruebas
 * una costura clara. La identidad es la clave propia de la estación, que
 * nunca viaja por el cable: cada POST va firmado con Pulse-HMAC.
 */
#pragma once

#include <string>

#include "CoreTypes.h"

namespace EcoStation {

struct HttpResponse {
    int status = 0;       // HTTP status code; < 0 → transport failure
    std::string body;     // raw response body (may be empty)
    bool transportOk = false;
};

class ApiClient {
public:
    virtual ~ApiClient() = default;

    /** POST a JSON body to baseUrl + path, signed with the station key. */
    virtual HttpResponse post(const std::string& path,
                              const std::string& jsonBody) = 0;
};

}  // namespace EcoStation
