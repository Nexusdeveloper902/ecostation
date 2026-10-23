/**
 * station.h — the ESP32-CAM STATION: camera + RC522 + network + application
 * as ONE integrated device (env `esp32cam`, -DCAMERA_STATION).
 *
 * Lifecycle:
 *   begin()  → serial → status LED → shutter pin → camera (non-fatal) →
 *              RC522 (non-fatal, self-retrying) → Wi-Fi (bounded) →
 *              server selection → visualizer → banner. A failed peripheral
 *              NEVER halts the station; it retries in update() while the
 *              rest stays alive.
 *   update() → wifi.tick → led.tick → visualizer → serial console/capture
 *              trigger → shutter button → RC522 poll → presence tap
 *              pipeline → camera re-init retry. Non-blocking (bounded
 *              HTTP POSTs and capture delays excepted, as before).
 *
 * Application flow: an RFID tap resolves identity through the SAME
 * presence tap endpoint the reader uses, EXCEPT when a bottle-first
 * capture is pending (awaiting_card): then the tap UID associates that
 * capture directly (no new event). Otherwise, when the backend answers
 * next_step awaiting_classification with an event id, the station ARMS
 * that event — update() auto-captures+classifies once
 * CARD_FIRST_AUTO_CAPTURE_DELAY_MS elapses (card-first, auto;
 * BUTTON/ENTER captures immediately). A button/ENTER with nothing armed stays
 * bottle-first (capture → hold awaiting_card → tap/`a <uid>` associates).
 * Pairing mode, console password, debounce, signed requests and
 * multipart wire bytes are reused unchanged from the existing libs.
 *
 * Transaction state lives in EcoStation::StationFlow (host-tested): this
 * file only maps each branch onto it.
 */
#pragma once

#include <Arduino.h>
#include <Preferences.h>
#include <WebServer.h>

#include <string>

#include "config.h"

// Station credentials live ONLY in gitignored include/secrets.h.
// Fresh-checkout guard: without it we compile against the example
// placeholders and fail Wi-Fi/HTTP gracefully.
#if __has_include("secrets.h")
#include "secrets.h"
#else
#warning "no se encontró include/secrets.h — se compila con los valores de ejemplo (cp include/secrets.h.example include/secrets.h)"
#include "secrets.h.example"
#endif

#ifndef MODE_PASSWORD
#warning "MODE_PASSWORD no está definido — se usa un valor inseguro por defecto; añádelo a include/secrets.h."
#define MODE_PASSWORD "CHANGE-ME-MODE-PW"
#endif

// mDNS discovery on/off. 1 = query _ecostation._tcp at boot and on transport
// failure; 0 = use only the manual address (school networks that block
// multicast). secrets.h may override it.
#ifndef MDNS_DISCOVERY
#define MDNS_DISCOVERY 1
#endif

#include "CapturePayload.h"
#include "CaptureTrigger.h"
#include "CardDebouncer.h"
#include "CoreTypes.h"
#include "EcoStationDiscovery.h"
#include "EcoStationEndpoint.h"
#include "EspApiClient.h"
#include "ModeConsole.h"
#include "Modes.h"
#include "NfcReader.h"
#include "PayloadBuilder.h"
#include "Rc522NfcReader.h"
#include "ResponseParser.h"
#include "StationFlow.h"
#include "StationLed.h"
#include "TsLog.h"  // millis() prefix on reader diagnostics (bench correlation)
#include "WifiService.h"

namespace EcoStation {

class Station {
public:
    Station();
    void begin();
    void update();

private:
    // --- lifecycle helpers ------------------------------------------------
    void printBanner();
    void refreshStateLed();  // degraded vs idle, only on change
    bool initializeCamera();
    void showModeEvent(FeedbackKind kind);
    void switchMode();

    // --- serial: console password + capture trigger share one terminal ----
    void pollSerial();
    void dispatchSerialLine(const std::string& line, uint32_t now);

