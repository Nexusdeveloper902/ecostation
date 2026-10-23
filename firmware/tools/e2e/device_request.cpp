/**
 * device_request.cpp — E2E harness (host side).
 * device_request.cpp — arnés E2E (lado host).
 *
 * Builds ONE signed device request with the firmware's own RequestSigner and
 * CapturePayload: it prints exactly two lines to stdout (the Authorization
 * value and the Content-Type) and writes the body bytes to a file. The shell
 * script only has to feed those three artifacts to curl, so the signature
 * covers the exact bytes the ESP32 would send.
 * / Construye UNA petición firmada del dispositivo con el RequestSigner y el
 * CapturePayload del firmware: imprime exactamente dos líneas por stdout (el
 * valor de Authorization y el Content-Type) y escribe el cuerpo en un
 * fichero. El guion solo pasa esos tres artefactos a curl, así la firma
 * cubre los bytes exactos que enviaría el ESP32.
 *
 * Usage / Uso:
 *   device_request <secret> json <path> <json_body_file> <out_body> [--nonce <hex>]
 *   device_request <secret> capture <jpeg_file> <out_body> [--nonce <hex>]
 *   device_request <secret> classify <jpeg_file> <event_id> <out_body> [--nonce <hex>]
 *
 * `capture` targets POST /api/v1/recycling/capture
 * (CapturePayload::imageOnly + captureSigningBody); `classify` targets
 * POST /api/v1/recycling/classify (classifyWithEvent + classifySigningBody).
 * The nonce is 32 hex characters from std::random_device unless --nonce is
 * given (the signer golden vector pins its own).
 */
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <fstream>
#include <random>
#include <string>
#include <vector>

#include "CapturePayload.h"
#include "RequestSigner.h"

namespace {

const char* const kCapturePath = "/api/v1/recycling/capture";
const char* const kClassifyPath = "/api/v1/recycling/classify";

void usage(const char* prog) {
    std::fprintf(stderr,
                 "usage:\n"
                 "  %s <secret> json <path> <json_body_file> <out_body> [--nonce <hex>]\n"
                 "  %s <secret> capture <jpeg_file> <out_body> [--nonce <hex>]\n"
                 "  %s <secret> classify <jpeg_file> <event_id> <out_body> [--nonce <hex>]\n",
                 prog, prog, prog);
}

std::string randomNonce() {
    std::random_device rd;
    static const char kDigits[] = "0123456789abcdef";
    std::string nonce;
    nonce.reserve(32);
    for (int i = 0; i < 32; i++) {
        nonce.push_back(kDigits[rd() & 0x0F]);
    }
    return nonce;
}

bool readFile(const std::string& path, std::string& out) {
    std::ifstream in(path, std::ios::binary);
    if (!in) {
        return false;
    }
    out.assign(std::istreambuf_iterator<char>(in), std::istreambuf_iterator<char>());
    return true;
}

bool writeFile(const std::string& path, const std::string& bytes) {
    std::ofstream out(path, std::ios::binary | std::ios::trunc);
    if (!out) {
        return false;
    }
    out.write(bytes.data(), static_cast<std::streamsize>(bytes.size()));
    return out.good();
}

bool isHex(const std::string& value) {
    if (value.empty()) {
        return false;
    }
    for (char c : value) {
        const bool hex = (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
        if (!hex) {
            return false;
        }
    }
    return true;
}

}  // namespace

int main(int argc, char** argv) {
    if (argc < 2) {
        usage(argv[0]);
        return 2;
    }

    // Optional trailing: --nonce <hex>
    int argEnd = argc;
    std::string nonce;
    if (argc >= 3 && std::strcmp(argv[argc - 2], "--nonce") == 0) {
        nonce = argv[argc - 1];
        if (!isHex(nonce) || nonce.size() < 16 || nonce.size() > 64) {
            std::fprintf(stderr, "nonce inválido: se esperan 16..64 caracteres hexadecimales\n");
            return 2;
        }
        argEnd = argc - 2;
    } else {
        nonce = randomNonce();
    }

    const std::string secret = argv[1];
    const std::string mode = argv[2];

    std::string path;
    std::string body;
    std::string signingBody;
    std::string contentType = "application/json";
    std::string outBody;

    if (mode == "json") {
        // device_request <secret> json <path> <json_body_file> <out_body>
        if (argEnd != 6) {
            usage(argv[0]);
            return 2;
        }
        path = argv[3];
        const std::string bodyFile = argv[4];
        outBody = argv[5];

        if (!readFile(bodyFile, body)) {
            std::fprintf(stderr, "no se puede leer el cuerpo JSON: %s\n", bodyFile.c_str());
            return 1;
        }
        // The canonical body is the raw JSON bytes on the wire.
        signingBody = body;
    } else if (mode == "capture") {
        // device_request <secret> capture <jpeg_file> <out_body>
        if (argEnd != 5) {
            usage(argv[0]);
            return 2;
        }
        const std::string jpegFile = argv[3];
        outBody = argv[4];

        if (!readFile(jpegFile, body)) {
            std::fprintf(stderr, "no se puede leer la imagen: %s\n", jpegFile.c_str());
            return 1;
        }
        const auto* bytes = reinterpret_cast<const uint8_t*>(body.data());
        path = kCapturePath;
        signingBody = EcoStation::CapturePayload::captureSigningBody(bytes, body.size());
        body = EcoStation::CapturePayload::imageOnly(bytes, body.size());
        contentType = EcoStation::CapturePayload::contentType();
    } else if (mode == "classify") {
        // device_request <secret> classify <jpeg_file> <event_id> <out_body>
        if (argEnd != 6) {
            usage(argv[0]);
            return 2;
        }
        const std::string jpegFile = argv[3];
        const std::string eventIdText = argv[4];
        outBody = argv[5];

        char* end = nullptr;
        const long eventId = std::strtol(eventIdText.c_str(), &end, 10);
        if (end == eventIdText.c_str() || *end != '\0' || eventId <= 0) {
            std::fprintf(stderr, "event_id inválido: %s\n", eventIdText.c_str());
            return 2;
        }

        if (!readFile(jpegFile, body)) {
            std::fprintf(stderr, "no se puede leer la imagen: %s\n", jpegFile.c_str());
            return 1;
        }
        const auto* bytes = reinterpret_cast<const uint8_t*>(body.data());
        path = kClassifyPath;
        signingBody = EcoStation::CapturePayload::classifySigningBody(eventId, bytes, body.size());
        body = EcoStation::CapturePayload::classifyWithEvent(eventId, bytes, body.size());
        contentType = EcoStation::CapturePayload::contentType();
    } else {
        usage(argv[0]);
        return 2;
    }

    if (!writeFile(outBody, body)) {
        std::fprintf(stderr, "no se puede escribir el cuerpo: %s\n", outBody.c_str());
        return 1;
    }

    const std::string authorization =
        EcoStation::Signer::authorizationValue(secret, "POST", path, nonce, signingBody);

    std::printf("%s\n", authorization.c_str());
    std::printf("%s\n", contentType.c_str());
    return 0;
}
