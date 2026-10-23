# EcoStation Firmware Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the Pulse ESP32-CAM recycling station (`esp32cam` env) and its host tests into `firmware/` of the EcoStation monorepo. Rename it to EcoStation, make it Spanish-only, keep the HCE and signing wire protocols byte-identical, and add a manual server-address fallback.

**Architecture:** Same layering as `B2B-Firmware`. `lib/StationCore/` (renamed from `PresenceCore`) is pure C++ covered by the `native` Unity suite. Thin Arduino libs (`NfcReader`, `Feedback`, `ApiClient`, `WifiService`, `Discovery`) wrap the hardware. `src/station.{h,cpp}` is the composition root. One new pure piece, `StationFlow`, lifts the card-first/bottle-first transaction state machine out of `station.cpp` so the native suite can test both flows. Pulse inlined that logic and never tested it.

**Tech Stack:** PlatformIO, Arduino-ESP32 (`board = esp32cam`), `espressif/esp32-camera`, `miguelbalboa/MFRC522@^1.4.11`, `bblanchon/ArduinoJson@^7.4.3`, Unity (native), bash + g++ + curl for the e2e script.

**Spec:** `/home/jperez/Documents/EcoStation/docs/superpowers/specs/2026-10-23-ecostation-design.md`
**Port source (read-only):** `/home/jperez/Documents/B2B/B2B-Firmware` (written below as `$SRC`). Monorepo root: `/home/jperez/Documents/EcoStation` (written below as `$ROOT`). Firmware dir: `$ROOT/firmware` (written below as `$FW`).

## Global Constraints

- Envs: only `esp32cam` (default) and `native`. Do not port `esp32dev`, `esp32dev-mock`, `esp32cam-reader`, `src/main.cpp` (the reader), `MockSerialNfcReader.h`, `LedFeedbackController.h`, `config/esp32dev.h`, `config/esp32cam_reader.h`, `secrets.camera.h*`, `secrets.cam_reader.h*`, `test_cam_reader_config.cpp`, or `scripts/flash.sh`.
- Do not port `bearerAuthorizationValue` or `test_auth.cpp`. Devices sign every request with Pulse-HMAC (Pulse ADR-016), and Bearer was only a bench path.
- **Never copy, open into the plan, or commit** `$SRC/include/secrets.h`, `$SRC/include/secrets.camera.h` or `$SRC/include/secrets.cam_reader.h`. The only tracked template is `$FW/include/secrets.h.example`, with placeholder values only.
- Station API contract (unchanged from B2B-Core):
  - `POST /api/v1/events/tap`
  - `POST /api/v1/recycling/classify` (multipart: `event_id` and `image`)
  - `POST /api/v1/recycling/capture` (multipart: `image`)
  - `POST /api/v1/recycling/captures/{id}/associate` (JSON)
  - `POST /api/v1/admin/cards/pair` (JSON)
- **Wire constants that stay byte-identical. Do NOT rename them:**
  - the `"Pulse-HMAC "` Authorization scheme;
  - the key-wrap label `"pulse-hce-key-wrap/v1\n"`;
  - AID `F0 01 02 03 04 05 06`;
  - CHALLENGE `80 10 00 00`, ENROLL `80 20 00 00 20`, status word `6A88`, and the `"HCE-OK"` SELECT payload;
  - the multipart signing canonicals `"event_id=<id>\nimage.sha256=<hex>"` and `"image.sha256=<hex>"`;
  - every literal in the HCE and signer test vectors (`PLS-K3Y7V3CT0R5Z`, `test-secret-000000000000000001`, `ba6d0fbf…a295`, `c5bb9fe1…14c2`, `b46b73a6f9444ea4`, `4ec7367d…c4f1`).
- Rename table, applied to everything ported:

  | Pulse | EcoStation |
  |---|---|
  | `namespace Presence` / `Presence::` | `namespace EcoStation` / `EcoStation::` |
  | `lib/PresenceCore` (+ `library.json` `"name"`) | `lib/StationCore` |
  | `PulseEndpoint`, `PulseCandidate`, `pickPulseEndpoint`, `pulseProtocolCompatible` | `EcoStationEndpoint`, `EcoStationCandidate`, `pickEcoStationEndpoint`, `ecoStationProtocolCompatible` |
  | `lib/PulseDiscovery/src/PulseDiscovery.h`, `PulseDiscovery` | `lib/Discovery/src/EcoStationDiscovery.h`, `EcoStationDiscovery` |
  | `PULSE_MDNS_SERVICE_NAME "pulse"` | `ECOSTATION_MDNS_SERVICE_NAME "ecostation"` |
  | `PULSE_MDNS_SERVICE_PROTO`, `PULSE_DEFAULT_API_PORT`, `PULSE_PROTOCOL_VERSION` | `ECOSTATION_MDNS_SERVICE_PROTO`, `ECOSTATION_DEFAULT_API_PORT`, `ECOSTATION_PROTOCOL_VERSION` (values unchanged) |
  | `PULSE_MDNS_LABEL "_pulse._tcp.local"` | `ECOSTATION_MDNS_LABEL "_ecostation._tcp.local"` |
  | `PULSE_DISCOVERY_BOOT_ATTEMPTS`, `PULSE_REDISCOVER_COOLDOWN_MS` | `ECOSTATION_DISCOVERY_BOOT_ATTEMPTS`, `ECOSTATION_REDISCOVER_COOLDOWN_MS` |
  | `PULSE_FW_BUILD "hce.19"` | `ECOSTATION_FW_BUILD "eco.1"` (bump on every behaviour-changing flash) |
  | `test_pulse_endpoint.cpp`, `runPulseEndpointTests` | `test_endpoint.cpp`, `runEndpointTests` |
  | mDNS hostname `"pulse-station"` | `"ecostation-cam"` |
- **Spanish only.** Every string printed on Serial, every web-visualizer string, the mode labels and hints, and every doc are Spanish. Use no `[EN]`/`[ES]` prefixes and no `"english / español"` pairs. Code comments are not user-facing: leave the ported comments as they are.
- Do not change the timing values: `CARD_FIRST_AUTO_CAPTURE_DELAY_MS 5000`, `PENDING_CAPTURE_TIMEOUT_MS 90000`, `ARMED_EVENT_TIMEOUT_MS 90000`, `CARD_COOLDOWN_MS 2000`, `HTTP_TIMEOUT_MS 10000`, and `MFRC522_SPICLOCK=1000000u`.
- Do not change the pins: RC522 SS 13 / SCK 14 / MOSI 15 / MISO 2 / RST -1, LED GPIO33 active-LOW, shutter GPIO12 to GND. Never drive GPIO4.
- `monitor_dtr = 0` and `monitor_rts = 0` on `esp32cam` (the AI-Thinker auto-download circuit).

## Review Focus

1. **The bottle-first capture expired before the tap (more than 90 s).** The tap must take the normal `/events/tap` path and arm card-first; it must not associate a stale capture. Pinned in Task 5 by `stale_pending_capture_does_not_steal_the_tap`.
2. **The same card re-taps while its event is armed** (the debounce lets it through after 2 s, and the backend dedups to the same `event_id`). There must be no re-arm, and the auto photo must not fire twice. Pinned in Task 5 by `retap_of_armed_event_keeps_the_arm`.
3. **The classify upload fails (Wi-Fi drop or 5xx).** The arm stays, the auto-capture does not refire, BUTTON/ENTER retries, and a 200 clears the arm. Pinned in Task 5 by `failed_classify_keeps_arm_without_refiring_auto_capture`.
4. **The operator types a bad server address** (`localhost`, `127.0.0.1`, `https://…`, garbage, or a port out of range). The address is rejected and the current one is left untouched. Pinned in Task 4 by `manual_url_rejects_unusable_addresses`.
5. **A mode switch happens mid-transaction** (pending bottle or armed event, then PAIRING). Both are cleared, and in PAIRING every tap routes to pair, never to associate. Pinned in Task 5 by `pairing_mode_never_associates` and `reset_clears_everything`.

---

### Task 1: Scaffold `firmware/`: build envs, config, secrets template, test runner

