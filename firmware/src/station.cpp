/**
 * station.cpp — Station lifecycle + subsystem integration.
 * See station.h for the architecture. The tap pipeline, capture/upload,
 * console and visualizer are MOVED (not rewritten) from the original
 * station; only the transaction state now lives in the host-tested
 * EcoStation::StationFlow. Two behavior changes from the camera-only
 * firmware: (1) camera/Wi-Fi failures are recoverable states, never halts;
 * (2) tap is context-sensitive: with a bottle-first capture pending it
 * associates that capture (no new event); otherwise a tap answered with
 * next_step awaiting_classification ARMS the event — capture+classify on
 * the NEXT button/ENTER press or automatically after the delay.
 */
#include "station.h"

#include <HTTPClient.h>
#include <WiFi.h>

#include "esp_camera.h"
#include "esp_heap_caps.h"

namespace EcoStation {

namespace {

// Resolutions (reference values, preserved).
constexpr framesize_t kStreamResolution = FRAMESIZE_VGA;
constexpr framesize_t kCaptureResolution = FRAMESIZE_XGA;
constexpr framesize_t kInitialFrameSize = FRAMESIZE_UXGA;

/** Operator-facing name of the address source (boot log and `s`). */
const char* serverSourceName(ServerSource source) {
    switch (source) {
        case ServerSource::Console:
            return "consola";
        case ServerSource::Discovered:
            return "mDNS";
        case ServerSource::Secrets:
        default:
            return "secrets.h";
    }
}

}  // namespace

Station::Station()
    : server_(80),
      // Polarity comes from the board config — one define flips
      // inverted-polarity clone boards (see include/config/esp32cam.h).
      led_(PIN_STATION_LED, PIN_STATION_LED_ACTIVE_LOW != 0),
      wifi_(WIFI_SSID, WIFI_PASSWORD, WIFI_CONNECT_TIMEOUT_MS, WIFI_RECONNECT_INTERVAL_MS),
      api_(API_BASE_URL, READER_API_KEY, HTTP_TIMEOUT_MS),
      nfc_(PIN_RC522_SS, PIN_RC522_RST, PIN_RC522_SCK, PIN_RC522_MISO, PIN_RC522_MOSI, &tsLog_),
      debouncer_(CARD_COOLDOWN_MS),
      mode_(&operationMode_),
      console_(MODE_PASSWORD, MODE_CONSOLE_MAX_WRONG_ATTEMPTS, MODE_CONSOLE_LOCKOUT_MS),
      lines_(SERIAL_LINE_MAX_LENGTH),
      trigger_([]() { return millis(); }),
      shutter_([]() { return digitalRead(PIN_SHUTTER_BUTTON) == LOW; },
               []() { return millis(); }, SHUTTER_DEBOUNCE_MS) {}

// ---------------------------------------------------------------------------
// begin()
// ---------------------------------------------------------------------------

void Station::begin() {
    Serial.begin(115200);
    delay(1000);

    led_.begin();
    led_.indicate(FeedbackKind::BootConnecting);

    pinMode(PIN_SHUTTER_BUTTON, INPUT_PULLUP);  // shutter: button to GND
    if (PIN_CAM_BUZZER >= 0) {
        pinMode(PIN_CAM_BUZZER, OUTPUT);
        digitalWrite(PIN_CAM_BUZZER, LOW);
    }

    Serial.println();
    Serial.println("================================");
    Serial.println("ECOSTATION — ESTACIÓN ESP32-CAM");
    Serial.println("(cámara + RC522 + presencia, un solo equipo)");
    Serial.println("================================");

    if (strstr(WIFI_SSID, "YOUR_") != nullptr ||
        strstr(WIFI_PASSWORD, "YOUR_") != nullptr ||
        strstr(READER_API_KEY, "00000000000000000000000000000000") != nullptr ||
        strstr(API_BASE_URL, "localhost") != nullptr ||
        strstr(API_BASE_URL, "127.0.0.1") != nullptr) {
        Serial.println("[AVISO] include/secrets.h aún tiene valores de marcador —");
        Serial.println("        edita include/secrets.h (WIFI_SSID, WIFI_PASSWORD, API_BASE_URL, READER_API_KEY).");
        Serial.println("        API_BASE_URL debe ser la dirección LAN del servidor — 'localhost' en la");
        Serial.println("        ESP32 es la propia placa, y todas las peticiones fallarían.");
    }

    // Camera: recoverable, never FATAL — the station retries in update().
    cameraOk_ = initializeCamera();
    lastCameraAttemptMs_ = millis();
    if (!cameraOk_) {
        Serial.println("[!] falló el arranque de la cámara — la estación continúa, reintentando");
    }

    // RC522: non-fatal, self-retrying (see Rc522NfcReader).
    if (!nfc_.begin()) {
        Serial.println("[!] falló el arranque del lector NFC — se seguirá reintentando");
        Serial.println("    Tabla de cableado: docs/ESTACION.md");
    }

    const bool connected = wifi_.begin(millis());
    WiFi.setSleep(false);  // streaming station: modem-sleep off
    Serial.print("[WiFi] ");
    if (connected) {
        Serial.print("conectado — IP: ");
        Serial.println(wifi_.ip().c_str());
    } else {
        Serial.println("aún sin conexión — reintentando en segundo plano");
    }

    // The operator may have pinned a server with `s <url>` on a past boot.
    prefs_.begin("ecostation", false);
    std::string stored = prefs_.getString("server", "").c_str();
    if (!validManualServerUrl(stored, stored)) {
        stored.clear();  // corrupt/legacy value: fall back to discovery/secrets
    }
    consoleUrl_ = stored;

    selectServer();

    setupRoutes();
    server_.begin();

    printBanner();
    refreshStateLed();
    debouncer_.reset();
}

void Station::printBanner() {
    Serial.println();
    Serial.println("================================");
    Serial.println("SERVIDOR LISTO");
    Serial.print("Compilación: ");
    Serial.println(ECOSTATION_FW_BUILD);  // bench rule: every flashed change bumps this
    Serial.println("================================");
    Serial.print("Visualizador: http://");
    Serial.print(wifi_.ip().c_str());
    Serial.println("/");
    Serial.print("Lector: ");
    Serial.println(nfc_.label());
    Serial.print("Modo: ");
    Serial.println(mode_->label());
    Serial.print("Servidor: ");
    Serial.println(api_.baseUrl().c_str());  // effective URL: console, discovered or fallback
    Serial.println();
    Serial.println("Comandos seriales:");
    Serial.println("  TARJETA          cierra la captura pendiente (asocia); si no, arma tarjeta-primero y auto-captura tras la espera (BOTÓN/ENTER ya)");
    Serial.println("  ENTER            captura + subida (botella-primero si nada está armado)");
    Serial.println("  a <credential_uid>  asocia la última captura con esta tarjeta");
    Serial.println("  e <event_id>     arma la clasificación tarjeta-primero (misma auto-captura)");
    Serial.println("  c                captura local, sin subir");
    Serial.println("  s <url>          fija el servidor (http://IP:puerto; se guarda tras reiniciar)");
    Serial.println("  s                muestra la dirección actual del servidor");
    Serial.println("  s -              borra la dirección fijada y vuelve a mDNS/secrets.h");
    Serial.println("  BOTÓN (GPIO12->GND)  igual que ENTER");
    Serial.println("  CONTRASEÑA DE MODO + Enter  cambia operación/emparejamiento (enmascarada, con bloqueo)");
    Serial.printf("  Ventanas: toque-tras-captura %lus, botón-tras-toque %lus\n",
                  (unsigned long) (PENDING_CAPTURE_TIMEOUT_MS / 1000),
                  (unsigned long) (ARMED_EVENT_TIMEOUT_MS / 1000));
    Serial.println();
}

// ---------------------------------------------------------------------------
// update()
// ---------------------------------------------------------------------------

void Station::update() {
    const uint32_t now = millis();

    wifi_.tick(now);
    discovery_.begin("ecostation-cam");  // late Wi-Fi: start mDNS once it associates
    led_.tick(now);
    server_.handleClient();
    expireStaleTransactions(now);  // anti-steal windows, before any tap/button use
    pollSerial();

    if (shutter_.poll()) {
        handleCaptureCommand({CaptureCommand::Capture, ""});  // button == ENTER
    }

    // Tap → wait → auto photo. One shot per arm — a failed capture keeps
    // the arm so BUTTON/ENTER can still retry it manually.
    if (flow_.autoCaptureDue(now)) {
        handleCaptureCommand({CaptureCommand::Capture, ""});
    }

    std::string uid;
    if (nfc_.poll(uid)) {
        if (debouncer_.shouldProcess(uid, now)) {
            handleCardTap(uid);
        }
    } else {
        debouncer_.markAbsent();  // no card present → resting detection
    }

    if (!cameraOk_ && (now - lastCameraAttemptMs_) >= CAMERA_REINIT_INTERVAL_MS) {
        lastCameraAttemptMs_ = now;
        if (initializeCamera()) {
            cameraOk_ = true;
            cameraFailures_ = 0;
            Serial.println("[CAM] cámara recuperada");
        }
    }

    refreshStateLed();
}

void Station::refreshStateLed() {
    const bool degraded = !cameraOk_ || !nfc_.healthy() || !wifi_.isConnected();
    const FeedbackKind want = degraded ? FeedbackKind::StationDegraded
                              : (mode_->kind() == ModeKind::Pairing ? FeedbackKind::IdlePairing
                                                                   : FeedbackKind::IdleOperation);
    if (want != lastIndicated_) {
        lastIndicated_ = want;
        led_.indicate(want);
    }
}

// ---------------------------------------------------------------------------
// Camera (reference init + capture, preserved)
// ---------------------------------------------------------------------------

bool Station::initializeCamera() {
    camera_config_t config = {};

    config.ledc_channel = LEDC_CHANNEL_0;
    config.ledc_timer = LEDC_TIMER_0;
    config.pin_d0 = Y2_GPIO_NUM;
    config.pin_d1 = Y3_GPIO_NUM;
    config.pin_d2 = Y4_GPIO_NUM;
    config.pin_d3 = Y5_GPIO_NUM;
    config.pin_d4 = Y6_GPIO_NUM;
    config.pin_d5 = Y7_GPIO_NUM;
    config.pin_d6 = Y8_GPIO_NUM;
    config.pin_d7 = Y9_GPIO_NUM;
    config.pin_xclk = XCLK_GPIO_NUM;
    config.pin_pclk = PCLK_GPIO_NUM;
    config.pin_vsync = VSYNC_GPIO_NUM;
    config.pin_href = HREF_GPIO_NUM;
    config.pin_sccb_sda = SIOD_GPIO_NUM;
    config.pin_sccb_scl = SIOC_GPIO_NUM;
    config.pin_pwdn = PWDN_GPIO_NUM;
    config.pin_reset = RESET_GPIO_NUM;
    config.xclk_freq_hz = 20000000;
    config.pixel_format = PIXFORMAT_JPEG;

    if (psramFound()) {
        Serial.println("PSRAM detectada.");
        config.frame_size = kInitialFrameSize;
        config.jpeg_quality = 10;
        config.fb_count = 1;
        config.fb_location = CAMERA_FB_IN_PSRAM;
        config.grab_mode = CAMERA_GRAB_WHEN_EMPTY;
    } else {
        Serial.println("AVISO: sin PSRAM.");
        config.frame_size = FRAMESIZE_QVGA;
        config.jpeg_quality = 12;
        config.fb_count = 1;
        config.fb_location = CAMERA_FB_IN_DRAM;
        config.grab_mode = CAMERA_GRAB_WHEN_EMPTY;
    }

    esp_err_t err = esp_camera_init(&config);
    if (err != ESP_OK) {
        Serial.printf("Falló la inicialización de la cámara: 0x%x\n", err);
        return false;
    }

    sensor_t* sensor = esp_camera_sensor_get();
    if (sensor == nullptr) {
        Serial.println("ERROR: sensor no disponible.");
        return false;
    }

    Serial.printf("PID de la cámara: 0x%02X\n", sensor->id.PID);

    if (sensor->id.PID == OV3660_PID) {
        sensor->set_vflip(sensor, 1);
        sensor->set_brightness(sensor, 1);
        sensor->set_saturation(sensor, -2);
    }

    sensor->set_framesize(sensor, kStreamResolution);
    Serial.println("Cámara iniciada en VGA.");
    return true;
}

void Station::freeLatestCapture() {
    if (latestCapture_ != nullptr) {
        heap_caps_free(latestCapture_);
        latestCapture_ = nullptr;
    }
    latestCaptureSize_ = 0;
}

bool Station::captureHighResolution() {
    if (!cameraOk_) {
        Serial.println("[CAM] cámara caída — captura omitida, reintento pendiente");
        return false;
    }
    if (cameraBusy_) {
        Serial.println("La cámara ya está ocupada.");
        return false;
    }

    cameraBusy_ = true;

    Serial.println();
    Serial.println("================================");
    Serial.println("CAPTURA DE ALTA RESOLUCIÓN");
    Serial.println("================================");

    sensor_t* sensor = esp_camera_sensor_get();

    if (sensor == nullptr) {
        Serial.println("ERROR: sensor no disponible.");
        cameraBusy_ = false;
        return false;
    }

    Serial.println("Cambiando el sensor a XGA...");

    int result = sensor->set_framesize(sensor, kCaptureResolution);
    if (result != 0) {
        Serial.printf("ERROR: falló set_framesize: %d\n", result);
        sensor->set_framesize(sensor, kStreamResolution);
        cameraBusy_ = false;
        return false;
    }

    delay(250);

    camera_fb_t* discard = esp_camera_fb_get();
    if (discard != nullptr) {
        esp_camera_fb_return(discard);
    } else {
        Serial.println("AVISO: el primer fotograma tras el cambio no estaba disponible.");
    }

    delay(50);

    camera_fb_t* fb = esp_camera_fb_get();
    if (fb == nullptr) {
        Serial.println("ERROR: la captura falló.");
        sensor->set_framesize(sensor, kStreamResolution);
        cameraBusy_ = false;
        flagCameraFailure();
        return false;
    }

    cameraFailures_ = 0;  // a good frame clears the degraded suspicion

    Serial.printf("Capturado: %ux%u\n", fb->width, fb->height);

    freeLatestCapture();

    // Capture buffer: PSRAM first, DRAM fallback. The camera init has an
    // explicit no-PSRAM path (QVGA/DRAM), so a PSRAM-less board boots and
    // streams — hard-requiring SPIRAM here would leave it permanently
    // unable to capture ("could not allocate" on every ENTER).
    latestCapture_ = static_cast<uint8_t*>(
        heap_caps_malloc(fb->len, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT));
    if (latestCapture_ == nullptr) {
        latestCapture_ = static_cast<uint8_t*>(heap_caps_malloc(fb->len, MALLOC_CAP_8BIT));
    }

    if (latestCapture_ == nullptr) {
        Serial.println("ERROR: no se pudo asignar el búfer de captura.");
        esp_camera_fb_return(fb);
        sensor->set_framesize(sensor, kStreamResolution);
        cameraBusy_ = false;
        return false;
    }

    memcpy(latestCapture_, fb->buf, fb->len);
    latestCaptureSize_ = fb->len;
    latestCaptureId_++;

    Serial.printf("Captura guardada #%lu\n",
                  static_cast<unsigned long>(latestCaptureId_));

    esp_camera_fb_return(fb);

    sensor->set_framesize(sensor, kStreamResolution);
    delay(150);

    cameraBusy_ = false;
    return true;
}

// ---------------------------------------------------------------------------
// Capture and upload application flow (contracts preserved)
// ---------------------------------------------------------------------------

void Station::flagCameraFailure() {
    // Boot-time init is only half of "never FATAL-halt": the camera can
    // also DIE at runtime (cable glitch, sensor lock-up) — fb_get keeps
    // returning nullptr forever while cameraOk_ stayed true and the
    // re-init cadence never engaged. Consecutive failures flip the flag,
    // so update()'s existing recovery loop takes over.
    cameraFailures_++;
    if (cameraOk_ && cameraFailures_ >= CAMERA_FAILURES_BEFORE_REINIT) {
        cameraOk_ = false;
        Serial.printf("[CAM] %d fallos de captura consecutivos — reintento periódico activado\n",
                      CAMERA_FAILURES_BEFORE_REINIT);
    }
}

// Boot server selection (bounded discovery, then the manual address, then
// the compiled fallback) plus the single runtime choke point — a transport
// failure re-queries _ecostation._tcp (cooldown-guarded) and re-points the
// HTTP layer, so a DHCP-rotated server is picked up with no reboot. A manual
// address pins the server: discovery never overrides it.
void Station::selectServer() {
    discovery_.begin("ecostation-cam");
    const bool mayDiscover = shouldDiscover(MDNS_DISCOVERY != 0, consoleUrl_);

    EcoStationEndpoint found;
    if (mayDiscover && wifi_.isConnected()) {
        for (int attempt = 0; attempt < ECOSTATION_DISCOVERY_BOOT_ATTEMPTS; ++attempt) {
            found = discovery_.discover();
            if (found.valid) {
                break;
            }
        }
    }

    std::string url;
    const ServerSource source = chooseServer(consoleUrl_, found, API_BASE_URL, url);
    api_.setBaseUrl(url);
    Serial.print("[SERVIDOR] ");
    Serial.print(url.c_str());
    Serial.print(" (origen: ");
    Serial.print(serverSourceName(source));
    Serial.println(")");

    if (mayDiscover && wifi_.isConnected() && !found.valid) {
        Serial.print("[SERVIDOR] sin servicio " ECOSTATION_MDNS_LABEL
                     " — usando la reserva");
        Serial.println();
    }
}

ServerSource Station::currentServerSource() const {
    if (!consoleUrl_.empty()) {
        return ServerSource::Console;
    }
    if (api_.baseUrl() != std::string(API_BASE_URL)) {
        return ServerSource::Discovered;
    }
    return ServerSource::Secrets;
}

HttpResponse Station::post(const std::string& path, const std::string& body,
                           const std::string& contentType) {
    HttpResponse response = !contentType.empty()
                                ? api_.post(path, body, contentType)
                                : api_.post(path, body);
    if (!response.transportOk && shouldDiscover(MDNS_DISCOVERY != 0, consoleUrl_)) {
        EcoStationEndpoint endpoint;
        if (discovery_.refreshIfDue(millis(), ECOSTATION_REDISCOVER_COOLDOWN_MS,
                                    endpoint) &&
            endpoint.valid) {
            api_.setBaseUrl(endpoint.baseUrl());
            Serial.print("[SERVIDOR] servidor redescubierto: ");
            Serial.println(endpoint.baseUrl().c_str());
        }
    }
    return response;
}

HttpResponse Station::postMultipart(const std::string& path, const std::string& body,
                                    const std::string& contentType,
                                    const std::string& signingBody) {
    HttpResponse response = api_.postMultipart(path, body, contentType, signingBody);
    if (!response.transportOk && shouldDiscover(MDNS_DISCOVERY != 0, consoleUrl_)) {
        EcoStationEndpoint endpoint;
        if (discovery_.refreshIfDue(millis(), ECOSTATION_REDISCOVER_COOLDOWN_MS,
                                    endpoint) &&
            endpoint.valid) {
            api_.setBaseUrl(endpoint.baseUrl());
            Serial.print("[SERVIDOR] servidor redescubierto: ");
            Serial.println(endpoint.baseUrl().c_str());
        }
    }
    return response;
}

void Station::doCaptureAndUpload() {
    if (!captureHighResolution()) {
        return;  // capture failure IS the clear failure state (spec §8)
    }

    if (latestCapture_ == nullptr || latestCaptureSize_ == 0) {
        Serial.println("[STATION] no hay captura — nada subido");
        return;
    }

    if (flow_.routeCapture() == CaptureRoute::Classify) {
        // Card-first: classify the PRECEDING tap's event (spec §2/§8).
        // Lazy expiry first: a stale arm must never steal the new bottle.
        expireStaleTransactions(millis());
        const long eventId = flow_.armedEventId();
        if (eventId > 0) {
            Serial.printf("[STATION] clasificación tarjeta-primero para el evento %ld...\n",
                          eventId);
            std::string body = CapturePayload::classifyWithEvent(
                eventId, latestCapture_, latestCaptureSize_);
            // Multipart canonical signing: PHP reconstructs the same string
            // from the parsed upload (raw multipart never reaches it).
            std::string signing = CapturePayload::classifySigningBody(
                eventId, latestCapture_, latestCaptureSize_);
            HttpResponse r = postMultipart("/api/v1/recycling/classify", body,
                                           CapturePayload::contentType(), signing);
            reportUpload("clasificación", r.status, String(r.body.c_str()), r.transportOk);
            flow_.onClassifyResult(r.transportOk, r.status);
            if (!(r.transportOk && r.status == 200)) {
                // Upload failure (Wi-Fi drop, backend down, 5xx): the arm
                // STAYS (failures stay BUTTON/ENTER retryable), bounded by
                // ARMED_EVENT_TIMEOUT_MS.
                Serial.println("[STATION] falló la subida de clasificación — brazo conservado, BOTÓN/ENTER reintenta");
            }
            return;
        }
        // Expired just now — fall through to bottle-first below.
    }

    // Bottle-first (spec §3 Case B): image WITHOUT a student. The
    // backend holds it awaiting_card; ENTER never triggers
    // classification (the backend owns that decision — spec §4/§8).
    Serial.println("[STATION] captura botella-primero: subiendo imagen (sin tarjeta aún)...");
    std::string body = CapturePayload::imageOnly(latestCapture_, latestCaptureSize_);
    std::string signing = CapturePayload::captureSigningBody(latestCapture_, latestCaptureSize_);
    HttpResponse r = postMultipart("/api/v1/recycling/capture", body,
                                   CapturePayload::contentType(), signing);
    reportUpload("captura", r.status, String(r.body.c_str()), r.transportOk);

    if (!r.transportOk || r.status != 200) {
        return;  // keep any previous pending id so the operator can retry
    }

    const long newId = parseCaptureId(r.body);
    const long replaced = flow_.onCaptureResult(r.transportOk, r.status, newId, millis());
    if (replaced > 0 && replaced != newId) {
        Serial.printf("[STATION] reemplazando la captura pendiente %ld con %ld (la anterior expira en el servidor)\n",
                      replaced, newId);
    }
    const long pending = flow_.pendingCaptureId();
    if (pending > 0) {
        Serial.printf("[STATION] captura %ld en el servidor — toca una tarjeta dentro de %lus (o escribe 'a <credential_uid>')\n",
                      pending, (unsigned long) (PENDING_CAPTURE_TIMEOUT_MS / 1000));
    }
}

void Station::doAssociate(const std::string& uid, const HceProof& proof) {
    expireStaleTransactions(millis());
    const long target = flow_.pendingCaptureId();
    if (target <= 0) {
        Serial.println("[STATION] no hay captura pendiente — presiona ENTER primero");
        return;
    }

    std::string body = buildAssociatePayload(uid, proof);  // relayed phone proof
    std::string path = "/api/v1/recycling/captures/" + std::to_string(target) + "/associate";
    HttpResponse r = post(path, body);
    reportUpload("asociación", r.status, String(r.body.c_str()), r.transportOk);
    const bool cleared = flow_.onAssociateResult(r.transportOk, r.status, r.body);
    if (cleared) {
        if (r.transportOk && r.status == 200) {
            Serial.printf("[STATION] captura %ld asociada — liberada\n", target);
        } else {
            Serial.printf("[STATION] la captura pendiente %ld ya no existe o expiró — liberada\n",
                          target);
        }
    } else if (r.transportOk && r.status == 404) {
        // Any other 404 is an unknown/inactive CARD (mistyped 'a <uid>'),
        // which must keep the pending capture alive.
        Serial.printf("[STATION] captura %ld conservada — tarjeta rechazada, toca la tarjeta correcta\n",
                      target);
    }
    // transport/network errors keep the pending id so the operator can retry
}

void Station::expireStaleTransactions(uint32_t now) {
    const Expired expired = flow_.expire(now);
    if (expired.captureId != -1) {
        Serial.printf("[STATION] captura pendiente %ld expirada tras %lus — liberada, el próximo toque inicia un nuevo tarjeta-primero\n",
                      expired.captureId,
                      (unsigned long) (PENDING_CAPTURE_TIMEOUT_MS / 1000));
    }
    if (expired.eventId != -1) {
        Serial.printf("[STATION] evento armado %ld expirado tras %lus — liberado, el botón vuelve a ser botella-primero\n",
                      expired.eventId,
                      (unsigned long) (ARMED_EVENT_TIMEOUT_MS / 1000));
    }
}

void Station::handleCaptureCommand(const CaptureCommand& cmd) {
    expireStaleTransactions(millis());
    switch (cmd.kind) {
        case CaptureCommand::Capture:
            doCaptureAndUpload();
            break;
        case CaptureCommand::Associate:
            doAssociate(cmd.arg);
            break;
        case CaptureCommand::ArmEvent:
            // Event ids are small positive integers; anything else is a typo
            // and must be REFUSED, or the operator waits forever for a photo
            // that no armed state will ever produce.
            if (!flow_.armFromConsole(cmd.arg, millis())) {
                Serial.println("[STATION] id de evento inválido — el modo tarjeta-primero necesita un id positivo del registro");
                return;
            }
            Serial.printf("[STATION] clasificación tarjeta-primero armada para el evento %ld (auto-captura en %lus, BOTÓN/ENTER ya)\n",
                          flow_.armedEventId(),
                          (unsigned long) (CARD_FIRST_AUTO_CAPTURE_DELAY_MS / 1000));
            break;
        case CaptureCommand::LocalOnly:
            captureHighResolution();
            break;
        case CaptureCommand::ServerUrl: {
            if (cmd.arg.empty()) {
                Serial.print("[SERVIDOR] actual: ");
                Serial.print(api_.baseUrl().c_str());
                Serial.print(" (origen: ");
                Serial.print(serverSourceName(currentServerSource()));
                Serial.println(")");
                return;
            }
            if (cmd.arg == "-") {
                prefs_.remove("server");
                consoleUrl_.clear();
                Serial.println("[SERVIDOR] dirección de consola borrada — se vuelve a mDNS/secrets.h");
                selectServer();
                return;
            }
            std::string norm;
            if (!validManualServerUrl(cmd.arg, norm)) {
                Serial.println("[SERVIDOR] dirección inválida — usa http://IP:puerto de la LAN (nunca localhost)");
                return;
            }
            prefs_.putString("server", norm.c_str());
            consoleUrl_ = norm;
            api_.setBaseUrl(norm);
            Serial.print("[SERVIDOR] dirección fijada: ");
            Serial.print(norm.c_str());
            Serial.println(" (se guarda tras reiniciar; 's -' la borra)");
            return;
        }
        case CaptureCommand::None:
        default:
            Serial.println("[STATION] comando desconocido. Comandos: ENTER=capturar+subir | a <uid>=asociar | e <evento>=tarjeta-primero | c=captura local | s <url>=fijar servidor");
            break;
    }
}

void Station::chirpSuccess() {
    if (PIN_CAM_BUZZER < 0) {
        return;  // absent on this bench (no free pin; GPIO4 is the flash LED)
    }
    digitalWrite(PIN_CAM_BUZZER, HIGH);
    delay(120);
    digitalWrite(PIN_CAM_BUZZER, LOW);
}

void Station::reportUpload(const char* what, int status, const String& body, bool transportOk) {
    FeedbackSignal signal;
    if (transportOk && status == 200) {
        chirpSuccess();
        signal.kind = FeedbackKind::CaptureSuccess;
        led_.showEvent(signal);
    } else if (!transportOk) {
        signal.kind = FeedbackKind::NetworkError;
        led_.showEvent(signal);
    } else {
        signal.kind = FeedbackKind::ServerError;
        led_.showEvent(signal);
    }
    if (!transportOk) {
        Serial.printf("[STATION] %s: ERROR DE RED (transporte). Revisa Wi-Fi y API_BASE_URL.\n", what);
        return;
    }
    Serial.printf("[STATION] %s: HTTP %ld\n", what, (long) status);
    Serial.println(body);
}

// ---------------------------------------------------------------------------
// Serial: mode password + capture trigger share one terminal.
// Masked echo (never print typed secrets). Empty lines are console-Ignored
// and fall through to the capture trigger, so ENTER still captures — even
// during a password lockout. Non-empty wrong lines are console feedback
// only and never fire capture commands. A CRLF pair counts as ONE Enter
// (pollSerial drops the \n after a \r) — otherwise every typed line +
// Enter would ghost-fire a capture on its own tail. ENTER captures only
// when the line is otherwise empty.
// ---------------------------------------------------------------------------

void Station::pollSerial() {
    const uint32_t now = millis();
    while (Serial.available() > 0) {
        const char c = static_cast<char>(Serial.read());
        if (c == '\n' && prevSerialWasCR_) {
            // CRLF tail, not a second ENTER — the \r already completed
            // the line (PlatformIO/Arduino monitors send \r\n per Enter).
            prevSerialWasCR_ = false;
            continue;
        }
        prevSerialWasCR_ = (c == '\r');
        if (c != '\n' && c != '\r') {
            Serial.print('*');  // masked echo: never print what was typed
        }
        std::string line;
        if (lines_.feed(c, line)) {
            Serial.println();  // end the asterisk row on Enter
            if (lines_.overflowed()) {
                Serial.println("[MODE] entrada demasiado larga — línea descartada");
            }
            dispatchSerialLine(line, now);
        }
    }
}

void Station::dispatchSerialLine(const std::string& line, uint32_t now) {
    if (line.empty()) {
        // Bare ENTER skips the console (Ignored by design) and goes
        // straight to the trigger — with its key-repeat cooldown.
        CaptureCommand cmd = trigger_.feed('\n');
        if (cmd.kind != CaptureCommand::None) {
            handleCaptureCommand(cmd);
        }
        return;
    }

    // COMMAND GRAMMAR FIRST: a command-shaped line (c, a <uid>,
    // e <id>, s <url>) is dispatched as a capture command; the trigger
    // answers None for anything else (the mode password included), and only
    // then does the password console judge the line. A mode password that
    // collides with the command grammar is consumed as the command — pick a
    // password outside those shapes.
    CaptureCommand cmd = trigger_.feedLine(line);
    if (cmd.kind != CaptureCommand::None) {
        handleCaptureCommand(cmd);
        return;
    }

    const ConsoleResult result = console_.handleLine(line, now);
    switch (result) {
        case ConsoleResult::Accepted:
            switchMode();
            return;  // a password is never a capture command
        case ConsoleResult::Rejected:
            Serial.println();
            Serial.print("[MODE] clave incorrecta — ");
            if (console_.lockedOut(now)) {
                Serial.print("entrada bloqueada durante ");
                Serial.print(MODE_CONSOLE_LOCKOUT_MS / 1000);
                Serial.println(" s");
            } else {
                Serial.print(MODE_CONSOLE_MAX_WRONG_ATTEMPTS - console_.wrongAttempts());
                Serial.println(" intento(s) restante(s)");
            }
            showModeEvent(FeedbackKind::ModeRejected);
            return;
        case ConsoleResult::LockedOut:
            Serial.println();
            Serial.println("[MODE] entrada bloqueada — espera la cuenta atrás");
            showModeEvent(FeedbackKind::ModeRejected);
            return;
        case ConsoleResult::Ignored:
        default:
            return;  // nothing else can claim a non-empty line
    }
}

void Station::showModeEvent(FeedbackKind kind) {
    FeedbackSignal signal;
    signal.kind = kind;
    led_.showEvent(signal);
}

void Station::switchMode() {
    mode_ = (mode_->kind() == ModeKind::Pairing) ? static_cast<Mode*>(&operationMode_)
                                                : static_cast<Mode*>(&pairingMode_);
    Serial.println();
    Serial.print("[MODE] cambiado a: ");
    Serial.println(mode_->label());
    Serial.print("[MODE] ");
    Serial.print(mode_->hint());
    Serial.println();
    showModeEvent(FeedbackKind::ModeSwitched);
    refreshStateLed();
    debouncer_.reset();  // a tap in flight must not straddle the switch
    flow_.reset();       // pending bottle + armed card-first never cross modes
    // Only PAIRING asks a phone for its key (ENROLL).
    nfc_.setEnrollment(mode_->kind() == ModeKind::Pairing, READER_API_KEY);
}

// ---------------------------------------------------------------------------
// RFID → presence pipeline + station transaction:
// tap closes a pending bottle-first capture (associate), else a tap with
// next_step awaiting_classification ARMS the event for the auto/BUTTON
// capture (classifyWithEvent).
// ---------------------------------------------------------------------------

void Station::printReaderKeyRemediation() {
    Serial.println("     READER_API_KEY no tiene una fila de lector en el servidor —");
    Serial.println("     arreglo: docs/ESTACION.md (provisionamiento)");
}

void Station::handleCardTap(const std::string& uid) {
    Serial.println();
    Serial.print("[NFC] tarjeta: ");
    Serial.println(uid.c_str());

    // Lazy expiry first: an expired pending must not steal this tap.
    expireStaleTransactions(millis());

    // Pairing never associates a pending bottle; a pending bottle IS the
    // associate (no new tap event, no arming).
    if (flow_.routeTap(mode_->kind()) == TapRoute::Associate) {
        Serial.printf("[STATION] el toque cierra la captura pendiente %ld — asociando, sin evento nuevo\n",
                      flow_.pendingCaptureId());
        doAssociate(uid, nfc_.lastProof());
        return;
    }

    // Same kind contract as the reader main: "hce" (phone authenticated
    // through the APDU exchange) reaches pairing as credential_kind; the
    // tap/associate lookups stay credential_uid-only (RF UID ≠ identity).
    ApiCall call = mode_->onCardTap(uid, nfc_.lastKind(), nfc_.lastProof());
    HttpResponse response = post(call.path, call.jsonBody);

    if (call.type == ApiCallType::Tap) {
        TapResult result = parseTapResponse(response.status, response.body);
        FeedbackSignal signal = operationMode_.interpret(result);

        switch (result.outcome) {
            case TapOutcome::Success:
                Serial.print("[OK] evento registrado — ");
                Serial.print(result.studentFirstName.c_str());
                Serial.print(" (");
                Serial.print(result.eventType.c_str());
                Serial.println(")");
                if (result.awaitingClassification) {
                    Serial.println("[i] siguiente paso: awaiting_classification");
                }
                break;
            case TapOutcome::CardNotRecognized:
                Serial.print("[404] ");
                Serial.println(result.message.c_str());
                Serial.println("     ¿tarjeta sin emparejar? cambia a EMPAREJAR y arma una sesión —");
                Serial.println("     docs/ESTACION.md");
                break;
            case TapOutcome::AuthFailure:
                Serial.println("[401] clave de lector rechazada");
                printReaderKeyRemediation();
                break;
            case TapOutcome::NetworkError:
                Serial.println("[NET] fallo de red");
                break;
            default:
                Serial.print("[ERR] inesperado: ");
                Serial.println(result.message.c_str());
                break;
        }
        led_.showEvent(signal);

        // Station transaction (card-first, auto): identity resolved AND
        // the backend wants a photo for this event → ARM it. update()
        // auto-captures+classifies once the delay elapses; BUTTON/ENTER
        // captures immediately without waiting.
        switch (flow_.onTapResult(result, millis())) {
            case ArmResult::Armed:
                Serial.printf("[STATION] tarjeta-primero armado para el evento %ld — auto-captura en %lus (BOTÓN/ENTER ya)\n",
                              (long) result.eventId,
                              (unsigned long) (CARD_FIRST_AUTO_CAPTURE_DELAY_MS / 1000));
                break;
            case ArmResult::AlreadyArmed:
                // Re-tap of the SAME event (backend dedup, or a retry after
                // a failed upload): keep the current arm exactly as it is.
                Serial.printf("[STATION] el evento %ld ya está armado — captura pendiente, sin rearme\n",
                              (long) result.eventId);
                break;
            case ArmResult::Replaced:
                Serial.printf("[STATION] reemplazando el evento armado por %ld (el anterior expira sin clasificar en el servidor)\n",
                              (long) result.eventId);
                break;
            case ArmResult::NotArmed:
            default:
                break;
        }
    } else {  // ApiCallType::PairCard
        PairResult result = parsePairResponse(response.status, response.body);
        FeedbackSignal signal = pairingMode_.interpret(result);

        switch (result.outcome) {
            case PairOutcome::Success:
                Serial.print("[OK] tarjeta emparejada con: ");
                Serial.println(result.pairedStudentName.c_str());
                break;
            case PairOutcome::NoActiveSession:
                Serial.print("[409] ");
                Serial.println(result.message.c_str());
                Serial.println("     arma primero una sesión y acerca la tarjeta dentro de la ventana —");
                Serial.println("     docs/ESTACION.md");
                break;
            case PairOutcome::AlreadyPaired:
                Serial.print("[422] ");
                Serial.println(result.message.c_str());
                Serial.println("     usa una tarjeta NUEVA — la sesión sigue armada");
                break;
            case PairOutcome::ProofRejected:
                // The phone's handed-over key did not verify its own proof
                // (or it was replayed). Session stays armed.
                Serial.print("[403] ");
                Serial.println(result.message.c_str());
                Serial.println("     en el teléfono toca «Vincular este teléfono» y acerca de nuevo");
                break;
            case PairOutcome::AuthFailure:
                Serial.println("[401] clave de lector rechazada");
                printReaderKeyRemediation();
                break;
            case PairOutcome::NetworkError:
                Serial.println("[NET] fallo de red");
                break;
            default:
                Serial.print("[ERR] inesperado: ");
                Serial.println(result.message.c_str());
                break;
        }
        led_.showEvent(signal);
    }
}

// ---------------------------------------------------------------------------
// Visualizer (reference routes and JSON keys, preserved)
// ---------------------------------------------------------------------------

void Station::setupRoutes() {
    server_.on("/", HTTP_GET, [this]() { handleRoot(); });
    server_.on("/stream-frame", HTTP_GET, [this]() { handleStreamFrame(); });
    server_.on("/capture.jpg", HTTP_GET, [this]() { handleCaptureImage(); });
    server_.on("/capture-status", HTTP_GET, [this]() { handleCaptureStatus(); });
    server_.onNotFound([this]() {
        server_.send(404, "text/plain", "No encontrado");
    });
}

void Station::sendJPEG(const uint8_t* data, size_t length) {
    server_.setContentLength(length);
    server_.send(200, "image/jpeg", "");

    WiFiClient client = server_.client();

    size_t written = 0;
    while (written < length && client.connected()) {
        size_t chunk = length - written;
        if (chunk > 1460) {
            chunk = 1460;
        }
        size_t n = client.write(data + written, chunk);
        if (n == 0) {
            break;
        }
        written += n;
        delay(1);
    }
}

void Station::handleStreamFrame() {
    if (cameraBusy_) {
        server_.send(503, "text/plain", "Cámara ocupada");
        return;
    }
    if (!cameraOk_) {
        server_.send(503, "text/plain", "Cámara caída");
        return;
    }

    camera_fb_t* fb = esp_camera_fb_get();
    if (fb == nullptr) {
        server_.send(500, "text/plain", "La captura falló");
        return;
    }

    sendJPEG(fb->buf, fb->len);
    esp_camera_fb_return(fb);
}

void Station::handleCaptureImage() {
    if (latestCapture_ == nullptr || latestCaptureSize_ == 0) {
        server_.send(404, "text/plain", "No hay captura");
        return;
    }

    sendJPEG(latestCapture_, latestCaptureSize_);
}

void Station::handleCaptureStatus() {
    String json;
    json.reserve(192);
    json += "{\"id\":";
    json += String(latestCaptureId_);
    json += ",\"size\":";
    json += String(latestCaptureSize_);
    json += ",\"available\":";
    json += (latestCapture_ != nullptr ? "true" : "false");
    json += ",\"busy\":";
    json += (cameraBusy_ ? "true" : "false");
    json += ",\"camera\":";
    json += (cameraOk_ ? "\"ready\"" : "\"error\"");
    json += ",\"nfc\":";
    json += (nfc_.healthy() ? "\"ready\"" : "\"error\"");
    json += ",\"wifi\":";
    json += (wifi_.isConnected() ? "\"online\"" : "\"offline\"");
    json += "}";

    server_.send(200, "application/json", json);
}

void Station::handleRoot() {
    const char* html = R"rawliteral(<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>EcoStation — Estación</title>
<style>
body{margin:0;padding:24px;background:#111;color:#eee;font-family:system-ui,sans-serif}
.container{width:min(1100px,100%);margin:auto}
h1{margin-top:0}
.section{margin-top:30px}
.image-box{background:#000;border-radius:10px;overflow:hidden}
.image-box img{display:block;width:100%;height:auto}
.placeholder{min-height:250px;display:flex;align-items:center;justify-content:center;color:#777;text-align:center}
.status{margin-top:12px;padding:10px;border-radius:6px;background:#222;font-family:monospace}
</style>
</head>
<body>
<div class="container">
<h1>EcoStation — Estación ESP32-CAM</h1>
<div class="section">
<h2>Vista en vivo</h2>
<div class="image-box"><img id="stream" alt="Cámara en vivo"></div>
</div>
<div class="section">
<h2>Última captura</h2>
<div class="image-box">
<div id="placeholder" class="placeholder">Toca una tarjeta o presiona ENTER en el terminal serie para capturar y subir.</div>
<img id="capture" style="display:none" alt="Última captura">
</div>
<div id="status" class="status">Esperando...</div>
</div>
</div>
<script>
const stream=document.getElementById("stream");
async function updateStream(){try{const r=await fetch("/stream-frame?t="+Date.now(),{cache:"no-store"});if(!r.ok)throw new Error("HTTP "+r.status);const b=await r.blob();const u=URL.createObjectURL(b);stream.onload=()=>URL.revokeObjectURL(u);stream.src=u}catch(e){console.log("Error del flujo:",e)}setTimeout(updateStream,200)}
updateStream();
const capture=document.getElementById("capture");
const placeholder=document.getElementById("placeholder");
const status=document.getElementById("status");
let lastCaptureId=0;
async function checkCapture(){try{const r=await fetch("/capture-status?t="+Date.now(),{cache:"no-store"});const d=await r.json();if(d.available){if(d.id!==lastCaptureId){lastCaptureId=d.id;capture.src="/capture.jpg?t="+Date.now();capture.style.display="block";placeholder.style.display="none"}status.textContent="Captura #"+d.id+" | "+d.size+" bytes | cam:"+d.camera+" nfc:"+d.nfc+" wifi:"+d.wifi}else{status.textContent="Aún no hay capturas. cam:"+d.camera+" nfc:"+d.nfc+" wifi:"+d.wifi}}catch(e){console.log("Error del estado de captura:",e)}setTimeout(checkCapture,500)}
checkCapture();
</script>
</body>
</html>)rawliteral";

    server_.send(200, "text/html", html);
}

}  // namespace EcoStation