    // --- RFID → presence pipeline -----------------------------------------
    void handleCardTap(const std::string& uid);
    void printReaderKeyRemediation();

    // --- EcoStation server discovery + manual address ---------------------
    // Boot: DNS-SD first (unless the operator pinned an address), the
    // manual console address, then the compiled fallback. Also re-run by
    // `s -` after clearing the stored address.
    void selectServer();
    /** Where the address the device is dialing came from (log only). */
    ServerSource currentServerSource() const;
    // Single HTTP choke point: every backend POST here runs through it, so
    // a transport failure triggers one cooldown-guarded rediscovery for all
    // callers (tap, capture, classify, associate) instead of per-callsite
    // guards. The failed POST is NOT retried (not idempotent).
    HttpResponse post(const std::string& path, const std::string& body,
                      const std::string& contentType = "");
    // Multipart image posts: same choke point, but signed over the multipart
    // canonical (CapturePayload signing bodies), not the wire bytes — PHP
    // never sees raw multipart (php://input is empty). JSON posts keep
    // signing the exact bytes via post().
    HttpResponse postMultipart(const std::string& path, const std::string& body,
                               const std::string& contentType,
                               const std::string& signingBody);

    // --- capture and upload -----------------------------------------------
    void freeLatestCapture();
    bool captureHighResolution();
    void flagCameraFailure();  // repeated runtime fb_get failures → re-init path
    void doCaptureAndUpload();
    void doAssociate(const std::string& uid, const HceProof& proof = HceProof());
    void handleCaptureCommand(const CaptureCommand& cmd);
    void expireStaleTransactions(uint32_t now);  // anti-steal timeouts
    void reportUpload(const char* what, int status, const String& body, bool transportOk);
    void chirpSuccess();  // no-op while PIN_CAM_BUZZER is -1 (no free pin on this bench)

    // --- visualizer ---------------------------------------------------------
    void setupRoutes();
    void sendJPEG(const uint8_t* data, size_t length);
    void handleStreamFrame();
    void handleCaptureImage();
    void handleCaptureStatus();
    void handleRoot();

    // --- members: ONE of each subsystem (this is the station) --------------
    WebServer server_;
    Preferences prefs_;  // NVS: the operator's `s <url>` address survives reboots
    StationLed led_;
    WifiService wifi_;
    EspApiClient api_;
    EcoStationDiscovery discovery_;  // server address via DNS-SD
    // Declared before nfc_: members construct in declaration order.
    TsLog tsLog_{Serial};  // timestamped diagnostics sink (bench ask)
    Rc522NfcReader nfc_;
    CardDebouncer debouncer_;
    OperationMode operationMode_;
    PairingMode pairingMode_;
    Mode* mode_;
    ModeConsole console_;
    LineBuffer lines_;
    bool prevSerialWasCR_ = false;  // CRLF collapse: \n right after \r is one Enter
    TerminalCaptureTrigger trigger_;
    ButtonCaptureTrigger shutter_;

    // The card-first and bottle-first transaction state (host-tested).
    StationFlow flow_{{PENDING_CAPTURE_TIMEOUT_MS, ARMED_EVENT_TIMEOUT_MS,
                       CARD_FIRST_AUTO_CAPTURE_DELAY_MS}};

    // Manual server address typed with `s <url>` ("" = not pinned).
    std::string consoleUrl_;

    // capture state (was file-static in the camera main)
    uint8_t* latestCapture_ = nullptr;
    size_t latestCaptureSize_ = 0;
    uint32_t latestCaptureId_ = 0;
    volatile bool cameraBusy_ = false;

    // recoverable subsystem state (never FATAL-halt)
    bool cameraOk_ = false;
    uint32_t lastCameraAttemptMs_ = 0;
    int cameraFailures_ = 0;  // consecutive fb_get failures → CAMERA_FAILURES_BEFORE_REINIT flips cameraOk_
    FeedbackKind lastIndicated_ = FeedbackKind::BootConnecting;
};

}  // namespace EcoStation