**Model:** haiku

**Files:**
- Create: `$FW/platformio.ini`
- Create: `$FW/.gitignore`
- Create: `$FW/include/config.h`, `$FW/include/config/common.h`, `$FW/include/config/esp32cam.h`
- Create: `$FW/include/secrets.h.example`
- Create: `$FW/lib/StationCore/library.json`
- Create: `$FW/src/main.cpp` (temporary stub; Task 8 replaces it)
- Create: `$FW/test/test_main.cpp`, `$FW/test/test_station_config.cpp`

**Interfaces:**
- Produces:
  - the config macros listed in Global Constraints;
  - `include/config.h`, which includes `config/esp32cam.h` and defines `ECOSTATION_FW_BUILD`;
  - `include/config/esp32cam.h`, which merges Pulse's `esp32cam_board.h` and `esp32cam.h` into one file and includes `config/common.h`;
  - `secrets.h` macros `WIFI_SSID`, `WIFI_PASSWORD`, `API_BASE_URL`, `MDNS_DISCOVERY`, `READER_API_KEY` and `MODE_PASSWORD`;
  - `test_main.cpp`, which declares and calls one `run<X>Tests()` per test file (later tasks add their lines).

- [ ] **Step 1: Write `platformio.ini`.**
  - Base it on `$SRC/platformio.ini` and keep only `[platformio] default_envs = esp32cam`, `[env]`, `[env:esp32cam]` and `[env:native]`.
  - For `esp32cam`, remove `build_src_filter`: `src/` holds only the station.
  - Remove the duplicated `-DMFRC522_SPICLOCK` line from `esp32cam`; `[env]` already sets it.
  - Rewrite the header comment in Spanish.
  - `native` keeps `test_framework = unity`, `build_src_filter = -<*>` and `lib_deps = bblanchon/ArduinoJson@^7.4.3`.
- [ ] **Step 2: Write `.gitignore`.** Copy `$SRC/.gitignore` and keep only the `.pio/`/`.vscode` lines, `include/secrets.h`, `secrets.h`, `*.log`, `*.bak` and `*.tmp`.
- [ ] **Step 3: Write `include/config/common.h`.** Copy it from `$SRC/include/config/common.h` and apply the rename table.
- [ ] **Step 4: Write `include/config/esp32cam.h`.**
  - Concatenate the defines from `$SRC/include/config/esp32cam_board.h` and `$SRC/include/config/esp32cam.h`.
  - Drop the `#include "config/esp32cam_board.h"` and every reference to `esp32cam-reader`.
  - Keep every value byte-identical.
- [ ] **Step 5: Write `include/config.h`.** Use `#pragma once`, `#include "config/esp32cam.h"` and `#define ECOSTATION_FW_BUILD "eco.1"`. Drop the board dispatch.
- [ ] **Step 6: Write `include/secrets.h.example`.** Write it in Spanish, with placeholders only:
  ```c
  #pragma once
  #define WIFI_SSID      "YOUR_WIFI_SSID"
  #define WIFI_PASSWORD  "YOUR_WIFI_PASSWORD"
  // Dirección MANUAL del servidor: se usa cuando mDNS (_ecostation._tcp) no
  // responde, o siempre si MDNS_DISCOVERY es 0. IP de LAN, nunca localhost.
  #define API_BASE_URL   "http://192.168.1.50:8000"
  // 1 = buscar el servidor por mDNS al arrancar; 0 = usar solo la dirección
  // manual (redes escolares que bloquean multicast).
  #define MDNS_DISCOVERY 1
  #define READER_API_KEY "00000000000000000000000000000000"
  #define MODE_PASSWORD  "CHANGE-ME-MODE-PW"
  ```
  Spanish comments must explain three things:
  - the key is the station's identity, and it also wraps phone keys during pairing;
  - the mode password must not look like a command (`c`, `a …`, `e …`, `s …`);
  - the `s <url>` console command overrides `API_BASE_URL` at runtime (Task 4/8).
- [ ] **Step 7: Write `lib/StationCore/library.json`.** Copy `$SRC/lib/PresenceCore/library.json`, set `"name": "StationCore"` and write a Spanish description.
- [ ] **Step 8: Write the stub `src/main.cpp`** with `#include <Arduino.h>`, `#include "config.h"`, and empty `setup()` and `loop()`. Later tasks add `#include` lines here so the env compiles their headers.
- [ ] **Step 9: Write the test files.**
  - Port `test/test_station_config.cpp` from `$SRC/test/test_station_config.cpp`. Change its include to `"config/esp32cam.h"` (already the same) and apply the rename table.
  - The pattern tests in that file need `FeedbackPatterns`, which Task 3 ports. Until then, comment out the three `RUN_TEST` lines and its `#include "FeedbackPatterns.h"`, but keep the `static_assert`s. Task 3 re-enables them.
  - Add `static void station_config_compiles() { TEST_ASSERT_EQUAL(13, PIN_RC522_SS); }` and register it.
  - Write `test/test_main.cpp` with Unity `setUp`, `tearDown` and `main`, calling `runStationConfigTests()`.
- [ ] **Step 10: Run the native suite.** Run `cd $FW && pio test -e native`. Expected: `1 Tests 0 Failures`.
- [ ] **Step 11: Compile the stub.** Run `cd $FW && pio run -e esp32cam`. Expected: `SUCCESS`.
- [ ] **Step 12: Run the secrets guard.**
  - Run `cd $FW && cp include/secrets.h.example include/secrets.h && git check-ignore include/secrets.h && rm include/secrets.h`. Expected: it prints `include/secrets.h`.
  - Run `grep -rIl -F -f <(grep -hoE '^#define [A-Z_]+ +"[^"]{6,}"' $SRC/include/secrets.h $SRC/include/secrets.camera.h $SRC/include/secrets.cam_reader.h 2>/dev/null | grep -oE '"[^"]+"' | grep -v -e YOUR_ -e CHANGE-ME -e 00000000 -e 192.168.1.50 | tr -d '"') $FW`. Expected: no output. This proves no real value leaked; never print the values themselves.
