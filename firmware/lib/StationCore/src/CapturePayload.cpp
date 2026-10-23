#include "CapturePayload.h"

#include "RequestSigner.h"

namespace EcoStation {

const char* CapturePayload::boundary() {
    // Literal chosen to be absent from JPEG entropy; fixed so payloads
    // and the Content-Type header can never disagree.
    return "----PresenceCamBoundary7C4F1A";
}

std::string CapturePayload::contentType() {
    return std::string("multipart/form-data; boundary=") + boundary();
}

std::string CapturePayload::imageOnly(const uint8_t* jpeg, size_t length) {
    return wrap("", jpeg, length);
}

std::string CapturePayload::classifyWithEvent(long eventId, const uint8_t* jpeg, size_t length) {
    std::string fields = "--";
    fields += boundary();
    fields += "\r\nContent-Disposition: form-data; name=\"event_id\"\r\n\r\n";
    fields += std::to_string(eventId);
    fields += "\r\n";
    return wrap(fields, jpeg, length);
}

std::string CapturePayload::classifySigningBody(long eventId, const uint8_t* jpeg, size_t length) {
    // Byte-exact mirror of B2B-Core DeviceRequestSigner::multipartCanonical()
    // with an event id: "event_id=<id>\nimage.sha256=<hex>". The hash covers
    // the raw image bytes only (not the multipart framing) — PHP reconstructs
    // the same string from the parsed upload (php://input is empty there).
    std::string image((jpeg != nullptr && length > 0) ? reinterpret_cast<const char*>(jpeg) : "", (jpeg != nullptr) ? length : 0);
    std::string hash = Signer::sha256Hex(image);
    return "event_id=" + std::to_string(eventId) + "\nimage.sha256=" + hash;
}

std::string CapturePayload::captureSigningBody(const uint8_t* jpeg, size_t length) {
    // Same mirror without an event id: "image.sha256=<hex>".
    std::string image((jpeg != nullptr && length > 0) ? reinterpret_cast<const char*>(jpeg) : "", (jpeg != nullptr) ? length : 0);
    return "image.sha256=" + Signer::sha256Hex(image);
}

std::string CapturePayload::wrap(const std::string& fields, const uint8_t* jpeg, size_t length) {
    std::string body = fields;

    body += "--";
    body += boundary();
    body += "\r\nContent-Disposition: form-data; name=\"image\"; filename=\"capture.jpg\"\r\n";
    body += "Content-Type: image/jpeg\r\n\r\n";

    body.append(reinterpret_cast<const char*>(jpeg), length);

    body += "\r\n--";
    body += boundary();
    body += "--\r\n";

    return body;
}

}  // namespace EcoStation
