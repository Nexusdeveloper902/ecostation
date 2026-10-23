/**
 * build_payloads.cpp — E2E harness (host side).
 * build_payloads.cpp — arnés E2E (lado host).
 *
 * Writes the EXACT JSON request bodies the ESP32 firmware sends, using
 * the firmware's own PayloadBuilder — so the backend is exercised with
 * byte-identical device payloads.
 * / Escribe los cuerpos JSON EXACTOS que envía el firmware del ESP32,
 * usando su propio PayloadBuilder — el backend recibe payloads idénticos
 * a los del dispositivo.
 *
 * The associate body is the bottle-first resolution call; its wire shape is
 * built by buildAssociatePayload (same single-credential JSON as pair, but a
 * separate contract in the firmware).
 * / El cuerpo de asociación es la llamada de resolución «botella primero»;
 * lo construye buildAssociatePayload (mismo JSON de una credencial que
 * emparejar, pero contrato separado en el firmware).
 *
 * Usage: build_payloads <tap_uid> <pair_uid> <out_dir>
 */
#include <cstdio>
#include <cstdlib>
#include <string>

#include "PayloadBuilder.h"

namespace {

bool writeBody(const std::string& path, const std::string& bytes) {
    FILE* f = std::fopen(path.c_str(), "wb");
    if (f == nullptr) {
        std::perror(path.c_str());
        return false;
    }
    std::fwrite(bytes.data(), 1, bytes.size(), f);
    std::fclose(f);
    return true;
}

}  // namespace

int main(int argc, char** argv) {
    if (argc != 4) {
        std::fprintf(stderr, "usage: %s <tap_uid> <pair_uid> <out_dir>\n", argv[0]);
        return 2;
    }

    const std::string tapUid = argv[1];
    const std::string pairUid = argv[2];
    const std::string dir = argv[3];

    const std::string tapPayload = EcoStation::buildTapPayload(tapUid);
    const std::string pairPayload = EcoStation::buildPairPayload(pairUid);
    const std::string associatePayload = EcoStation::buildAssociatePayload(tapUid);

    const std::string tapPath = dir + "/tap_payload.json";
    const std::string pairPath = dir + "/pair_payload.json";
    const std::string associatePath = dir + "/associate_payload.json";

    if (!writeBody(tapPath, tapPayload) || !writeBody(pairPath, pairPayload) ||
        !writeBody(associatePath, associatePayload)) {
        return 1;
    }

    std::printf("tap       payload: %s\n", tapPayload.c_str());
    std::printf("pair      payload: %s\n", pairPayload.c_str());
    std::printf("associate payload: %s\n", associatePayload.c_str());
    return 0;
}