- [ ] **Step 13: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "feat(firmware): scaffold esp32cam + native envs"
  ```

---

### Task 2: Protocol core: HCE, request signing, payloads, response parsing, multipart

**Model:** opus

**Files:**
- Create (port and rename) in `$FW/lib/StationCore/src/`:
  - `CoreTypes.h`
  - `HceProtocol.h`, `HceProtocol.cpp`
  - `RequestSigner.h`, `RequestSigner.cpp`
  - `PayloadBuilder.h`, `PayloadBuilder.cpp`
  - `ResponseParser.h`, `ResponseParser.cpp`
  - `CapturePayload.h`, `CapturePayload.cpp`
- Test (port): `$FW/test/test_hce_protocol.cpp`, `test_request_signer.cpp`, `test_payloads.cpp`, `test_responses.cpp`, `test_capture_payload.cpp`
- Modify: `$FW/test/test_main.cpp`

**Interfaces:**
- Produces:
  - all `$SRC` signatures, unchanged, under `namespace EcoStation`: `Hce::*`, `Signer::*`, `buildTapPayload`, `buildPairPayload`, `buildAssociatePayload`, `parseTapResponse`, `parsePairResponse`, `CapturePayload::*`, and the `HceProof`/`TapResult`/`PairResult`/`FeedbackKind` types;
  - new: `long parseCaptureId(const std::string& body);` in `ResponseParser.h`. It returns the positive `capture_id` from a capture response, or `-1` when the field is absent, non-positive or the body is not JSON. It replaces `station.cpp`'s Arduino-`String` `extractLongField`.

- [ ] **Step 1: Copy the files.** Copy the five source pairs and the five test files from `$SRC/lib/PresenceCore/src` and `$SRC/test`. Apply the rename table. In `CoreTypes.h`, delete `bearerAuthorizationValue` and its comment block.
- [ ] **Step 2: Check that the wire constants survived.**
  ```bash
  cd $FW && grep -c '"pulse-hce-key-wrap/v1\\n"' lib/StationCore/src/HceProtocol.cpp   # expect 1
  grep -c '"Pulse-HMAC "' lib/StationCore/src/RequestSigner.cpp                      # expect 1
  grep -c 'PLS-K3Y7V3CT0R5Z' test/test_hce_protocol.cpp                             # expect >=1
  diff <(grep -oE '0x[0-9A-Fa-f]{2}' $SRC/lib/PresenceCore/src/HceProtocol.h) <(grep -oE '0x[0-9A-Fa-f]{2}' lib/StationCore/src/HceProtocol.h)  # expect no output
  ```
- [ ] **Step 3: Write the failing test for `parseCaptureId`.** Add it to `test/test_responses.cpp` and register it in `runResponseTests()`:
  ```cpp
  void test_capture_id_is_parsed(void) {
      TEST_ASSERT_EQUAL(42, EcoStation::parseCaptureId("{\"status\":\"ok\",\"capture_id\":42,\"next_step\":\"present_card\"}"));
      TEST_ASSERT_EQUAL(7, EcoStation::parseCaptureId("{ \"capture_id\" : 7 }"));
      TEST_ASSERT_EQUAL(-1, EcoStation::parseCaptureId("{\"status\":\"ok\"}"));
      TEST_ASSERT_EQUAL(-1, EcoStation::parseCaptureId("{\"capture_id\":0}"));
      TEST_ASSERT_EQUAL(-1, EcoStation::parseCaptureId("<html>500</html>"));
      TEST_ASSERT_EQUAL(-1, EcoStation::parseCaptureId(""));
  }
  ```
- [ ] **Step 4: Wire the tests into the runner.** Add `runHceProtocolTests`, `runRequestSignerTests`, `runPayloadTests`, `runResponseTests` and `runCapturePayloadTests` to `test_main.cpp`. Run `pio test -e native`. Expected: a compile failure, because `parseCaptureId` is not declared.
- [ ] **Step 5: Implement `parseCaptureId`** in `ResponseParser.cpp` with ArduinoJson (`JsonDocument`, `deserializeJson`, `doc["capture_id"] | -1`, then map `<= 0` to `-1`).
- [ ] **Step 6: Run the suite.** Run `pio test -e native`. Expected: all tests pass with 0 failures. Every HCE, signer and multipart golden vector passes unchanged.
- [ ] **Step 7: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "feat(firmware): port HCE, Pulse-HMAC signing, payloads and parsers"
  ```

---

### Task 3: Operator core: modes, console, debounce, LED patterns, capture trigger (Spanish)

**Model:** sonnet

**Files:**
- Create (port) in `$FW/lib/StationCore/src/`:
  - `Mode.h`, `Modes.h`, `Modes.cpp`
  - `ModeConsole.h`, `ModeConsole.cpp`
  - `CardDebouncer.h`
  - `FeedbackPatterns.h`, `FeedbackPatterns.cpp`
  - `CaptureTrigger.h`, `CaptureTrigger.cpp`
- Test (port): `$FW/test/test_modes.cpp`, `test_console.cpp`, `test_debounce.cpp`, `test_capture_trigger.cpp`
- Modify: `$FW/test/test_station_config.cpp` (re-enable the pattern tests), `$FW/test/test_main.cpp`

**Interfaces:**
- Consumes: `CoreTypes.h` and `PayloadBuilder.h` (Task 2).
- Produces:
  - the `$SRC` signatures, unchanged (`OperationMode`, `PairingMode`, `LineBuffer`, `ModeConsole`, `CardDebouncer`, `modeLedPattern`, `eventLedPattern`, `PatternPlayer`, `TerminalCaptureTrigger`, `ButtonCaptureTrigger`, `CaptureCommand`);
  - `OperationMode::label()` returns `"OPERACION"` and `PairingMode::label()` returns `"EMPAREJAR"`;
  - `ModeConsole::matches` is reader-only, so delete it (the station never calls it).

- [ ] **Step 1: Copy the files and apply the rename table.**
- [ ] **Step 2: Update `test_modes.cpp` to the Spanish strings (it fails first).**
  - Rename `test_mode_labels_are_bilingual` to `test_mode_labels_are_spanish`. It asserts `strcmp(op.label(), "OPERACION") == 0` and `strcmp(pair.label(), "EMPAREJAR") == 0`, and `strstr(label, "/") == nullptr` for both.
  - In `test_pairing_hint_teaches_arm_first_flow`, assert `strstr(hint, "arma primero una sesión")` and `strstr(hint, "45 s")`.
  - Delete the English asserts and any `matches()` test in `test_console.cpp`.
- [ ] **Step 3: Run the suite and confirm the failure.** Run `pio test -e native`. Expected: FAIL on the label and hint asserts.
- [ ] **Step 4: Set the Spanish labels and hints in `Modes.cpp`/`Modes.h`.**
  - `OperationMode::hint()`: `"acerca una tarjeta o teléfono EMPAREJADO y deja la botella"`.
  - `PairingMode::hint()`: `"arma primero una sesión en la web (admin, ventana de 45 s),\n     luego acerca una tarjeta NUEVA o el teléfono con «Vincular este teléfono» abierto"`.
  - Delete `ModeConsole::matches` from the `.h` and `.cpp`.
- [ ] **Step 5: Re-enable the pattern tests.** In `test_station_config.cpp`, restore `#include "FeedbackPatterns.h"` and the three `RUN_TEST`s. Add the four runners to `test_main.cpp`.
- [ ] **Step 6: Run the suite.** Run `pio test -e native`. Expected: all tests pass with 0 failures.
- [ ] **Step 7: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "feat(firmware): port mode machine, console, trigger (Spanish labels)"
  ```

---

### Task 4: Server address: `_ecostation._tcp` discovery policy and manual fallback

**Model:** sonnet

**Files:**
- Create: `$FW/lib/StationCore/src/EcoStationEndpoint.h` (port of `PulseEndpoint.h`, renamed, plus the new policy)
- Modify: `$FW/lib/StationCore/src/CaptureTrigger.h/.cpp` (add the `s` command)
- Test: `$FW/test/test_endpoint.cpp` (port of `test_pulse_endpoint.cpp`), plus new cases in `$FW/test/test_capture_trigger.cpp`
- Modify: `$FW/test/test_main.cpp`

**Interfaces:**
- Produces (in `EcoStationEndpoint.h`, `namespace EcoStation`, all `inline`):
  - the ported `EcoStationCandidate`, `EcoStationEndpoint` (`host`, `port`, `valid`, `baseUrl()`, `urlFor()`, `static fromBaseUrl()`), `ecoStationProtocolCompatible()` and `pickEcoStationEndpoint()`. The behaviour is unchanged.
  - `bool validManualServerUrl(const std::string& url, std::string& normalizedOut);`
    - It returns true only when all of these hold:
      - `url.size() <= ECOSTATION_SERVER_URL_MAX`;
      - the url does not start with `https://`;
      - `fromBaseUrl(url).valid`;
      - the host is not `localhost`, does not start with `127.`, and is not `0.0.0.0`.
    - On success it sets `normalizedOut = endpoint.baseUrl()`. A bare `192.168.1.50:8000` is accepted (fromBaseUrl tolerates a missing scheme) and becomes `http://192.168.1.50:8000`.
    - Add `#define ECOSTATION_SERVER_URL_MAX 64` to this header behind `#ifndef`, so the native tests do not need `config.h`.
  - `enum class ServerSource { Console, Discovered, Secrets };`
  - `inline bool shouldDiscover(bool mdnsEnabled, const std::string& consoleUrl)`. It returns `mdnsEnabled && consoleUrl.empty()`: an address the operator typed pins the server, and mDNS never overrides it.
  - `inline ServerSource chooseServer(const std::string& consoleUrl, const EcoStationEndpoint& discovered, const std::string& secretsUrl, std::string& baseUrlOut);` The priority is: a non-empty console URL first, then a valid discovered endpoint, then `secretsUrl`.
