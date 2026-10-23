/**
 * WifiService.h — connect-on-boot + periodic non-blocking reconnect.
 * WifiService.h — conexión al arrancar + reconexión periódica no bloqueante.
 *
 * Design rules (protocol Phase A/F):
 *   - Initial connect: bounded wait (WIFI_CONNECT_TIMEOUT_MS), never hangs.
 *   - After a drop: check every WIFI_RECONNECT_INTERVAL_MS; the main loop
 *     keeps running and taps keep being debounced meanwhile.
 *   - WiFi.waitForConnectResult()/blocking loops are forbidden.
 * / Reglas: espera inicial acotada, reconexión periódica, bucle principal
 *   siempre vivo; prohibido usar bloqueos.
 */
#pragma once

#include <Arduino.h>
#include <WiFi.h>

#include <cstdint>
#include <string>

namespace EcoStation {

class WifiService {
public:
    WifiService(const char* ssid, const char* password,
                uint32_t connectTimeoutMs = 15000,
                uint32_t reconnectIntervalMs = 10000)
        : ssid_(ssid),
          password_(password),
          connectTimeoutMs_(connectTimeoutMs),
          reconnectIntervalMs_(reconnectIntervalMs) {}

    /**
     * Bounded initial association attempt. Returns when associated,
     * when the timeout elapses, or when the credentials are rejected —
     * the device boots regardless (reconnects continue in the background).
     */
    bool begin(uint32_t nowMs) {
        WiFi.mode(WIFI_STA);
        WiFi.begin(ssid_, password_);

        uint32_t start = nowMs;
        while (WiFi.status() != WL_CONNECTED && (nowMs - start) < connectTimeoutMs_) {
            delay(100);  // bounded boot-phase wait only; never in loop()
            nowMs = millis();
        }
        lastAttemptMs_ = nowMs;
        return WiFi.status() == WL_CONNECTED;
    }

    bool isConnected() const {
        return WiFi.status() == WL_CONNECTED;
    }

    /** Non-blocking maintenance: periodic reconnect attempt on a drop. */
    void tick(uint32_t nowMs) {
        if (WiFi.status() == WL_CONNECTED) {
            return;
        }
        if (lastAttemptMs_ != 0 && (nowMs - lastAttemptMs_) < reconnectIntervalMs_) {
            return;  // still inside the retry backoff window
        }
        lastAttemptMs_ = nowMs;
        WiFi.disconnect();
        WiFi.begin(ssid_, password_);  // async: status changes over time
    }

    /** IP as string for the serial log. */
    std::string ip() const {
        if (WiFi.status() != WL_CONNECTED) {
            return "(sin conexión)";
        }
        return std::string(WiFi.localIP().toString().c_str());
    }

private:
    const char* ssid_;
    const char* password_;
    uint32_t connectTimeoutMs_;
    uint32_t reconnectIntervalMs_;
    uint32_t lastAttemptMs_ = 0;
};

}  // namespace EcoStation
