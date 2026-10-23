/**
 * CapturePayload.h — multipart/form-data bodies for the camera station
 * (TASK-008). Pure C++ so the EXACT wire bytes are host-testable.
 * CapturePayload.h — cuerpos multipart/form-data para la estación de
 * cámara (TASK-008). C++ puro para testear los bytes exactos en el host.
 *
 * B2B-Core contracts (docs/API.md):
 *   POST /api/v1/recycling/capture           — multipart: image=<jpeg>
 *   POST /api/v1/recycling/classify          — multipart: event_id + image
 *   POST /api/v1/recycling/captures/{id}/associate — JSON (PayloadBuilder)
 *
 * std::string is binary-safe for the JPEG bytes; HTTPClient's byte-array
 * POST sends them verbatim.
 *
 * Signing note (Pulse-HMAC multipart canonical): PHP never sees raw
 * multipart bytes (php://input is empty), so image posts are signed over
 * signingBody(), NOT the wire bytes — event_id + sha256(image), mirrored
 * byte-for-byte by B2B-Core DeviceRequestSigner::multipartCanonical().
 */
#pragma once

#include <cstdint>
#include <string>

namespace EcoStation {

class CapturePayload {
public:
    /** The fixed multipart boundary (never appears in JPEG streams in
     *  practice; body length stays deterministic). */
    static const char* boundary();

    /** Content-Type header value for this boundary. */
    static std::string contentType();

    /** multipart body with ONE image file field (bottle-first capture). */
    static std::string imageOnly(const uint8_t* jpeg, size_t length);

    /** multipart body with event_id + image (card-first classify). */
    static std::string classifyWithEvent(long eventId, const uint8_t* jpeg, size_t length);

    /** Pulse-HMAC signing canonical for classify: "event_id=<id>\nimage.sha256=<hex>". */
    static std::string classifySigningBody(long eventId, const uint8_t* jpeg, size_t length);

    /** Pulse-HMAC signing canonical for capture: "image.sha256=<hex>". */
    static std::string captureSigningBody(const uint8_t* jpeg, size_t length);

private:
    static std::string wrap(const std::string& fields, const uint8_t* jpeg, size_t length);
};

}  // namespace EcoStation