- Produces (in `CaptureTrigger`):
  - the new `CaptureCommand::ServerUrl` kind;
  - `feedLine("s")` gives `{ServerUrl, ""}` (show the current address);
  - `"s -"` gives `{ServerUrl, "-"}` (clear the console URL);
  - `"s <token>"` gives `{ServerUrl, token}`, trimmed like `a <uid>`;
  - `"s a b"` (two tokens) gives `None`.

- [ ] **Step 1: Port the endpoint test.** Port `test_pulse_endpoint.cpp` to `test_endpoint.cpp` with the rename table. In `fallback_base_url_parses`, change the literal `"http://pulse-backend"` to `"http://ecostation-server"`. Then add:
  ```cpp
  static void mdns_identity_is_ecostation() {
      TEST_ASSERT_EQUAL_STRING("ecostation", ECOSTATION_MDNS_SERVICE_NAME);
      TEST_ASSERT_EQUAL_STRING("_ecostation._tcp.local", ECOSTATION_MDNS_LABEL);
  }
  static void manual_url_is_normalized() {
      std::string out;
      TEST_ASSERT_TRUE(validManualServerUrl("192.168.1.50:8000", out));
      TEST_ASSERT_EQUAL_STRING("http://192.168.1.50:8000", out.c_str());
      TEST_ASSERT_TRUE(validManualServerUrl("http://10.0.0.5", out));
      TEST_ASSERT_EQUAL_STRING("http://10.0.0.5:80", out.c_str());
  }
  static void manual_url_rejects_unusable_addresses() {
      std::string out = "untouched";
      const char* bad[] = {"http://localhost:8000", "127.0.0.1:8000", "http://0.0.0.0:8000",
                           "https://10.0.0.5", "http://10.0.0.5:99999", "http://:8000", "", "basura::",
                           "http://10.0.0.5:8000/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"};
      for (const char* b : bad) {
          TEST_ASSERT_FALSE_MESSAGE(validManualServerUrl(b, out), b);
      }
      TEST_ASSERT_EQUAL_STRING("untouched", out.c_str());
  }
  static void console_url_pins_the_server() {
      EcoStationEndpoint disc; disc.host = "10.0.0.9"; disc.port = 8000; disc.valid = true;
      std::string url;
      TEST_ASSERT_FALSE(shouldDiscover(true, "http://10.0.0.5:8000"));
      TEST_ASSERT_TRUE(shouldDiscover(true, ""));
      TEST_ASSERT_FALSE(shouldDiscover(false, ""));
      TEST_ASSERT_TRUE(chooseServer("http://10.0.0.5:8000", disc, "http://1.1.1.1:8000", url) == ServerSource::Console);
      TEST_ASSERT_EQUAL_STRING("http://10.0.0.5:8000", url.c_str());
      TEST_ASSERT_TRUE(chooseServer("", disc, "http://1.1.1.1:8000", url) == ServerSource::Discovered);
      TEST_ASSERT_EQUAL_STRING("http://10.0.0.9:8000", url.c_str());
      TEST_ASSERT_TRUE(chooseServer("", EcoStationEndpoint(), "http://1.1.1.1:8000", url) == ServerSource::Secrets);
      TEST_ASSERT_EQUAL_STRING("http://1.1.1.1:8000", url.c_str());
  }
  ```
- [ ] **Step 2: Add the trigger tests** to `test_capture_trigger.cpp`:
  ```cpp
  static void server_line_parses() {
      TerminalCaptureTrigger t([]() { return 0UL; });
      CaptureCommand c = t.feedLine("s 192.168.1.50:8000");
      TEST_ASSERT_EQUAL(CaptureCommand::ServerUrl, c.kind);
      TEST_ASSERT_EQUAL_STRING("192.168.1.50:8000", c.arg.c_str());
      TEST_ASSERT_EQUAL_STRING("", t.feedLine("s").arg.c_str());
      TEST_ASSERT_EQUAL(CaptureCommand::ServerUrl, t.feedLine("s").kind);
      TEST_ASSERT_EQUAL_STRING("-", t.feedLine("s -").arg.c_str());
      TEST_ASSERT_EQUAL(CaptureCommand::None, t.feedLine("s a b").kind);
  }
  ```
