/**
 * EcoStationDiscovery.h — DNS-SD client for the EcoStation server (Arduino-only).
 * EcoStationDiscovery.h — cliente DNS-SD del servidor EcoStation (solo Arduino).
 *
 * This is the ONLY place that touches mDNS: the rest of the firmware
 * asks for an EcoStationEndpoint without knowing how discovery works. One
 * queryService("ecostation", "tcp") round (~3 s inside the ESP-IDF core, so
 * callers only run it at boot and on transport failure — never per-tap,
 * never on a timer) maps each answer onto EcoStation::EcoStationCandidate and
 * shares the host-tested pickEcoStationEndpoint() policy.
 * / Este es el ÚNICO lugar que toca mDNS: el resto del firmware pide un
 * EcoStationEndpoint sin saber cómo funciona el descubrimiento. Una consulta
 * (~3 s dentro del core, así que solo al arrancar y ante fallo de
 * transporte — nunca por toque ni por temporizador) mapea cada respuesta
 * a EcoStationCandidate y usa la política pickEcoStationEndpoint() (testeada
 * en el host).
 *
 * Requires Wi-Fi STA up + MDNS.begin() (done here, once). Offline or
 * unanswered queries yield an invalid endpoint — the caller keeps the
 * compiled API_BASE_URL fallback and retries on the next failure.
 */
#pragma once

#include <Arduino.h>
#include <ESPmDNS.h>
#include <WiFi.h>

#include <cstdint>

#include "EcoStationEndpoint.h"

namespace EcoStation {

class EcoStationDiscovery {
public:
    EcoStationDiscovery()
        : mdnsStarted_(false), lastAttemptMs_(0), lastSeen_(0) {}

    /**
     * Start the local mDNS responder (also names THIS device on .local).
     * Safe to call on every loop: runs once, and only while associated.
     */
    void begin(const char* deviceHostname) {
        if (mdnsStarted_) {
            return;
        }
        if (WiFi.status() != WL_CONNECTED) {
            return;
        }
        mdnsStarted_ = MDNS.begin(deviceHostname);
    }

    /**
     * One blocking DNS-SD query for _ecostation._tcp.local. Returns the first
     * usable answer, or an invalid endpoint when offline / unanswered /
     * unusable. Worst case ~3 s (the ESP-IDF query window) — boot and
     * failure paths only.
     */
    EcoStationEndpoint discover() {
        EcoStationEndpoint none;
        lastSeen_ = 0;
        if (WiFi.status() != WL_CONNECTED || !mdnsStarted_) {
            return none;
        }
        const int n = MDNS.queryService(ECOSTATION_MDNS_SERVICE_NAME,
                                        ECOSTATION_MDNS_SERVICE_PROTO);
        if (n <= 0) {
            return none;
        }
        lastSeen_ = n;
        // Small static fan-out: one backend per LAN in practice; more
        // answers than this still resolve — only the first few compete.
        static const int kMaxConsidered = 4;
        EcoStationCandidate candidates[kMaxConsidered];
        int count = 0;
        for (int i = 0; i < n && count < kMaxConsidered; ++i) {
            candidates[count].ip =
                std::string(MDNS.IP(i).toString().c_str());
            candidates[count].port = MDNS.port(i);
            candidates[count].protocolTxt =
                MDNS.hasTxt(i, "protocol")
                    ? std::string(MDNS.txt(i, "protocol").c_str())
                    : "";
            ++count;
        }
        EcoStationEndpoint out;
        pickEcoStationEndpoint(candidates, count, out);
        return out;
    }

    /**
     * Cooldown-guarded rediscovery for the transport-failure path:
     * false = still inside the cooldown (no multicast emitted) or
     * nothing usable found. On true, `out` carries the new endpoint
     * (valid) or an invalid one (keep the old URL, not a blank one).
     */
    bool refreshIfDue(uint32_t nowMs, uint32_t cooldownMs,
                      EcoStationEndpoint& out) {
        if (lastAttemptMs_ != 0 && (nowMs - lastAttemptMs_) < cooldownMs) {
            return false;
        }
        lastAttemptMs_ = nowMs;
        out = discover();
        return true;
    }

    /** Answers seen by the last query (operator log only). */
    int lastSeen() const {
        return lastSeen_;
    }

private:
    bool mdnsStarted_;
    uint32_t lastAttemptMs_;
    int lastSeen_;
};

}  // namespace EcoStation
