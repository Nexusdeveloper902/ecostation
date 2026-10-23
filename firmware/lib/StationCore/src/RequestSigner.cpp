/**
 * RequestSigner.cpp — Pulse-HMAC signing over the shared HCE crypto.
 * RequestSigner.cpp — firma Pulse-HMAC sobre la cripto compartida de HCE.
 */
#include "RequestSigner.h"

#include "HceProtocol.h"

namespace EcoStation {
namespace Signer {

std::string toHex(const uint8_t* data, size_t len) {
    static const char kDigits[] = "0123456789abcdef";
    std::string out;
    out.reserve(len * 2);
    for (size_t i = 0; i < len; i++) {
        out.push_back(kDigits[(data[i] >> 4) & 0x0F]);
        out.push_back(kDigits[data[i] & 0x0F]);
    }
    return out;
}

std::string sha256Hex(const std::string& data) {
    uint8_t digest[Hce::HMAC_LEN];
    Hce::sha256Bytes(reinterpret_cast<const uint8_t*>(data.data()), data.size(), digest);
    return toHex(digest, sizeof(digest));
}

std::string keyFingerprint(const std::string& secret) {
    return sha256Hex(secret).substr(0, KID_LEN);
}

std::string requestSignature(const std::string& secret,
                             const std::string& method,
                             const std::string& path,
                             const std::string& nonce,
                             const std::string& body) {
    std::string canonical = method + "\n" + path + "\n" + nonce + "\n" + sha256Hex(body);
    uint8_t mac[Hce::HMAC_LEN];
    Hce::hmacSha256(reinterpret_cast<const uint8_t*>(secret.data()), secret.size(),
                    reinterpret_cast<const uint8_t*>(canonical.data()), canonical.size(), mac);
    return toHex(mac, sizeof(mac));
}

std::string authorizationValue(const std::string& secret,
                               const std::string& method,
                               const std::string& path,
                               const std::string& nonce,
                               const std::string& body) {
    return "Pulse-HMAC " + keyFingerprint(secret) + ":" + nonce + ":" +
           requestSignature(secret, method, path, nonce, body);
}

}  // namespace Signer
}  // namespace EcoStation