- [ ] **Step 3: Run the suite and confirm the failure.** Register `runEndpointTests` and the new `RUN_TEST`s, then run `pio test -e native`. Expected: a compile failure (the new symbols are missing).
- [ ] **Step 4: Implement the endpoint header and the `s` command.** Create `EcoStationEndpoint.h`: the renamed port plus the four new inline functions. Add the `s` branch to `TerminalCaptureTrigger::handleLine`, after the `e` branch, mirroring the `a <uid>` trimming. Check whether the line is exactly `"s"` or `"S"` before checking the `"s "` prefix.
- [ ] **Step 5: Run the suite.** Run `pio test -e native`. Expected: all tests pass with 0 failures.
- [ ] **Step 6: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "feat(firmware): _ecostation._tcp discovery + manual server address"
  ```

---

### Task 5: `StationFlow`, the pure card-first/bottle-first transaction state machine

**Model:** opus

**Files:**
- Create: `$FW/lib/StationCore/src/StationFlow.h`, `$FW/lib/StationCore/src/StationFlow.cpp`
- Test: `$FW/test/test_station_flow.cpp`
- Modify: `$FW/test/test_main.cpp`

**Interfaces:**
- Consumes: `ModeKind`, `TapResult` and `TapOutcome` from `CoreTypes.h` (Task 2).
- Produces (`namespace EcoStation`):
  ```cpp
  enum class TapRoute { Pair, Associate, Tap };
  enum class CaptureRoute { Classify, Capture };
  enum class ArmResult { NotArmed, Armed, AlreadyArmed, Replaced };
  struct Expired { long captureId = -1; long eventId = -1; };   // ids just dropped, for logging
  struct FlowTimings { uint32_t pendingCaptureTimeoutMs; uint32_t armedEventTimeoutMs; uint32_t autoCaptureDelayMs; };

  class StationFlow {
  public:
      explicit StationFlow(const FlowTimings& t);
      Expired expire(uint32_t now);                         // wrap-safe (now - startedAt) >= timeout
      TapRoute routeTap(ModeKind mode) const;              // Pairing→Pair; pending capture→Associate; else Tap
      ArmResult onTapResult(const TapResult& r, uint32_t now); // arms only on Success && awaitingClassification && eventId > 0
      bool armFromConsole(const std::string& digits, uint32_t now); // 'e <id>': rejects size()>9 or value<=0
      bool autoCaptureDue(uint32_t now);                    // true exactly once per arm, at elapsed >= delay
      CaptureRoute routeCapture() const;                    // armed → Classify, else Capture
      void onClassifyResult(bool transportOk, int status);  // clears the arm ONLY on transportOk && 200
      long onCaptureResult(bool transportOk, int status, long captureId, uint32_t now); // on 200 && id>0 stores pending; returns the replaced pending id or -1
      bool onAssociateResult(bool transportOk, int status, const std::string& body); // true when pending was cleared
      void reset();                                         // mode switch: drop pending + arm
      long armedEventId() const;
      long pendingCaptureId() const;
  };
  ```
  `onAssociateResult` clears the pending capture on a 200. On a 404 it clears only when `body` contains `no_pending_capture` or `already_associated`. Any other 404 (an unknown card) and any transport failure keep it. These rules are moved unchanged from `$SRC/src/camera/station.cpp` (`doAssociate`, `expireStaleTransactions`, `handleCardTap`, `doCaptureAndUpload`, and the `ArmEvent` case).

- [ ] **Step 1: Write the failing tests** in `test_station_flow.cpp`. Use the constants `kT = {90000, 90000, 5000}` and the helper `TapResult ok(int id)`, which returns `Success`, `awaitingClassification = true` and `eventId = id`.
  ```cpp
  static void card_first_auto_captures_once_after_5s() {
      StationFlow f(kT);
      TEST_ASSERT_TRUE(f.onTapResult(ok(11), 1000) == ArmResult::Armed);
      TEST_ASSERT_FALSE(f.autoCaptureDue(5999));
      TEST_ASSERT_TRUE(f.autoCaptureDue(6000));
      TEST_ASSERT_FALSE(f.autoCaptureDue(6001));
      TEST_ASSERT_TRUE(f.routeCapture() == CaptureRoute::Classify);
      f.onClassifyResult(true, 200);
      TEST_ASSERT_EQUAL(-1, f.armedEventId());
      TEST_ASSERT_TRUE(f.routeCapture() == CaptureRoute::Capture);
  }
  static void failed_classify_keeps_arm_without_refiring_auto_capture() {
      StationFlow f(kT);
      f.onTapResult(ok(11), 0);
      TEST_ASSERT_TRUE(f.autoCaptureDue(5000));
      f.onClassifyResult(false, -1);
      TEST_ASSERT_EQUAL(11, f.armedEventId());
      TEST_ASSERT_FALSE(f.autoCaptureDue(9000));
      f.onClassifyResult(true, 503);
      TEST_ASSERT_EQUAL(11, f.armedEventId());
      f.onClassifyResult(true, 200);
      TEST_ASSERT_EQUAL(-1, f.armedEventId());
  }
  static void retap_of_armed_event_keeps_the_arm() {
      StationFlow f(kT);
      f.onTapResult(ok(11), 0);
      TEST_ASSERT_TRUE(f.autoCaptureDue(5000));
      TEST_ASSERT_TRUE(f.onTapResult(ok(11), 7000) == ArmResult::AlreadyArmed);
      TEST_ASSERT_FALSE(f.autoCaptureDue(7500));
      TEST_ASSERT_TRUE(f.onTapResult(ok(12), 8000) == ArmResult::Replaced);
      TEST_ASSERT_TRUE(f.autoCaptureDue(13000));
  }
  static void bottle_first_then_tap_associates() {
      StationFlow f(kT);
      TEST_ASSERT_TRUE(f.routeCapture() == CaptureRoute::Capture);
      TEST_ASSERT_EQUAL(-1, f.onCaptureResult(true, 200, 77, 0));
      TEST_ASSERT_TRUE(f.routeTap(ModeKind::Operation) == TapRoute::Associate);
      TEST_ASSERT_TRUE(f.onAssociateResult(true, 200, "{}"));
      TEST_ASSERT_TRUE(f.routeTap(ModeKind::Operation) == TapRoute::Tap);
  }
  static void stale_pending_capture_does_not_steal_the_tap() {
      StationFlow f(kT);
      f.onCaptureResult(true, 200, 77, 0);
      Expired e = f.expire(90000);
      TEST_ASSERT_EQUAL(77, e.captureId);
      TEST_ASSERT_TRUE(f.routeTap(ModeKind::Operation) == TapRoute::Tap);
  }
  static void associate_404_keeps_or_clears_by_reason() {
      StationFlow f(kT);
      f.onCaptureResult(true, 200, 77, 0);
      TEST_ASSERT_FALSE(f.onAssociateResult(true, 404, "{\"message\":\"Tarjeta no reconocida\"}"));
      TEST_ASSERT_FALSE(f.onAssociateResult(false, -1, ""));
      TEST_ASSERT_EQUAL(77, f.pendingCaptureId());
      TEST_ASSERT_TRUE(f.onAssociateResult(true, 404, "{\"status\":\"error\",\"reason\":\"no_pending_capture\"}"));
      TEST_ASSERT_EQUAL(-1, f.pendingCaptureId());
  }
  static void failed_capture_upload_keeps_previous_pending() {
      StationFlow f(kT);
      f.onCaptureResult(true, 200, 77, 0);
      TEST_ASSERT_EQUAL(-1, f.onCaptureResult(false, -1, -1, 100));
      TEST_ASSERT_EQUAL(77, f.pendingCaptureId());
      TEST_ASSERT_EQUAL(77, f.onCaptureResult(true, 200, 78, 200));
      TEST_ASSERT_EQUAL(78, f.pendingCaptureId());
  }
  static void pairing_mode_never_associates() {
      StationFlow f(kT);
      f.onCaptureResult(true, 200, 77, 0);
      TEST_ASSERT_TRUE(f.routeTap(ModeKind::Pairing) == TapRoute::Pair);
  }
  static void reset_clears_everything() {
      StationFlow f(kT);
      f.onCaptureResult(true, 200, 77, 0);
      f.armFromConsole("5", 0);
      f.reset();
      TEST_ASSERT_EQUAL(-1, f.pendingCaptureId());
      TEST_ASSERT_EQUAL(-1, f.armedEventId());
  }
  static void console_arm_rejects_bad_ids() {
      StationFlow f(kT);
      TEST_ASSERT_FALSE(f.armFromConsole("0", 0));
      TEST_ASSERT_FALSE(f.armFromConsole("1234567890", 0));
      TEST_ASSERT_TRUE(f.armFromConsole("42", 0));
      TEST_ASSERT_EQUAL(42, f.armedEventId());
  }
  static void non_awaiting_or_failed_taps_never_arm() {
      StationFlow f(kT);
      TapResult r = ok(11); r.awaitingClassification = false;
      TEST_ASSERT_TRUE(f.onTapResult(r, 0) == ArmResult::NotArmed);
      TapResult bad; bad.outcome = TapOutcome::CardNotRecognized;
      TEST_ASSERT_TRUE(f.onTapResult(bad, 0) == ArmResult::NotArmed);
  }
  static void timers_survive_millis_wrap() {
      StationFlow f(kT);
      const uint32_t t0 = 0xFFFFF000u;           // 4096 ms before the wrap
      f.onTapResult(ok(11), t0);
      TEST_ASSERT_FALSE(f.autoCaptureDue(t0 + 4999));
      TEST_ASSERT_TRUE(f.autoCaptureDue(t0 + 5000));  // wraps past 0
      TEST_ASSERT_EQUAL(-1, f.expire(t0 + 89999).eventId);
      TEST_ASSERT_EQUAL(11, f.expire(t0 + 90000).eventId);
  }
  void runStationFlowTests() { /* RUN_TEST each of the above */ }
  ```
- [ ] **Step 2: Run the suite and confirm the failure.** Register `runStationFlowTests` in `test_main.cpp` and run `pio test -e native`. Expected: a compile failure (`StationFlow.h` is missing).
- [ ] **Step 3: Implement `StationFlow`.** State: `long pending_ = -1`, `pendingAt_`, `long armed_ = -1`, `armedAt_`, `bool autoDone_`. All elapsed checks use `(uint32_t)(now - start) >= window`. Use no Arduino headers.
- [ ] **Step 4: Run the suite.** Run `pio test -e native`. Expected: all tests pass with 0 failures.
- [ ] **Step 5: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "feat(firmware): host-tested station transaction state machine"
  ```

---

### Task 6: Device transport libs: HTTP signing client, Wi-Fi, mDNS discovery, station LED

**Model:** sonnet

**Files:**
- Create (port and rename, Spanish strings):
  - `$FW/lib/ApiClient/src/ApiClient.h`, `$FW/lib/ApiClient/src/EspApiClient.h`
  - `$FW/lib/WifiService/src/WifiService.h`
  - `$FW/lib/Discovery/src/EcoStationDiscovery.h`
  - `$FW/lib/Feedback/src/FeedbackController.h`, `$FW/lib/Feedback/src/StationLed.h`
- Modify: `$FW/src/main.cpp` (stub: include each new header)

**Interfaces:**
- Consumes: `RequestSigner.h`, `CoreTypes.h` and `FeedbackPatterns.h` (Tasks 2–3), and `EcoStationEndpoint.h` (Task 4).
- Produces (the `$SRC` signatures, unchanged):
  - `HttpResponse{status, body, transportOk}`;
  - `EspApiClient(baseUrl, secret, timeoutMs)` with `setBaseUrl`, `baseUrl()`, `post(path, json)`, `post(path, body, contentType)` and `postMultipart(path, body, contentType, signingBody)`;
  - `WifiService(ssid, pw, connectMs, reconnectMs)` with `begin`, `isConnected`, `tick` and `ip()`. `ip()` returns `"(sin conexión)"` when offline.
  - `EcoStationDiscovery` with `begin(hostname)`, `discover() -> EcoStationEndpoint`, `refreshIfDue(now, cooldown, out)` and `lastSeen()`. It queries `ECOSTATION_MDNS_SERVICE_NAME`/`ECOSTATION_MDNS_SERVICE_PROTO`.
  - `StationLed(pin, activeLow)`, which implements `FeedbackController`.

