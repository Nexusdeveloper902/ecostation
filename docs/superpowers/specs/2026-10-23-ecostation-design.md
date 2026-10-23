# EcoStation: design

Date: 2026-10-23 · Status: draft, awaiting approval

## Intent

EcoStation is a standalone spin-off of the **recycling ("ecostation") slice
of Pulse**. A student taps a card or phone at a camera station, drops a
bottle and walks away. The bottle is classified and the points land on the
student's account in the background.

Everything else Pulse does is out of scope: attendance, school meals (PAE),
NL-query, parents and kitchen. The aim is a smaller product with its own
identity: a new name, a new logo and a "fresh & playful" recycling theme.

## Goals

- **Station (ESP32-CAM):** the same experience as Pulse's camera station,
  with both orders.
  - **Card-first:** tap, then the 5 s auto photo, then classify.
  - **Bottle-first:** button press or drop, then capture; the next tap links
    the capture to the student.
- **Phone as card:** over the **unchanged HCE protocol** (Pulse
  `HCE_PROTOCOL.md` v1.1: AID, CHALLENGE/ENROLL, key wrap, a per-phone
  Keystore key).
- **Web app**, with three roles:
  - **Student:** points balance, deposit history, and a leaderboard with top
    students plus class vs. class.
  - **Teacher:** class totals and trends by material, a per-student
    breakdown with each deposit's photo and classification, a live station
    feed, and PDF/CSV export.
  - **Admin:**
    - students and classes;
    - card/phone pairing (arm a student, then the next tap at any station
      links it);
    - stations (readers and their keys);
    - staff;
    - points per material, editable by the admin. Defaults: plastic 10,
      metal 15, glass 8, paper 5, other 0.
- **One Android app**, doing three things:
  1. phone-as-card (HCE);
  2. **speaker mode:** the Bluetooth speaker bridge from Pulse (accepted and
     rejected beeps on tap), **plus a new result cue** after classification
     (points awarded vs. not recyclable);
  3. student login to see their own points and history.
- **Classification** by **DeepSeek**, plus a **stub** driver for tests and
  offline demos.
- **Designs in Penpot** before implementation: logo and brand sheet, web
  screens, mobile screens.

## Non-goals

- Rewards catalog and redemption. Points are only a score.
- Multi-school. One deployment serves one school.
- The local-model classifier sidecar.
- A dedicated pairing reader (the esp32dev / esp32cam-reader builds).
  Pairing happens at the station.
- iOS.
- English. Everything is **Spanish only**: UI, docs, app and serial strings.
  This replaces Pulse's EN/ES parity rule.

## Anti-goals (failure even if it "works")

- Students waiting at the station for the classification. The tap must be
  fast, and the result arrives asynchronously.
- Dragging in Pulse's attendance/PAE baggage: dead enums, PAE columns, meal
  settings.
- Changing or weakening the HCE wire protocol, for example by reintroducing
  a shared secret or making key material a build input.
- Copying any real secret (`secrets*.h`, `.env`, API keys) from the Pulse
  repos.

## Constraints and decisions

| Area | Decision |
|---|---|
| Location | `~/Documents/EcoStation`, a **single monorepo** published as the public GitHub repo `NexusDeveloper902/ecostation` |
| Layout *(my call)* | `web/` (Laravel), `firmware/` (PlatformIO, esp32cam env + native tests), `app/` (Android/Kotlin), `design/` (Penpot exports, logo), `docs/` |
| Web | **Port the recycling slice from B2B-Core** (Laravel 13, Blade). Keep the API contract the firmware and app already speak: `/api/v1/events/tap`, `/recycling/classify`, `/recycling/capture`, `/recycling/captures/{id}/associate`, `/admin/cards/pair`, Pulse-HMAC device signing |
| DB | SQLite for dev, prod and tests |
| Hosting | A LAN server at the school. Stations and the app find it via mDNS; the service is renamed from `_pulse._tcp` to `_ecostation._tcp` *(my call)* |
| Points | Append-only `points_ledger`, balance = `SUM(delta)`. Per-material values live in a settings table that the admin can edit |
| Events | `events` stays the single source of truth. Every stat is derived from it |
| Realtime | A WebSocket feed (teacher live feed + speaker). Channels: tap, recycling, feedback |
| Speaker login | A dedicated **station/speaker account** replaces Pulse's kitchen account *(my call)* |
| Timezone | `America/Bogota` |
| Theme | A new design system, "fresh & playful": bright greens/teals, rounded shapes, student-friendly. It replaces Datum entirely. Fonts are self-hosted |
| Agents | The orchestrator delegates implementation to subagents and picks a model per task: Haiku for mechanical work, Sonnet for routine work, Opus for HCE, firmware and design |