- [ ] **Step 1: Copy and rename the files.**
  - Copy the six headers and apply the rename table.
  - In `EspApiClient.h`, delete the TASK-007 comment paragraphs that mention `bearerAuthorizationValue`. Keep the explicit `addHeader("Authorization", …)` and the "never call `setAuthorization`" rule as a one-line comment.
  - Drop the `LedFeedbackController` reference in `FeedbackController.h`'s comment.
- [ ] **Step 2: Include the headers in the stub.** Add `#include "EspApiClient.h"`, `"WifiService.h"`, `"EcoStationDiscovery.h"` and `"StationLed.h"` to the stub `src/main.cpp`.
- [ ] **Step 3: Compile.** Run `cd $FW && pio run -e esp32cam`. Expected: `SUCCESS`.
- [ ] **Step 4: Check the renames and the language.**
  - Run `grep -rn "Pulse\|pulse\|PULSE" $FW/lib/ApiClient $FW/lib/WifiService $FW/lib/Discovery $FW/lib/Feedback`. Expected: only the comment lines naming the `Pulse-HMAC` wire scheme.
  - Run `grep -rnE '"[^"]*(not connected|failed|retry)[^"]*"' $FW/lib`. Expected: no output.
- [ ] **Step 5: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "feat(firmware): port signed HTTP client, Wi-Fi, mDNS discovery, LED"
  ```

---

### Task 7: RC522 reader with HCE relay and pairing ENROLL (Spanish diagnostics)

**Model:** opus

**Files:**
- Create (port): `$FW/lib/NfcReader/src/NfcReader.h`, `$FW/lib/NfcReader/src/Rc522NfcReader.h`, `$FW/lib/NfcReader/src/TsLog.h`
- Modify: `$FW/src/main.cpp` (stub: include `Rc522NfcReader.h`)

**Interfaces:**
- Consumes: `HceProtocol.h`, `RequestSigner.h` and `CoreTypes.h` (Task 2).
- Produces (the `$SRC` signatures, unchanged):
  - `Rc522NfcReader(ss, rst, sck, miso, mosi, Print* log)`;
  - `begin()`, `poll(uid&)`, `lastKind()` (`"physical"`/`"hce"`), `lastProof() -> const HceProof&`, `setEnrollment(bool on, const std::string& wrapSecret)`, `healthy()` and `label()` (`"RC522 (SPI)"`).

- [ ] **Step 1: Copy and rename the files.** Copy the three headers from `$SRC/lib/NfcReader/src/` and apply the rename table. Do not port `MockSerialNfcReader.h`. Do not alter any APDU byte, timing constant, retry or salvage path, the ENROLL frame redaction, the raw-key wipe after `wrapKeyHex`, or the `esp_random` nonce generation.
- [ ] **Step 2: Translate every `log_->print*`/`Serial.print*` string to Spanish only.**
  - Keep the tag prefixes (`[NFC]`, `[HCE]`).
  - Keep the hex dumps exactly as they are.
  - Rename "Pulse phone" to "teléfono EcoStation".
  - The 6A88 line must tell the operator: `"el teléfono no tiene llave: en la app toca «Vincular este teléfono» y empareja en modo EMPAREJAR"`.
- [ ] **Step 3: Check that the protocol logic is untouched.** Diff only the non-string code:
  ```bash
  diff <(sed -E 's/"([^"\\]|\\.)*"/""/g; s/Presence/EcoStation/g' $SRC/lib/NfcReader/src/Rc522NfcReader.h | grep -v '^\s*\(//\|\*\|/\*\)') \
       <(sed -E 's/"([^"\\]|\\.)*"/""/g' $FW/lib/NfcReader/src/Rc522NfcReader.h | grep -v '^\s*\(//\|\*\|/\*\)')
  ```
  Expected: no differences, or only differences caused by line-wrapping of translated strings, each reviewed by eye.
- [ ] **Step 4: Check the language.** Run `grep -nE '(print|println|printf)\(.*"[^"]*\b(the|card|phone|failed|retry|not|wiring)\b' $FW/lib/NfcReader/src/*.h`. Expected: no output.
- [ ] **Step 5: Compile.** Add `#include "Rc522NfcReader.h"` to the stub `src/main.cpp`, then run `pio run -e esp32cam && pio test -e native`. Expected: both report SUCCESS, and the native suite has 0 failures.
- [ ] **Step 6: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "feat(firmware): port RC522 reader with HCE relay + ENROLL"
  ```

---

### Task 8: Station composition root: both flows, pairing, manual server, visualizer

**Model:** opus

**Files:**
- Create: `$FW/src/station.h`, `$FW/src/station.cpp` (port of `$SRC/src/camera/station.{h,cpp}`)
- Replace: `$FW/src/main.cpp` (port of `$SRC/src/camera/main.cpp`)

**Interfaces:**
- Consumes: every interface from Tasks 2–7, plus Arduino `Preferences` (part of the ESP32 core, so no new dependency).
- Produces: `EcoStation::Station` with `begin()` and `update()`. There is no other public API.

- [ ] **Step 1: Port the files.**
  - Port `main.cpp`, `station.h` and `station.cpp`, and apply the rename table.
  - `station.h` includes `"secrets.h"`, guarded by `__has_include`. The fallback is `"secrets.h.example"` with a Spanish `#warning`.
  - Add `#ifndef MDNS_DISCOVERY` / `#define MDNS_DISCOVERY 1`.
  - Keep the `MODE_PASSWORD` default guard.
- [ ] **Step 2: Replace the inline transaction state with `StationFlow`.**
  - Delete the members `backendCaptureId_`, `backendCaptureAtMs_`, `armedEventId_`, `armedAtMs_` and `autoCaptureDone_`.
  - Delete `extractLongField`.
  - Add `StationFlow flow_{{PENDING_CAPTURE_TIMEOUT_MS, ARMED_EVENT_TIMEOUT_MS, CARD_FIRST_AUTO_CAPTURE_DELAY_MS}};`.
  - Each original branch maps one-to-one:
    - `expireStaleTransactions(now)` calls `flow_.expire(now)` and logs each id that is not -1;
    - `update()`'s auto-capture becomes `if (flow_.autoCaptureDue(now)) handleCaptureCommand({CaptureCommand::Capture, ""});`;
    - `handleCardTap` switches on `flow_.routeTap(mode_->kind())`: `Associate` calls `doAssociate(uid, nfc_.lastProof())` and returns. `Tap` and `Pair` POST `mode_->onCardTap(...)` as before, and after a tap, `flow_.onTapResult(result, millis())` drives the four log lines;
    - `doCaptureAndUpload` switches on `flow_.routeCapture()`, then `onClassifyResult` or `onCaptureResult(…, parseCaptureId(body), now)`;
    - `doAssociate` refuses when `flow_.pendingCaptureId() <= 0`, otherwise POSTs and calls `onAssociateResult`;
    - the `ArmEvent` case calls `flow_.armFromConsole(arg, millis())`;
    - `switchMode()` calls `flow_.reset()`.
- [ ] **Step 3: Add server selection and the `s` command.**
  - New members: `Preferences prefs_;` and `std::string consoleUrl_;`.
  - Boot sequence:
    1. `prefs_.begin("ecostation", false)`.
    2. `consoleUrl_ = prefs_.getString("server", "")`. If `!validManualServerUrl(consoleUrl_, consoleUrl_)`, clear it.
    3. If `shouldDiscover(MDNS_DISCOVERY, consoleUrl_)`, run the boot discovery loop (`ECOSTATION_DISCOVERY_BOOT_ATTEMPTS`).
    4. Call `chooseServer(consoleUrl_, found, API_BASE_URL, url)`, then `api_.setBaseUrl(url)`.
    5. Log `[SERVIDOR] <url> (origen: consola|mDNS|secrets.h)`.
  - The rediscovery in `post()`/`postMultipart()` runs only when `shouldDiscover(...)` is true. Rename the method `discoverPulseServer` to `selectServer`.
  - `handleCaptureCommand` gets a `ServerUrl` case:
    - `""`: print the current URL and its source.
    - `"-"`: `prefs_.remove("server")`, clear `consoleUrl_` and re-run `selectServer()`.
    - Anything else: if `validManualServerUrl(arg, norm)`, then `prefs_.putString("server", norm)`, set `consoleUrl_ = norm`, call `api_.setBaseUrl(norm)` and print `"[SERVIDOR] dirección fijada: <norm> (se guarda tras reiniciar; 's -' la borra)"`. If not valid, print `"[SERVIDOR] dirección inválida — usa http://IP:puerto de la LAN (nunca localhost)"` and leave everything unchanged.
- [ ] **Step 4: Make every string Spanish only.**
  - The banner title is `"ECOSTATION — ESTACIÓN ESP32-CAM"`. The build line prints `ECOSTATION_FW_BUILD`.
  - The command list includes `s <url>`, `s` and `s -`.
  - The placeholder warning names `include/secrets.h`.
  - The `[EN]` lines are deleted and the `[ES]` lines are kept without the tag.
  - Remediation strings point to `docs/ESTACION.md`.
  - Visualizer HTML: `<html lang="es">`, `<title>EcoStation — Estación</title>`, and Spanish headings ("Vista en vivo", "Última captura") and status text. Keep the endpoints `/`, `/stream-frame`, `/capture.jpg` and `/capture-status` and their JSON keys unchanged.
- [ ] **Step 5: Compile and test.** Run `cd $FW && pio run -e esp32cam && pio test -e native`. Expected: both report SUCCESS, the native suite has 0 failures, and the build has no warnings from `src/` other than the missing-`secrets.h` `#warning`.
- [ ] **Step 6: Run the language and rename checks.**
  ```bash
  grep -nE '\[EN\]|\[ES\]| / [a-záéíóú]' $FW/src/*.cpp $FW/src/*.h          # expect no output
  grep -rn "Pulse\|pulse\|PULSE" $FW/src $FW/include                       # expect no output
  grep -rn "Presence" $FW/src $FW/lib $FW/test $FW/include                 # expect no output
  ```
- [ ] **Step 7: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "feat(firmware): EcoStation station — both flows, pairing, manual server"
  ```

---

### Task 9: Spanish docs: README, station guide, HCE protocol

**Model:** haiku

**Files:**
- Create: `$FW/README.md`
- Create: `$FW/docs/ESTACION.md`
- Create: `$FW/docs/HCE_PROTOCOL.md`

**Interfaces:** None (docs only).

- [ ] **Step 1: Write `README.md` in Spanish.**
  - What the station does (both flows, phone taps, pairing).
  - Requirements.
  - Quick start: `cp include/secrets.h.example include/secrets.h`, `pio run -e esp32cam -t upload`, `pio device monitor -e esp32cam`, `pio test -e native`.
  - The layout of `lib/`, `src/`, `test/`, `tools/` and `scripts/`.
  - A security note: `secrets.h` is gitignored, and devices sign with Pulse-HMAC, a wire-compatible scheme name kept on purpose.
- [ ] **Step 2: Write `docs/ESTACION.md`** from `$SRC/docs/CAMERA_STATION.es.md` and `$SRC/docs/PAIRING.es.md`, cut to the station only. Sections:
  - wiring (the pin table from `config/esp32cam.h`);
  - provisioning `secrets.h`;
  - **Dirección del servidor** (mDNS `_ecostation._tcp` first; `s <url>` pins and persists; `s -` clears; `MDNS_DISCOVERY 0`);
  - flow A, card first (tap, then the photo after 5 s, or BUTTON/ENTER right away);
  - flow B, bottle first (GPIO12/ENTER, then a tap within 90 s);
  - pairing (mode password, then arm in the web, then tap a card or phone);
  - the serial command table (`ENTER`, `a <uid>`, `e <id>`, `c`, `s …`, password);
  - the LED pattern table (from the `FeedbackPatterns.h` header comment);
  - the bench checklist (adapted from `$SRC/docs/MANUAL_VERIFICATION_CHECKLIST.es.md`, station items only).
- [ ] **Step 3: Write `docs/HCE_PROTOCOL.md`.** Copy `$SRC/docs/HCE_PROTOCOL.es.md`. Rename the product references (Pulse → EcoStation, B2B-Core → `web/`, B2B-App → `app/`). Add one sentence under the title: `"Los literales pulse-hce-key-wrap/v1, Pulse-HMAC y el vector de prueba conservan el nombre Pulse a propósito: son constantes del protocolo en el cable."` Leave every byte value and vector row unchanged.
- [ ] **Step 4: Check that the docs match the code.**
  ```bash
  grep -c "_ecostation._tcp" $FW/docs/ESTACION.md                                   # expect >=1
  diff <(grep -oE '[0-9a-f]{32,64}' $SRC/docs/HCE_PROTOCOL.es.md) <(grep -oE '[0-9a-f]{32,64}' $FW/docs/HCE_PROTOCOL.md)  # expect no output
  ```
- [ ] **Step 5: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "docs(firmware): Spanish README, station guide, HCE protocol"
  ```

---

### Task 10: End-to-end script against `../web` (signed, both flows, pairing)

**Model:** sonnet

Depends on the `web/` plan being implemented. Until it is, the script must exit 2 with `"web/ no encontrado"`, not fail confusingly.

**Files:**
- Create: `$FW/tools/e2e/device_request.cpp` (new)
- Create (port): `$FW/tools/e2e/build_payloads.cpp`, `$FW/tools/e2e/verify_responses.cpp`
- Create: `$FW/scripts/e2e_web.sh` (modeled on `$SRC/scripts/e2e_backend.sh`)

**Interfaces:**
- Consumes: `PayloadBuilder`, `ResponseParser` (including `parseCaptureId`), `Modes`, `FeedbackPatterns`, `RequestSigner`, `HceProtocol` and `CapturePayload` (Tasks 2–3), all compiled with `g++ -std=gnu++17` from `lib/StationCore/src` plus ArduinoJson from `.pio/libdeps/native/ArduinoJson/src`.
- Produces: the `device_request` CLI. It prints exactly two lines to stdout: line 1 is the Authorization value, line 2 is the Content-Type. The body bytes go to `<out_body>`. The nonce is 32 hex characters from `std::random_device`, or the optional last argument `--nonce <hex>` when given.
  ```
  device_request <secret> json <path> <json_body_file> <out_body>
  device_request <secret> capture <jpeg_file> <out_body>                # path /api/v1/recycling/capture, CapturePayload::imageOnly + captureSigningBody
  device_request <secret> classify <jpeg_file> <event_id> <out_body>    # path /api/v1/recycling/classify, classifyWithEvent + classifySigningBody
  ```
- `web/` contract this script depends on (web plan Tasks 1, 2, 4, 6, 7, 9 and 10; if it changes, adapt only the fixture block in Step 3):
  - `php artisan migrate --seed --force` runs `DemoSeeder` (Task 2). It creates **one** reader, `Estación Demo`, whose `readers.api_key` is `Str::random(32)` (so read it from the DB; the table has **no `type` column**). It also creates five students, each with an active physical card `cards.credential_uid = DEMO-<n>`, and the staff `admin@ecostation.test`, `docente@ecostation.test` and `estacion@ecostation.test` (password `password`). **Every demo student already has a card**, so the script creates its own card-less student.
  - Sanctum tokens are issued to the `student` role only (Task 12), so there is no admin token. The script arms a pairing session by calling `App\Services\PairingService::arm($student)` from a `php` bootstrap against the same throwaway SQLite file, which is what the web tests do. The admin route is `POST /admin/pairing/arm` (session and CSRF), and the script does not use it.
  - `config('ecostation.pairing_window_seconds')` reads `PAIRING_WINDOW_SECONDS` (default 45).
  - `RECYCLING_CLASSIFIER_DRIVER=stub` classifies offline.
  - The HMAC nonce store is the cache, so `CACHE_STORE=database` must be set (the replay case needs a persistent store; `array` would forget between requests).
  - `GET /up` is the health check.
  - Response contracts, as in Task 6, 7, 9 and 10 of the web plan: tap `200 {status:"ok", event_id, event_type:"RECYCLING_DEPOSIT", student_first_name, duplicate:false, next_step:"awaiting_classification"}`, `404` for an unknown card, `401` for a bad signature or a replayed nonce; capture `200 {status:"ok", capture_id, next_step:"present_card"}`; associate `200`, then `404` with `reason: "no_pending_capture"` on a repeat; pair `409` with no session, `200` on success, `422` for an already-paired card, `401` for a bad key.

- [ ] **Step 1: Port the two tools and extend `verify_responses`.**
  - Port `build_payloads.cpp` (namespace rename only).
  - Port `verify_responses.cpp` and extend its kinds:
    - `tap` gains the expected value `success_awaiting` (Success && `awaitingClassification` && `eventId > 0`);
    - a new kind `capture` passes when `parseCaptureId(body) > 0`;
    - a new kind `status` passes when the HTTP status equals the expected integer.
  - Output and exit code: `PASS/FAIL` per line, and exit 0 only when all pass.
- [ ] **Step 2: Write `device_request.cpp`.** Implement the CLI above. Use `Signer::authorizationValue(secret, "POST", path, nonce, signingBody)`, where `signingBody` is the raw JSON bytes for `json` and the CapturePayload canonical for `capture`/`classify`.
- [ ] **Step 3: Write `scripts/e2e_web.sh`** with `set -Eeuo pipefail` and Spanish messages.
  - `WEB="${WEB:-$FW_ROOT/../web}"`, `PHP_BIN="${ECO_PHP:-php}"`, `PORT="${E2E_PORT:-8177}"`.
  - If `$WEB/artisan` is missing, exit 2 with `web/ no encontrado`. If `$WEB/vendor/autoload.php` is missing, exit 2 with `web/ sin dependencias: ejecuta ./run setup en web/`. Require `g++` and `curl`, and the ArduinoJson dir (otherwise tell the user to run `pio test -e native`).
  - Build the three tools into `mktemp -d`.
  - Throwaway SQLite. Export `APP_KEY="${APP_KEY:-base64:$(openssl rand -base64 32)}"` (the script must not depend on `web/.env`), `DB_CONNECTION=sqlite`, `DB_DATABASE=$WORK/e2e.sqlite`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `RECYCLING_CLASSIFIER_DRIVER=stub` and `PAIRING_WINDOW_SECONDS=45`, `touch` the file, then run `php artisan migrate --seed --force` from `$WEB`.
  - Fixture block (one `php -r` bootstrap, as in `$SRC`, using `PDO` on the SQLite file):
    - `KEY` is `SELECT api_key FROM readers ORDER BY id LIMIT 1`;
    - `KNOWN_UID` is `SELECT credential_uid FROM cards ORDER BY id LIMIT 1` (`DEMO-1`);
    - `SID` is the id of a student created by the bootstrap with `App\Models\Student::create(['name' => 'E2E Estudiante'])` (a null `class_id` is allowed), which has no card;
    - there is no `PAT`.
  - Helper `arm_pairing`: a `php -r` bootstrap (`require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();`) that runs `app(App\Services\PairingService::class)->arm(App\Models\Student::findOrFail((int) $argv[1]));` from `$WEB` with the same exported env.
  - Generate the test JPEG: `"$PHP_BIN" -r 'imagejpeg(imagecreatetruecolor(64,64), $argv[1]);' "$WORK/bottle.jpg"`. If GD is missing, exit 1 with `"PHP sin GD: no se puede generar la imagen de prueba"`.
  - Start `artisan serve --host=127.0.0.1 --port=$PORT --no-reload` with the same env, and wait for `/up` (30 s).
  - Helper: `signed_post <path> <out_body> <header_file>` posts with `curl --data-binary @body -H "Authorization: $(sed -n 1p)" -H "Content-Type: $(sed -n 2p)" -H "Accept: application/json"`, and appends `name|kind|status|bodyfile|expected` to `cases.txt`.
- [ ] **Step 4: Drive these cases, in this order, all signed with `device_request`.**

  | # | Case | Request | Expected |
  |---|---|---|---|
  | 1 | `tap_known_card` | json `/api/v1/events/tap` with `build_payloads` KNOWN_UID | `tap` → `success_awaiting`; save `event_id` from the body with `php -r 'echo json_decode(file_get_contents($argv[1]))->event_id;'` |
  | 2 | `classify_card_first` | classify with that `event_id` | `status` → 200 |
  | 3 | `tap_unknown_card` | `{"credential_uid":"TOTALLYUNKNOWN"}` | `tap` → `not_recognized` |
  | 4 | `tap_bad_key` | signed with `wrong-secret` | `tap` → `auth` |
  | 5 | `tap_replayed_nonce` | case 1's exact header and body re-sent | `tap` → `auth` |
  | 6 | `capture_bottle_first` | capture | `capture` (save `capture_id`) |
  | 7 | `associate_known` | json `/api/v1/recycling/captures/$CID/associate`, `buildAssociatePayload(KNOWN_UID)` | `status` → 200 |
  | 8 | `associate_again` | the same, a fresh nonce | `status` → 404 |
  | 9–13 | pairing | `pair_no_session` (409), `pair_success` (after `arm_pairing "$SID"`), `pair_consumed` (409), `pair_already_paired` (`arm_pairing` again, then KNOWN_UID, 422), `pair_bad_key` (401) | as in `$SRC/scripts/e2e_backend.sh`, with `arm_pairing` in place of the `arm-pairing` HTTP call |

  Feed `cases.txt` to `verify_responses`. Print `"RESULTADO: E2E OK"` or `"RESULTADO: E2E FALLÓ"` and exit with its code. The `trap` cleanup kills the server and removes `$WORK`.
- [ ] **Step 5: Check the tools on the host without the web app.**
  - Run `cd $FW && pio test -e native && bash -n scripts/e2e_web.sh && shellcheck scripts/e2e_web.sh`. Expected: tests pass, the syntax check passes, and shellcheck prints no warnings (skip it if shellcheck is not installed).
  - Build `device_request` by hand and run it with the signer vector: `device_request test-secret-000000000000000001 json /api/v1/events/tap <(printf '{"credential_uid":"QHHIKKOSD6UU"}') /tmp/b --nonce 0123456789abcdef`. Expected line 1: `Pulse-HMAC b46b73a6f9444ea4:0123456789abcdef:4ec7367d8b340da73914b806992ac6544cc54c9887777cddc6f827b02566c4f1`.
- [ ] **Step 6: Run against the web app (once it exists).** Run `WEB=../web ./scripts/e2e_web.sh`. Expected: `13/13` verdicts correct and `RESULTADO: E2E OK`. Because the pair body for the fresh card uses the physical kind, `build_payloads` needs no HCE proof. If `web/` is not implemented yet, expect exit 2 with `web/ no encontrado`, and record that in the task report.
- [ ] **Step 7: Commit.**
  ```bash
  cd $ROOT && git add firmware && git commit -m "test(firmware): signed e2e against web/ (both flows + pairing)"
  ```

---

## Final verification (after Task 10)

- [ ] Run `cd $FW && pio test -e native && pio run -e esp32cam`. Expected: both report SUCCESS.
- [ ] Run `grep -rn "esp32dev\|esp32cam-reader\|MockSerial\|LedFeedbackController\|secrets.camera\|bearerAuthorizationValue" $FW`. Expected: no output.
- [ ] Run `cd $ROOT && git ls-files firmware | grep -E 'secrets\.h$'`. Expected: no output (only `secrets.h.example` is tracked).
- [ ] Repeat Task 1 Step 12's real-value grep over `$FW` and this plan file. Expected: no output.