## DeepSeek (checked against api-docs.deepseek.com on 2026-10-23)

The current driver is valid as it stands: `POST
https://api.deepseek.com/chat/completions`, model `deepseek-flash` (V4.1
Flash, the only vision-capable model), an `image_url` base64 data URL with
`detail`, `response_format: json_object`, `thinking: {type: disabled}`,
`temperature 0`, `max_tokens`. `deepseek-v4-pro` has **no** vision.

Changes, all my calls:
- Retry once on 429, 500 or 503.
- Handle 402 (insufficient balance) with a clear message.
- Keep `detail: high`.
- One open question to verify live: the docs don't say whether
  `json_object` and `thinking` combine with image input. A `llm-check`
  command makes one live call to settle it.

## What gets reused vs. cut

**Reuse** (ported, renamed from Pulse to EcoStation):

- **Backend:**
  - `ClassificationService`, `CaptureService`
  - `PointsService`, `LeaderboardService`
  - `MaterialClassifier` with the Stub and DeepSeek drivers
  - `HceCredentialAuth`, `PairingService`
  - device request signing, `TapFeedback`, `RecyclingReportPdf`
  - the recycling, HCE and pairing tests
- **Firmware:** `src/camera/*`, the PresenceCore pieces it uses, and the
  native tests for those pieces.
- **App:**
  - HCE: `ApduProtocol`, `CredentialKeyStore`, `EnrollmentWindow`,
    `PulseHostApduService`
  - feedback bridge: `FeedbackBridge`, `FeedbackSounds`, `WsClient`,
    `BackendDiscovery`

**Cut:**

- **Services and screens:** `MealServingService`, `AttendanceService`,
  NL-query, the teacher attendance dashboard, the parent views.
- **Data and settings:** meal settings, the `pae_*` columns,
  `school_id`/schools, the redemption tables.
- **Roles and enums:** the classroom and PAE reader/event types, the kitchen
  role.
- **Firmware:** the `esp32dev` reader env.

`~/Documents/ecopuntos` (an earlier Next.js recycling prototype) is **not**
used as a base.

## Settled after the builder check

**User's answers:**

- **Teacher scope.** Each teacher sees **only the classes assigned to them**.
  This applies to the dashboards, the live feed and exports. Admins see
  everything.
- **Photos.** Deposit photos are **deleted automatically after N days**. N is
  an admin setting, default 30. The classification, the event and the points
  are kept. The docs state that photos are sent to DeepSeek for
  classification.
- **Speaker.** It exists only to give **audible feedback during demos**, and
  it stays as it was in Pulse: one phone hears every tap. The only addition
  is the classification result cue. There is no per-station binding.
- **Order.** **Design comes first.** The user approves the logo and screens
  in Penpot before any web or app UI is built. The backend port, firmware
  port and HCE work can start in parallel, because they don't depend on the
  look.

**Already answered by Pulse:**

- Bottle-first is triggered by the station's **shutter button** (GPIO12) or
  ENTER on serial. There is no drop sensor.
- The ESP32-CAM station has an **RC522** and already relays HCE phone taps,
  so pairing and phone taps at the station are a port, not a new feature.

**My calls:**

- **Login.**
  - Web: email + password, with a forced password change on first login (as
    in Pulse).
  - App: the student signs in with the same credentials, and the app
    receives an API token.
  - The speaker mode uses its own station account.
- **Duplicates and abuse.** Pulse's behaviour is ported as is (idempotent
  classify, the capture TTL and the classify window). No new rules.
- **Discovery.** mDNS first. The firmware and the app also accept a manually
  entered server address (secrets/config for the station, a settings field in
  the app), because school networks often block multicast.
- **Android distribution.** Sideloaded APK, signed with one stable release
  key that is kept **outside** the repo (ANDROID_ID depends on the signing
  key). There is no Play Store release.

## Left to the builder

- Exact screen layouts, which follow the Penpot designs.
- Logo concept. Several options are shown in Penpot and the user picks one.
- Result-cue sound assets: free/CC0 sounds, with their provenance recorded
  in `docs/`.
- Tooling: a slim `./run` (setup, serve, test, ci) and a CI pipeline (Pint,
  tests, gitleaks, firmware native tests, app unit tests).
- Identifiers, e.g. an Android package like `co.ecostation.app`. Note that a
  new package and signing key means fresh pairing for every phone.
