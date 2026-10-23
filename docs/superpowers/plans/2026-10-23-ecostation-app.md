# EcoStation Android app: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One Android app under `app/` that is three things: a phone-as-card over the unchanged Pulse HCE protocol, a demo "speaker" that beeps on every tap and plays a cue for each classification result, and a student view showing the student's own balance and deposit history.

**Architecture:** The app is a port of `B2B-App/pulse-credential` into package `co.ecostation.app`. The HCE path is copied byte for byte with only names changed. The speaker bridge (NSD discovery, web-form login, a hand-rolled RFC 6455 client, SoundPool) is unchanged except for three things: credentials come from in-app settings, `recycling` frames map to two new cues, and the user can enter the server address by hand. The student view is a small `HttpURLConnection` + `org.json` client for the Sanctum student API (token, password change, logout, balance, history). Its token is sealed with an AES-GCM key held in the Android Keystore.

**Tech Stack:** Kotlin 1.9.24, AGP 8.5.2, compileSdk 35 / targetSdk 34 / minSdk 24, JDK 17, JUnit 4.13.2 and `org.json:json:20240303` (test only). **No AndroidX, no runtime dependencies.**

**Spec:** `docs/superpowers/specs/2026-10-23-ecostation-design.md`. Port source: `/home/jperez/Documents/B2B/B2B-App/pulse-credential` (read `README.md`, `.agent/DECISIONS/ADR-001..004` and `docs/SOUNDS.md` there). Protocol: `/home/jperez/Documents/B2B/B2B-Firmware/docs/HCE_PROTOCOL.md` v1.1.

## Global Constraints

- All UI strings, docs and log messages shown to users are **Spanish only**. There is no `values-en/`, and docs have no `.es.md` twin.
- `applicationId` and `namespace` are `co.ecostation.app`, and the sources live under `app/app/src/main/java/co/ecostation/app/`.
- The HCE wire protocol is byte-identical to `HCE_PROTOCOL.md` v1.1:
  - AID `F0010203040506`, category `other`, `requireDeviceUnlock=false`;
  - SELECT returns `HCE-OK` + `9000`;
  - CHALLENGE is `80 10 00 00 08 <nonce>` and returns `len + credId + HMAC-SHA256(K_cred, credId‖nonce) + 9000`;
  - ENROLL is `80 20 00 00 [Le]` and returns `key(32) + 9000` exactly once, otherwise `6985`;
  - status words `6A88`, `6985`, `6A82`, `6700`, `6D00` and `6E00` keep their meanings.
  The key-wrap label `pulse-hce-key-wrap/v1` is a protocol constant and does not change. The shared test vector is pinned unchanged: credId `PLS-K3Y7V3CT0R5Z`, MAC `ba6d0fbf…a295`, a 51-byte response.
- **Key material is never a build input.** No `BuildConfig` field may hold a key, password, token or email. The only `BuildConfig` fields are `ECO_AID_HEX` and `ECO_CRED_ID` (blank).
- **No credentials in BuildConfig or Gradle.** The speaker account's email and password are typed into the app's Settings screen and stored sealed (Task 4).
- **Release signing:** one stable key kept outside the repo. It is read from Gradle properties or environment variables (Task 1), and the release build fails if the key is missing or inside the repo.
- `android:allowBackup="false"` and `usesCleartextTraffic="true"` (the app talks to a LAN server only), as in Pulse.
- **Discovery:** the app browses for the NSD service `_ecostation._tcp`. If that finds nothing, it sweeps private /22-or-smaller subnets on `:8000` and accepts a host only if `GET /manifest.webmanifest` answers 200 with a body containing `"start_url"`. A manual address entered in settings skips discovery entirely.
- Sounds are CC0 or public domain only, and their provenance is recorded in `app/docs/SOUNDS.md`.
- The host test command is `cd app && ./gradlew :app:testDebugUnitTest`.

## Decisions taken in this plan

- **The no-AndroidX constraint is kept.** `androidx.security:security-crypto` (EncryptedSharedPreferences) deprecated all of its APIs in 1.1.0-alpha07 "in favour of existing platform APIs and direct use of Android Keystore" (checked against the AndroidX release notes through Context7 on 2026-10-23). Using it would add a deprecated dependency plus Tink. The Keystore-backed equivalent takes about 60 lines (Task 4).
- **The token key is generated inside the Keystore**, unlike the HCE key. Only this app ever decrypts the token, so the key never needs to leave the device. It is an AES-256-GCM key under alias `ecostation.prefs.v1`, created with no user-auth requirement and no StrongBox.
- **Identifier renames** (all local, none of them on the wire):
  - the credential-id prefix `PLS-` becomes `ECO-`, still 16 characters with the same Crockford body, so the CHALLENGE response stays 51 bytes;
  - the domain separator becomes `ecostation.credential.v1|`;
  - the Keystore HCE alias becomes `ecostation.hce.v1:<credId>`.

  Readers and the backend never parse the prefix (checked with grep in B2B-Core and B2B-Firmware). The new package and signing key mean every phone pairs again anyway (spec §Left to the builder).
- **Speaker login is unchanged from Pulse.** It uses the web `/login` form plus `GET /realtime/token`. The web plan (Tasks 11, 14 and 18) supplies a `station` role that is created with `must_change_password=false`, is allowed on `/realtime/token`, and receives every tap and recycling frame unscoped.
- **The student API is the web plan's Task 12 shape**, not a bespoke `/app/*` API: `POST /api/v1/auth/token`, `PUT /api/v1/auth/password`, `DELETE /api/v1/auth/token`, `GET /api/v1/me/balance` and `GET /api/v1/me/history`. When `must_change_password` is true, the app shows a change-password form that calls `PUT /api/v1/auth/password`. The rank is shown when present.
- **The result cue comes from the `points_awarded` update**, which the web plan (Task 9) emits exactly once per new classification, including `points == 0` (an `other` result, which awards nothing; `is_recyclable` does not change the amount). The `validated` frame carries no cue:
  - `points > 0` plays RECYCLED;
  - `points == 0` plays NOT_RECYCLABLE (an "other" result, or any material the admin rated 0).

  Every other recycling update type is silent, which gives exactly one result cue per deposit.

## Interface contract with the web plan

The web plan is the source of truth for these routes and frame shapes (Tasks 9, 11, 12, 14 and 15). Each one is pinned by a host test in this plan (named in brackets).

**Student API (Laravel Sanctum personal access tokens).** Every request sends `Accept: application/json`, and every JSON body is UTF-8.

| Call | Request | 2xx response | Errors the app handles |
|---|---|---|---|
| `POST /api/v1/auth/token` | JSON `{"email","password","device_name"}` (`device_name` = `"EcoStation Android"`) | `200 {"token":"<plain-text sanctum token>","must_change_password":bool,"student":{"id":int,"name":str,"class_name":str\|null}}` | `422` bad credentials **and** non-student accounts (one `errors.email` message, so the app cannot tell them apart); `429` throttled (`throttle:login`, 5/min per email+IP) |
| `PUT /api/v1/auth/password` | `Authorization: Bearer <token>`, JSON `{"current_password","password","password_confirmation"}` | `204` (clears `must_change_password`) | `422 {"errors":{"<field>":["<mensaje en español>"]}}` (wrong current password, too short, mismatch); `401` |
| `DELETE /api/v1/auth/token` | `Authorization: Bearer <token>` | `204` | `401` (already revoked: treated as success) |
| `GET /api/v1/me/balance` | `Authorization: Bearer <token>` | `200 {"student":{"id","name","class_name"},"balance":120,"rank":3}` (`balance` is the ledger `SUM(delta)`; `rank` is an int or `null`) | `401` token invalid or revoked; `403` while `must_change_password` is still true; `404` account not linked to a student |
| `GET /api/v1/me/history?page=N` | `Authorization: Bearer <token>` | `200` Laravel `simplePaginate(20)` envelope: `{"data":[{"deposit_id":7,"occurred_at":"2026-10-23T09:15:00-05:00","material_class":"plastic","points_awarded":10,"is_recyclable":true}],"current_page":1,"next_page_url":null,...}`, newest first | `401`, `403`, `404` as above |

Field rules:

- `occurred_at` is ISO-8601 with an offset.
- `material_class` is one of `plastic|metal|glass|paper|other`. The app also tolerates `null` or an unknown value.
- `points_awarded` is an int ≥ 0, and it is `0` for an `other` result (Pulse's rule: points follow the material class only).
- `next_page_url` is a string when another page exists, otherwise `null`.
- Extra fields are ignored. [`StudentApiTest`]

**Speaker / realtime (unchanged from Pulse):**

- **Web login.** `GET /login` returns HTML with a `name="_token"` hidden input. `POST /login` (form `_token,email,password`) returns `302` to a non-`/login` page on success, and a `302` back to `/login` on failure.
- **Feed token.** `GET /realtime/token` (session cookie, `Accept: application/json`) returns `200 {"token","expires_at","url":"ws://<host>:8081/app"}`, or `401` / `302` / `419` when the session is unauthenticated or the account still has `must_change_password`. A **station/speaker account** (role `station`, `must_change_password=false`) is allowed to call it and receives every tap and recycling frame (no teacher scoping). [`FeedSessionTest`]
- **WebSocket frames** [`FeedbackCueTest`]:
  - `{"type":"tap","event":{"feedback":"accepted"}}`. The web plan has no `served` key, and the app treats a missing `served` as accepted.
  - `{"type":"feedback","feedback":{"cue":"accepted"|"rejected"}}`
  - `{"type":"recycling","update":{"id":int,"type":"points_awarded","payload":{"points":int,"material_class":str,"new_balance":int,"student_id":int,"class_id":int|null}}}`. It is emitted **once per new classification, including when `points == 0`**, and a retried (duplicate) classify emits none. The station role receives it unscoped.
  - `hello` carries history and must never trigger a cue.
- **Discovery.**
  - The NSD service `_ecostation._tcp` uses the web port (8000) as its port, with TXT `version=1`, `protocol=1`, `api=/api`. The app reads only `protocol`.
  - `GET /manifest.webmanifest` answers 200 with `"start_url"`, so the subnet-sweep fallback can find the server.

## Review Focus

1. **A speaker account that cannot get a token after a "successful" login** (forced first-login password change, wrong role): the app shows a clear Spanish status and waits 30 s, so it never hammers the 5/min login throttle. Covered by Task 5: `ticket_refused_after_login_is_reported_as_login_rejected`.
2. **A hand-typed server address**, for example `192.168.1.10`, `192.168.1.10:8080`, `http://servidor.local:8000/`, blank, `host:99999` or `a b`: valid input parses, and invalid input is refused at save time rather than failing silently later. Covered by Task 3: `parseManual_accepts_hosts_ports_and_urls_and_refuses_garbage`.
3. **A student token that was revoked or has expired**: a `401` on `/me/balance` or `/me/history` clears the token and returns to the login form. It must not crash or loop. Covered by Task 7 (`unauthorized_is_its_own_error_kind`) and Task 8 (wiring).
4. **Recycling frames other than the result** (`capture_created`, `validated`, `leaderboard_updated`, `hello` replay, a missing `points`): each deposit gets exactly one result cue, and only from `points_awarded`. Covered by Task 5: `recycling_frames_give_one_result_cue_per_deposit`.
5. **History payloads that are odd but valid**: an empty `data` list, a `null` or unknown `material_class`, a non-ASCII name (`Martínez`), extra fields, a `null` `rank` or `class_name`. All of them parse without throwing, and unknown materials show as "Otro". Covered by Task 7: `history_tolerates_null_unknown_and_extra_fields`.
6. **First login with a forced password change**: the token response says `must_change_password: true`, `/me/*` answers 403 until the password is changed, and the app shows the change form (also after a relaunch with a stored token), then loads the account. A failed change shows the server's Spanish message. Covered by Task 7 (`login_reports_must_change_password`, `password_change_sends_bearer_and_maps_422`) and Task 8.

---

### Task 1: Gradle project, package, release signing

**Model:** sonnet

**Files:**
- Create: `app/settings.gradle.kts`, `app/build.gradle.kts`, `app/gradle.properties`, and `app/gradle/wrapper/*` plus `app/gradlew` (copied from pulse-credential).
- Create: `app/app/build.gradle.kts`, `app/app/src/main/AndroidManifest.xml`, `app/app/src/main/res/values/strings.xml`, `app/app/src/main/res/values/themes.xml`.
- Create: `app/.gitignore`, `app/README.md`.

**Interfaces:**
- Produces:
  - module `:app` with namespace and applicationId `co.ecostation.app`;
  - `BuildConfig.ECO_AID_HEX = "F0010203040506"` and `BuildConfig.ECO_CRED_ID = ""`;
  - test deps `junit:junit:4.13.2` and `org.json:json:20240303`;
  - `useLibrary("android.test.runner" | "android.test.base" | "android.test.mock")` and `testInstrumentationRunner = "android.test.InstrumentationTestRunner"`, both as in Pulse.

- [ ] **Step 1: Copy the Gradle skeleton.** Copy `settings.gradle.kts`, the top-level `build.gradle.kts`, `gradle.properties`, `gradlew` and `gradle/wrapper/` from pulse-credential, with `rootProject.name = "EcoStation"`.
- [ ] **Step 2: Write `app/app/build.gradle.kts`** from Pulse's file, with these changes:
  - `namespace` and `applicationId` become `co.ecostation.app`;
  - the `PULSE_FEED_*` fields and the `pulseFeed*` properties are deleted;
  - the remaining fields are renamed to `ECO_AID_HEX` and `ECO_CRED_ID`.

  Then add the release signing config:

  ```kotlin
  fun signingInput(prop: String, env: String): String? =
      (findProperty(prop) as String?)?.takeIf { it.isNotBlank() } ?: System.getenv(env)?.takeIf { it.isNotBlank() }
  // ecostation.signing.storeFile / ECOSTATION_SIGNING_STORE_FILE, …storePassword, …keyAlias, …keyPassword
  ```

  - **Missing inputs.** If any of the four is missing, `signingConfigs.release` is not configured. In that case a `tasks.matching { it.name == "packageRelease" }.configureEach { doFirst { throw GradleException("Falta la llave de firma de release: ver app/README.md §Firma") } }` guard fires.
  - **Key inside the repo.** The same guard throws if `file(storeFile).canonicalPath` starts with `rootDir.parentFile.canonicalPath`, i.e. if the keystore sits inside the monorepo.
  - **Debug builds** are untouched.
- [ ] **Step 3: Manifest, strings and theme.**
  - **Manifest:** copy Pulse's manifest with the NFC, INTERNET and ACCESS_NETWORK_STATE permissions, `hce` as a required feature, `allowBackup=false` and `usesCleartextTraffic=true`. The service and activities are added by later tasks. Leave only `MainActivity` (an empty `Activity` with `setContentView(TextView)`) so the app builds.
  - **Strings:** `app_name` = `EcoStation`.
  - **Theme:** `Theme.EcoStation` with parent `android:Theme.Material.Light.NoActionBar`.
- [ ] **Step 4: Ignore and document.**
  - **`.gitignore`:** Pulse's entries plus `*.jks`, `*.keystore` and `keystore.properties`.
  - **`app/README.md`** (Spanish) gets these sections:
    - **Requisitos.**
    - **Compilar.**
    - **Firma de release:** how to generate the key once with `keytool -genkeypair -v -keystore ~/.ecostation/release.jks -alias ecostation -keyalg RSA -keysize 4096 -validity 10000`, where to set the four properties (`~/.gradle/gradle.properties`) or the env vars, and a warning that losing or changing the key changes every phone's credential id (ANDROID_ID is per signing key) and forces re-pairing.
    - **Decisiones:** no AndroidX, and why.
- [ ] **Step 5: Verify.**

  Run: `cd app && ./gradlew :app:assembleDebug :app:testDebugUnitTest`. Expected: BUILD SUCCESSFUL.

  Run: `cd app && ./gradlew :app:assembleRelease`. Expected: FAIL with `Falta la llave de firma de release`.

  Then generate a throwaway key in `/tmp`, run `./gradlew :app:assembleRelease -Pecostation.signing.storeFile=/tmp/x.jks …`, and check that `apksigner verify --print-certs app/app/build/outputs/apk/release/app-release.apk` prints the throwaway certificate. Finally pass a storeFile inside the repo. Expected: FAIL.
- [ ] **Step 6: Commit.** `git add app && git commit -m "feat(app): proyecto Android co.ecostation.app con firma de release externa"`

### Task 2: HCE port (phone-as-card)

**Model:** opus

**Files** (all under `app/app/src/`):
- Create, each ported from the Pulse file of the same role, under package `co.ecostation.app.hce`:
  - `main/java/co/ecostation/app/hce/ApduProtocol.kt`
  - `CredentialId.kt`
  - `CredentialKeyStore.kt`
  - `EnrollmentWindow.kt`
  - `HceResponder.kt` (including `CredentialRuntime`)
  - `EcoHostApduService.kt` (including `HceEvents`)
- Create: `main/res/xml/apduservice.xml` (copied verbatim; `@string/hce_description` and `@string/aid_group_description` = `Credencial EcoStation`).
- Create: `main/java/co/ecostation/app/MainActivity.kt` and `main/res/layout/activity_main.xml` (plain layout).
- Tests:
  - `test/java/co/ecostation/app/hce/ApduProtocolTest.kt`, `HceResponderTest.kt`, `EnrollmentWindowTest.kt`, `InMemoryCredentialKeyStore.kt`;
  - `androidTest/java/co/ecostation/app/hce/EcoHostApduServiceTest.kt`.
- Modify: `main/AndroidManifest.xml` to add the `EcoHostApduService` block, copied from Pulse.

**Interfaces:**
- Produces: the same public API as Pulse:
  - `ApduProtocol.process(cmd, session, credentialId, keys, window, nowMs): ByteArray`
  - `ApduProtocol.CRED_ID` (var), `ApduProtocol.SW_KEY_MISSING`, `ApduProtocol.routingReport(isDefault: Boolean?, aids: List<String>?): String`
  - `CredentialId.derive(seed): String`, `isWellFormed(id: String?): Boolean`, `install(context)`
  - `CredentialKeyStore` (`hasKey`, `provision`, `mac`, `delete`), implemented by `AndroidKeystoreCredentialKeyStore`
  - `HceResponder(keys, enrollment, credentialId, clockMs)` with `respond`, `deactivate` and `describe(cmd, resp): String` (the strings `"CHALLENGE -> 9000"`, `"CHALLENGE -> no key"`, `"CHALLENGE -> error"`, `"ENROLL -> 9000"`, `"SELECT AID -> 9000"` are unchanged, because Task 5 keys off them)
  - `CredentialRuntime.keys`, `.enrollment`, `.beginEnrollment(): Boolean`
  - `HceEvents.addListener/removeListener/emit/history`

- [ ] **Step 1: Port the four test files with the renames applied, keeping every assertion.** Port `ApduProtocolTest`, `HceResponderTest`, `EnrollmentWindowTest` and `InMemoryCredentialKeyStore` from Pulse. Required deltas:

  ```kotlin
  // buildConfig test
  assertEquals("F0010203040506", BuildConfig.ECO_AID_HEX)
  assertEquals("", BuildConfig.ECO_CRED_ID)
  // every BuildConfig field name must not match (?i)(secret|key|password|token|email); every String value must not be 64-hex
  // credentialId test
  assertTrue(a.startsWith("ECO-")); assertEquals(16, a.length)
  assertFalse(a.contains("androidid-aaaaaaaa"))
  assertTrue(CredentialId.isWellFormed("ECO-K3Y7V3CT0R5Z"))
  assertFalse(CredentialId.isWellFormed("PLS-K3Y7V3CT0R5Z"))   // old product prefix is not ours
  // the shared vector stays literal and byte-identical (VECTOR_CRED = "PLS-K3Y7V3CT0R5Z")
  assertArrayEquals(hex(VECTOR_RESPONSE), resp); assertEquals(51, resp.size)
  ```

  The vector test only uses the literal id as a string. It does not call `isWellFormed`, so the vector does not depend on the prefix.
- [ ] **Step 2: Run the tests to confirm they fail.**

  Run: `cd app && ./gradlew :app:testDebugUnitTest --tests 'co.ecostation.app.hce.*'`. Expected: compilation FAIL (unresolved `ApduProtocol`).
- [ ] **Step 3: Port the sources.** Copy each source file and change only the following:
  - the package;
  - `BuildConfig.PULSE_*` becomes `ECO_*`;
  - `CredentialId`: `PREFIX = "ECO-"`, `DOMAIN = "ecostation.credential.v1|"`, `PREFS = "ecostation.credential"`;
  - `AndroidKeystoreCredentialKeyStore`: `ALIAS_PREFIX = "ecostation.hce.v1:"`;
  - `PulseHostApduService` is renamed `EcoHostApduService`;
  - Pulse/bilingual comments are trimmed to Spanish where they are user-facing.

  The protocol logic, the status words, the window TTL (60 s) and the key policy (imported `PURPOSE_SIGN` HMAC, no user auth, no StrongBox) stay unchanged.
- [ ] **Step 4: Plain `MainActivity`** (no `CredentialCardView`, no brand assets; that work is in Task 9). It has a vertical `LinearLayout` with:
  - a status `TextView`: `LISTA`, `SIN VINCULAR`, `VINCULANDO 0:42`, `LLAVE ENVIADA`, `VERIFICADA`, `NFC APAGADO` or `NO COMPATIBLE`, using the same state logic as Pulse's `currentState()`;
  - a "Vincular este teléfono" button (Pulse's `askToLink` dialog, Spanish copy);
  - a diagnostics `TextView` (HCE capability, `routingReport`, key present, the last 5 `HceEvents`).

  `CredentialId.install(this)` runs in `onCreate`. Port all strings from Pulse's `values/strings.xml`, Spanish only, replacing "Pulse" with "EcoStation".
- [ ] **Step 5: Port the instrumented test.** `EcoHostApduServiceTest` is ported from `PulseHostApduServiceTest` with the same cases (real service, real Keystore, its own test credential id, `getEncoded()==null`).
- [ ] **Step 6: Verify.**

  Run: `cd app && ./gradlew :app:testDebugUnitTest`. Expected: PASS, including `challenge_answer_matches_the_shared_pulse_vector_byte_for_byte`.

  Run on a device: `./gradlew :app:connectedDebugAndroidTest`. Expected: PASS.

  Bench check (if a station is available): pair at the station in PAIRING mode, then tap, and confirm the server shows the tap.
- [ ] **Step 7: Commit.** `git commit -m "feat(app): credencial HCE portada de Pulse (protocolo v1.1 sin cambios)"`

### Task 3: Server endpoint, manual address and discovery

**Model:** sonnet

**Files:**
- Create: `app/app/src/main/java/co/ecostation/app/net/EcoEndpoint.kt`, ported from `PulseEndpoint.kt`.
- Create: `app/app/src/main/java/co/ecostation/app/net/BackendDiscovery.kt`, ported.
- Test: `app/app/src/test/java/co/ecostation/app/net/EcoEndpointTest.kt`.

**Interfaces:**
- Produces:
  - `data class EcoEndpoint(val host: String, val port: Int)` with `baseUrl` and `toString()` = `host:port` (IPv6 in brackets).
  - `EcoEndpoint.Candidate(ip: String?, port: Int, protocolTxt: String?)`.
  - In the companion object:
    - constants `SERVICE_TYPE = "_ecostation._tcp"`, `PROTOCOL_VERSION = "1"`, `DEFAULT_API_PORT = 8000`;
    - `pick(List<Candidate>): EcoEndpoint?`;
    - `probeHosts(address: ByteArray, prefixLength: Int): List<String>`;
    - `parseManual(text: String?): EcoEndpoint?`;
    - `locate(manual: String?, discover: () -> EcoEndpoint?): EcoEndpoint?`. A non-blank, parseable `manual` wins and `discover` is never called; a blank `manual` calls `discover()`; a non-blank but unparseable `manual` returns null.
  - `class BackendDiscovery(context) { fun discover(log: (String) -> Unit): EcoEndpoint? }`. The probe confirms a host through `/manifest.webmanifest` containing `"start_url"`.

- [ ] **Step 1: Write the failing tests.** Port `pickMirrorsTheFirmwarePolicy` and `probeHostsOnlySweepsSmallPrivateSubnets` from Pulse's `FeedbackBridgeTest`, then add:

  ```kotlin
  @Test fun parseManual_accepts_hosts_ports_and_urls_and_refuses_garbage() {
      assertEquals(EcoEndpoint("192.168.1.10", 8000), EcoEndpoint.parseManual("192.168.1.10"))
      assertEquals(EcoEndpoint("192.168.1.10", 8080), EcoEndpoint.parseManual(" 192.168.1.10:8080 "))
      assertEquals(EcoEndpoint("servidor.local", 8000), EcoEndpoint.parseManual("http://servidor.local:8000/"))
      assertNull(EcoEndpoint.parseManual(""))
      assertNull(EcoEndpoint.parseManual(null))
      assertNull(EcoEndpoint.parseManual("host:99999"))
      assertNull(EcoEndpoint.parseManual("host:0"))
      assertNull(EcoEndpoint.parseManual("a b"))
      assertNull(EcoEndpoint.parseManual("https://x"))   // LAN server is plain http
  }
  @Test fun manual_address_wins_over_discovery() {
      var called = false
      assertEquals(EcoEndpoint("10.0.0.5", 8000), EcoEndpoint.locate("10.0.0.5") { called = true; null })
      assertFalse(called)
      assertEquals(EcoEndpoint("10.0.0.9", 8000), EcoEndpoint.locate("  ") { EcoEndpoint("10.0.0.9", 8000) })
      assertNull(EcoEndpoint.locate("a b") { EcoEndpoint("10.0.0.9", 8000) })
  }
  @Test fun service_type_is_ecostation() = assertEquals("_ecostation._tcp", EcoEndpoint.SERVICE_TYPE)
  ```
- [ ] **Step 2: Run the tests to confirm they fail.**

  Run: `./gradlew :app:testDebugUnitTest --tests '*EcoEndpointTest*'`. Expected: compilation FAIL.
- [ ] **Step 3: Port and implement.**
  - **Port** `PulseEndpoint` and `BackendDiscovery` with the renames. Discovery logic, timeouts and cellular exclusion are unchanged.
  - **`parseManual`** strips an optional `http://` prefix and a trailing `/`, then splits the host from an optional `:port`. The host must match `[A-Za-z0-9.-]+` or be an IPv4 literal, and the port must be in 1..65535.
- [ ] **Step 4: Run the tests to confirm they pass.** Same command. Expected: PASS.
- [ ] **Step 5: Commit.** `git commit -m "feat(app): descubrimiento _ecostation._tcp y dirección manual"`

### Task 4: Sealed settings (Keystore AES-GCM)

**Model:** opus

**Files:**
- Create: `app/app/src/main/java/co/ecostation/app/store/Sealer.kt` (pure JVM).
- Create: `app/app/src/main/java/co/ecostation/app/store/AppSettings.kt` (Android).
- Test: `app/app/src/test/java/co/ecostation/app/store/SealerTest.kt`.

**Interfaces:**
- Produces:
  - `object Sealer`:
    - `fun seal(key: javax.crypto.SecretKey, plaintext: String): String` returns `"v1:" + hex(iv(12) ‖ ciphertext‖tag(128-bit))`, using AES/GCM/NoPadding with a fresh IV every call. On Android, let the Keystore cipher choose the IV (`cipher.init(ENCRYPT_MODE, key)` and then `cipher.iv`), because Keystore keys refuse caller-chosen IVs when randomized encryption is required.
    - `fun open(key: SecretKey, sealed: String?): String?` returns null on a null or blank input, a wrong prefix, bad hex, an authentication failure or any exception. It never throws.
  - `class AppSettings(context: Context)`:
    - plain prefs (`ecostation.settings`): `var serverAddress: String?` and `var speakerEnabled: Boolean`;
    - sealed prefs (`ecostation.sealed`, values via `Sealer`): `var speakerEmail: String?`, `var speakerPassword: String?` and `var studentToken: String?`. Setting one to null removes it.
    - The Keystore key is AES-256 under alias `ecostation.prefs.v1`, created lazily through `KeyGenParameterSpec.Builder(alias, PURPOSE_ENCRYPT or PURPOSE_DECRYPT).setBlockModes(GCM).setEncryptionPaddings(NONE).setKeySize(256)`, with no user auth and no StrongBox.
    - If a sealed value fails to open (key invalidated), the getter returns null and deletes the stale value, so the user simply signs in again.

- [ ] **Step 1: Write the failing tests.** Use a software `SecretKeySpec(ByteArray(32){it.toByte()}, "AES")` as the key:

  ```kotlin
  @Test fun round_trips_utf8() = assertEquals("contraseña ñ 🔑", Sealer.open(k, Sealer.seal(k, "contraseña ñ 🔑")))
  @Test fun same_plaintext_seals_differently() = assertNotEquals(Sealer.seal(k, "x"), Sealer.seal(k, "x"))
  @Test fun output_does_not_contain_the_plaintext() {
      val s = Sealer.seal(k, "1|abcdefTOKEN"); assertTrue(s.startsWith("v1:")); assertFalse(s.contains("TOKEN")) }
  @Test fun tampering_wrong_key_and_garbage_open_to_null() {
      val s = Sealer.seal(k, "secreto")
      val flipped = s.dropLast(1) + (if (s.last() == '0') '1' else '0')
      assertNull(Sealer.open(k, flipped)); assertNull(Sealer.open(otherKey, s))
      assertNull(Sealer.open(k, "v1:zz")); assertNull(Sealer.open(k, "v0:" + s.drop(3)))
      assertNull(Sealer.open(k, "")); assertNull(Sealer.open(k, null)) }
  ```
- [ ] **Step 2: Run the tests to confirm they fail.**

  Run: `./gradlew :app:testDebugUnitTest --tests '*SealerTest*'`. Expected: compilation FAIL.
- [ ] **Step 3: Implement `Sealer`, then `AppSettings`.** Use hex, not `java.util.Base64`, which needs API 26 while minSdk is 24.
- [ ] **Step 4: Run the tests to confirm they pass.** Same command. Expected: PASS.
- [ ] **Step 5: Commit.** `git commit -m "feat(app): ajustes sellados con llave AES-GCM del Keystore"`

### Task 5: Speaker logic: cues, realtime frames, session, WebSocket

**Model:** sonnet

**Files:**
- Create: `app/app/src/main/java/co/ecostation/app/speaker/FeedbackCue.kt`, `WsClient.kt` (ported unchanged) and `FeedSession.kt` (ported).
- Test: `app/app/src/test/java/co/ecostation/app/speaker/FeedbackCueTest.kt`, `WsClientTest.kt` and `FeedSessionTest.kt`. These are split from Pulse's `FeedbackBridgeTest` together with its fake socket servers.

**Interfaces:**
- Consumes: `EcoEndpoint` (Task 3).
- Produces:
  - `enum class FeedbackCue { DETECTED, ACCEPTED, REJECTED, RECYCLED, NOT_RECYCLABLE }`. It has **no `rawRes`**; Task 6 maps cues to sounds.
    - `fromFrame(text: String): FeedbackCue?`
    - `fromHceEvent(event: String): FeedbackCue?`
  - `class WsClient` with `connect(url)`, `readLoop(isActive, onText)`, `close()`, `acceptKey`, `encodeClientFrame` and `readFrame`, all unchanged.
  - `class FeedSession(email: String, password: String)` with `ticket(endpoint: EcoEndpoint): Ticket(wsUrl)`, plus `LoginRejected` and `Throttled` (both `IOException`) and `csrfToken(html)`.

- [ ] **Step 1: Write the failing tests.**
  - Port `onlyLiveTapFramesSound`, but remove its line asserting that `{"type":"recycling","update":{}}` is null (the next test covers it).
  - Port `localHceExchangeMapsToDetectedOrRejected`, all the WebSocket tests, and `ticketLogsInOnceThenReusesTheSession`, `wrongPasswordIsReportedAsLoginRejected` and `csrfTokenIsReadFromTheLaravelForm`. Rename the fake server's cookie to `ecostation_session` and its post-login redirect to `/station`.
  - Add:

  ```kotlin
  @Test fun recycling_frames_give_one_result_cue_per_deposit() {
      fun upd(type: String, payload: String) = """{"type":"recycling","update":{"id":1,"type":"$type","payload":$payload}}"""
      assertEquals(FeedbackCue.RECYCLED, FeedbackCue.fromFrame(upd("points_awarded", """{"points":10,"material_class":"plastic","new_balance":40}""")))
      assertEquals(FeedbackCue.NOT_RECYCLABLE, FeedbackCue.fromFrame(upd("points_awarded", """{"points":0,"material_class":"other","new_balance":30}""")))
      assertNull(FeedbackCue.fromFrame(upd("points_awarded", """{"material_class":"plastic"}""")))   // no points field: silent
      for (t in listOf("capture_created", "validation_started", "validated", "leaderboard_updated"))
          assertNull(t, FeedbackCue.fromFrame(upd(t, """{"points":10,"is_recyclable":true}""")))
      assertNull(FeedbackCue.fromFrame("""{"type":"recycling","update":{}}"""))
      assertNull(FeedbackCue.fromFrame("""{"type":"hello","recycling":[{"type":"points_awarded","payload":{"points":10}}]}"""))
  }
  @Test fun ticket_refused_after_login_is_reported_as_login_rejected() {
      // fake: POST /login -> 302 /password/change (success), /realtime/token -> 302 even with the session
      assertThrows(FeedSession.LoginRejected::class.java) { session.ticket(endpoint) }
  }
  ```
- [ ] **Step 2: Run the tests to confirm they fail.**

  Run: `./gradlew :app:testDebugUnitTest --tests 'co.ecostation.app.speaker.*'`. Expected: compilation FAIL.
- [ ] **Step 3: Implement.**
  - **`fromFrame`** adds a `"recycling"` branch. It reads `update.type`; when that is `points_awarded` and `payload.has("points")`, it returns `if (payload.getInt("points") > 0) RECYCLED else NOT_RECYCLABLE`. Everything else returns null. The `tap`, `feedback` and `hello` handling is unchanged.
  - **`FeedSession.ticket`:** the old final `?: throw IOException("token refused after login")` becomes `throw LoginRejected("la cuenta del parlante no puede abrir el feed: revisa su rol o cambia su contraseña en la web")`.
- [ ] **Step 4: Run the tests to confirm they pass.** Same command. Expected: PASS.
- [ ] **Step 5: Commit.** `git commit -m "feat(app): cues de resultado de reciclaje en el feed en tiempo real"`

### Task 6: Speaker mode wiring, sounds, settings screen

**Model:** sonnet

**Files:**
- Create: `app/app/src/main/java/co/ecostation/app/speaker/FeedbackBridge.kt` and `FeedbackSounds.kt`, both ported.
- Create: `app/app/src/main/java/co/ecostation/app/SettingsActivity.kt` and `res/layout/activity_settings.xml`.
- Create: `app/app/src/main/res/raw/eco_beep_detected.ogg`, `eco_beep_accepted.ogg`, `eco_beep_rejected.ogg`, `eco_cue_recycled.ogg` and `eco_cue_not_recyclable.ogg`.
- Create: `app/tools/gen_sounds.sh`, extended from Pulse's `gen_beeps.sh`, and `app/docs/SOUNDS.md`.
- Create: `app/app/src/test/java/co/ecostation/app/speaker/LiveBackendE2ETest.kt` (opt-in), ported.
- Modify: `MainActivity.kt` and `activity_main.xml` (a "Ajustes" button, bridge status lines), `AndroidManifest.xml` (`SettingsActivity`) and `app/README.md` (a §Modo parlante section).

**Interfaces:**
- Consumes:
  - `AppSettings` (Task 4);
  - `EcoEndpoint.locate` and `BackendDiscovery` (Task 3);
  - `FeedbackCue`, `FeedSession` and `WsClient` (Task 5);
  - `HceEvents` and `HceResponder.describe` strings (Task 2).
- Produces:
  - `FeedbackBridge(context, settings: AppSettings, onCue: (FeedbackCue) -> Unit, onStatus: (String) -> Unit)` with `start()` and `stop()`;
  - `FeedbackSounds(context)` with `play(cue)`, `startKeepAlive()`, `stopKeepAlive()`, `outputDescription()` and `release()`, plus a private `rawFor(cue)` mapping to the five files above.

- [ ] **Step 1: Sounds.**
  - **Port the three existing beeps** (renamed `eco_beep_*`) from Pulse's sources and processing, unchanged.
  - **Build the two new cues from the same public-domain `Beep_example.ogg`** (D V S, Wikimedia Commons). It already contains 294, 440, 524 and 660 Hz steps, so no new license enters the repo:
    - `eco_cue_recycled`: a rising pair, 524 Hz then 660 Hz, about 0.18 s each, a 40 ms gap, 10 ms fades;
    - `eco_cue_not_recyclable`: a falling pair, 440 Hz then 294 Hz, the same timing.
  - **Find the step boundaries first** with the windowed-FFT method in Pulse's `docs/SOUNDS.md` §Verification, then hard-code the `atrim` times in `gen_sounds.sh`.
  - **Verify each output** by FFT. Every slice must hold a single steady frequency within ±3 Hz, with no neighbouring step bleeding in.
  - **Write `app/docs/SOUNDS.md`** in Spanish: the sources table, the assets table (all five, with cue and processing) and the verification results, matching the Pulse file's structure.
- [ ] **Step 2: Port `FeedbackSounds`**, loading all five cues. The output-description text is in Spanish.
- [ ] **Step 3: Port `FeedbackBridge`** with these changes. The backoff, nudges, liveness flag and lifecycle are unchanged.
  - The session is built per attempt from `settings.speakerEmail` and `settings.speakerPassword`. If either is blank, the status is `falta la cuenta del parlante en Ajustes` and the bridge waits 30 s.
  - The endpoint is `EcoEndpoint.locate(settings.serverAddress) { discovery.discover(::log) }`.
  - Log tag `EcoSpeaker`, thread name `eco-speaker`.
  - Spanish statuses: `buscando servidor…`, `servidor <ep>: iniciando sesión…`, `EN VIVO · <ep>`, `reintento en Ns: <motivo>`.
- [ ] **Step 4: Wire `MainActivity`.**
  - In `onStart`, the bridge runs **only if `settings.speakerEnabled`**, and `stop()` runs in `onStop`.
  - Local `HceEvents` map through `FeedbackCue.fromHceEvent` and play whether or not speaker mode is on, as in Pulse.
  - Keep-screen-on and `volumeControlStream = STREAM_MUSIC` are set only while speaker mode is on.
  - Add two diagnostics lines, "Eventos del servidor (sonido)" and "Salida de audio". Tapping the audio line plays all five cues.
- [ ] **Step 5: `SettingsActivity`** (plain form) with these fields:
  - "Dirección del servidor (opcional)", showing a hint with the example `192.168.1.10:8000`;
  - a "Modo parlante" switch;
  - "Correo de la cuenta del parlante";
  - "Contraseña" (an `inputType` password field, shown masked once saved and never pre-filled in clear).

  On "Guardar", a non-blank address that `EcoEndpoint.parseManual` rejects shows `Dirección no válida` and nothing is saved. Valid values are saved to `AppSettings`.
- [ ] **Step 6: Port `LiveBackendE2ETest`.**
  - Env vars `ECO_E2E_BASE`, `ECO_E2E_EMAIL`, `ECO_E2E_PASSWORD`, `ECO_E2E_READER_KEY` and `ECO_E2E_CARD`. There is no default account: it is skipped unless all are set.
  - Drop the meal-reader round.
- [ ] **Step 7: Verify.**

  Run: `./gradlew :app:testDebugUnitTest :app:assembleDebug`. Expected: PASS. The live test is skipped.

  Manual (device plus a running web server):
  1. With speaker mode off, no network traffic appears in `adb logcat -s EcoSpeaker`.
  2. With it on and a station account, a tap plays accepted, and a classified plastic deposit then plays RECYCLED.
  3. With a wrong password, the status shows the rejection and retries after 30 s.
  4. With a manual address set and mDNS blocked, it still connects.
- [ ] **Step 8: Commit.** `git commit -m "feat(app): modo parlante con cuenta de estación y cues de resultado"`

### Task 7: Student API client

**Model:** sonnet

**Files:**
- Create: `app/app/src/main/java/co/ecostation/app/student/StudentApi.kt` and `Deposits.kt`.
- Test: `app/app/src/test/java/co/ecostation/app/student/StudentApiTest.kt`, with a ~40-line `FakeHttp` `ServerSocket` helper in the same file that maps `"METHOD path"` to `(status, body)` and records the last request path (with query), headers and body.

**Interfaces:**
- Consumes: `EcoEndpoint` (Task 3).
- Produces:
  - `data class StudentInfo(val id: Int, val name: String, val className: String?)`
  - `data class LoginResult(val token: String, val mustChangePassword: Boolean, val student: StudentInfo)`
  - `data class Balance(val student: StudentInfo, val balance: Int, val rank: Int?)`
  - `data class Deposit(val depositId: Long, val occurredAt: String, val materialClass: String?, val points: Int, val isRecyclable: Boolean)`
  - `data class HistoryPage(val items: List<Deposit>, val hasMore: Boolean)` (`hasMore` is `next_page_url` being a non-null string)
  - `class ApiException(val kind: Kind, message: String) : IOException(message)`, with `enum class Kind { BAD_CREDENTIALS, VALIDATION, PASSWORD_CHANGE_REQUIRED, NOT_LINKED, THROTTLED, UNAUTHORIZED, SERVER }`. For `VALIDATION`, `message` is the first message found under `errors` in the 422 body (Spanish, from the server).
  - `class StudentApi(endpoint: EcoEndpoint)`:
    - `fun login(email: String, password: String): LoginResult`;
    - `fun changePassword(token: String, current: String, new: String, confirm: String)`;
    - `fun logout(token: String)` (a `401` counts as success);
    - `fun balance(token: String): Balance`;
    - `fun history(token: String, page: Int = 1): HistoryPage`.

    All are blocking `HttpURLConnection` calls with 6 s timeouts, `Accept: application/json` and UTF-8. Status mapping:

    | Call | Status | Kind |
    |---|---|---|
    | `login` | 422 | `BAD_CREDENTIALS` |
    | `changePassword` | 422 | `VALIDATION` |
    | any | 429 | `THROTTLED` |
    | any with a token | 401 | `UNAUTHORIZED` |
    | `balance`, `history` | 403 | `PASSWORD_CHANGE_REQUIRED` |
    | `balance`, `history` | 404 | `NOT_LINKED` |
    | anything else non-2xx | | `SERVER` |
  - `fun materialLabel(materialClass: String?): String` maps `plastic` to "Plástico", `metal` to "Metal", `glass` to "Vidrio", `paper` to "Papel", and anything else, including null, to "Otro".
  - `fun displayTime(occurredAt: String): String` returns `dd/MM HH:mm` in `America/Bogota`, parsed with `SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.ROOT)`. Unparseable input is returned unchanged.

- [ ] **Step 1: Write the failing tests.**

  ```kotlin
  @Test fun login_posts_json_and_returns_token_flag_and_student() {
      fake.on("POST /api/v1/auth/token", 200, """{"token":"5|abc","must_change_password":false,"student":{"id":3,"name":"Ana Martínez","class_name":"5° A"},"extra":1}""")
      val r = api.login("ana@ecostation.test", "clave ñ")
      assertEquals("5|abc", r.token); assertFalse(r.mustChangePassword)
      assertEquals(StudentInfo(3, "Ana Martínez", "5° A"), r.student)
      val req = JSONObject(fake.lastBody); assertEquals("ana@ecostation.test", req.getString("email"))
      assertEquals("clave ñ", req.getString("password")); assertEquals("EcoStation Android", req.getString("device_name"))
      assertEquals("application/json", fake.lastHeader("Accept")) }
  @Test fun login_reports_must_change_password() {
      fake.on("POST /api/v1/auth/token", 200, """{"token":"t","must_change_password":true,"student":{"id":3,"name":"Ana","class_name":null}}""")
      val r = api.login("a", "b"); assertTrue(r.mustChangePassword); assertNull(r.student.className) }
  @Test fun login_errors_map_to_kinds() {
      mapOf(422 to "{}" to Kind.BAD_CREDENTIALS, 429 to "{}" to Kind.THROTTLED, 500 to "oops" to Kind.SERVER)
      // each: fake.on("POST /api/v1/auth/token", status, body); assertEquals(kind, assertThrows(ApiException::class.java) { api.login("a","b") }.kind)
  }
  @Test fun password_change_sends_bearer_and_maps_422() {
      fake.on("PUT /api/v1/auth/password", 204, "")
      api.changePassword("5|abc", "vieja", "nueva-clave-1", "nueva-clave-1")
      val req = JSONObject(fake.lastBody); assertEquals("vieja", req.getString("current_password"))
      assertEquals("nueva-clave-1", req.getString("password")); assertEquals("nueva-clave-1", req.getString("password_confirmation"))
      assertEquals("Bearer 5|abc", fake.lastHeader("Authorization"))
      fake.on("PUT /api/v1/auth/password", 422, """{"message":"x","errors":{"current_password":["La contraseña actual es incorrecta."]}}""")
      val e = assertThrows(ApiException::class.java) { api.changePassword("t", "x", "y", "y") }
      assertEquals(Kind.VALIDATION, e.kind); assertEquals("La contraseña actual es incorrecta.", e.message) }
  @Test fun logout_sends_delete_and_tolerates_401() {
      fake.on("DELETE /api/v1/auth/token", 204, ""); api.logout("5|abc"); assertEquals("Bearer 5|abc", fake.lastHeader("Authorization"))
      fake.on("DELETE /api/v1/auth/token", 401, "{}"); api.logout("5|abc") }
  @Test fun balance_sends_bearer_and_parses_utf8_rank_and_null_rank() {
      fake.on("GET /api/v1/me/balance", 200, """{"student":{"id":3,"name":"Ana Martínez","class_name":"5° A"},"balance":120,"rank":3,"extra":1}""")
      assertEquals(Balance(StudentInfo(3, "Ana Martínez", "5° A"), 120, 3), api.balance("5|abc")); assertEquals("Bearer 5|abc", fake.lastHeader("Authorization"))
      fake.on("GET /api/v1/me/balance", 200, """{"student":{"id":3,"name":"Ana","class_name":null},"balance":0,"rank":null}""")
      assertNull(api.balance("t").rank) }
  @Test fun balance_and_history_error_kinds() {
      for (path in listOf("/api/v1/me/balance", "/api/v1/me/history")) {
          fun kind() = assertThrows(ApiException::class.java) { if (path.endsWith("balance")) api.balance("x") else api.history("x") }.kind
          fake.on("GET $path", 401, """{"message":"Unauthenticated."}"""); assertEquals(Kind.UNAUTHORIZED, kind())
          fake.on("GET $path", 403, """{"message":"x"}"""); assertEquals(Kind.PASSWORD_CHANGE_REQUIRED, kind())
          fake.on("GET $path", 404, "{}"); assertEquals(Kind.NOT_LINKED, kind()) } }
  @Test fun unauthorized_is_its_own_error_kind() {
      fake.on("GET /api/v1/me/balance", 401, """{"message":"Unauthenticated."}""")
      assertEquals(Kind.UNAUTHORIZED, assertThrows(ApiException::class.java) { api.balance("x") }.kind) }
  @Test fun history_tolerates_null_unknown_and_extra_fields() {
      fake.on("GET /api/v1/me/history", 200, """{"current_page":1,"next_page_url":"http://x/api/v1/me/history?page=2","data":[
          {"deposit_id":9,"occurred_at":"2026-10-23T09:15:00-05:00","material_class":"plastic","points_awarded":10,"is_recyclable":true,"photo":"x"},
          {"deposit_id":8,"occurred_at":"2026-10-22T14:00:00-05:00","material_class":null,"points_awarded":0,"is_recyclable":false},
          {"deposit_id":7,"occurred_at":"2026-10-21T08:00:00-05:00","material_class":"cartón","points_awarded":0}]}""")
      val h = api.history("t"); assertEquals(3, h.items.size); assertTrue(h.hasMore)
      assertEquals(Deposit(9, "2026-10-23T09:15:00-05:00", "plastic", 10, true), h.items[0]); assertEquals("/api/v1/me/history?page=1", fake.lastPath)
      assertNull(h.items[1].materialClass); assertEquals("Otro", materialLabel(h.items[1].materialClass)); assertEquals("Otro", materialLabel(h.items[2].materialClass))
      fake.on("GET /api/v1/me/history", 200, """{"data":[],"next_page_url":null}""")
      val e = api.history("t", 2); assertTrue(e.items.isEmpty()); assertFalse(e.hasMore); assertTrue(fake.lastPath.endsWith("page=2")) }
  @Test fun labels_and_times_are_spanish_and_bogota() {
      assertEquals("Vidrio", materialLabel("glass")); assertEquals("23/10 09:15", displayTime("2026-10-23T14:15:00Z"))
      assertEquals("23/10 09:15", displayTime("2026-10-23T09:15:00-05:00")); assertEquals("ayer", displayTime("ayer")) }
  @Test fun malformed_success_body_is_a_server_error() {
      fake.on("GET /api/v1/me/balance", 200, "<html>"); assertEquals(Kind.SERVER, assertThrows(ApiException::class.java) { api.balance("t") }.kind) }
  ```
- [ ] **Step 2: Run the tests to confirm they fail.**

  Run: `./gradlew :app:testDebugUnitTest --tests '*StudentApiTest*'`. Expected: compilation FAIL.
- [ ] **Step 3: Implement `StudentApi`, `materialLabel` and `displayTime`.**
  - Read the history array from `data`, and `hasMore` from `!isNull("next_page_url")`.
  - A JSON parse failure on a 2xx response becomes `SERVER`. A 204 needs no body.
  - A 403 or 404 on `login`, `changePassword` or `logout` becomes `SERVER`.
  - A network `IOException` propagates as is, so the UI shows "No se pudo contactar al servidor".
- [ ] **Step 4: Run the tests to confirm they pass.** Same command. Expected: PASS.
- [ ] **Step 5: Commit.** `git commit -m "feat(app): cliente de la API del estudiante (token, contraseña, saldo, historial)"`

### Task 8: Student screen

**Model:** sonnet

**Files:**
- Create: `app/app/src/main/java/co/ecostation/app/StudentActivity.kt` and `res/layout/activity_student.xml` (plain).
- Modify: `MainActivity.kt` (a "Mis puntos" button), `AndroidManifest.xml`, `strings.xml` and `app/README.md` (a §Vista del estudiante section, including the API contract table from this plan).

**Interfaces:**
- Consumes:
  - `StudentApi`, `ApiException.Kind`, `LoginResult`, `Balance`, `HistoryPage`, `materialLabel` and `displayTime` (Task 7);
  - `AppSettings.studentToken` and `serverAddress` (Task 4);
  - `EcoEndpoint.locate` and `BackendDiscovery` (Task 3).

- [ ] **Step 1: Layout.** One `LinearLayout` with three `View.GONE`-toggled groups:
  - **Login:** email, password, an "Entrar" button and an error `TextView`.
  - **Change password:** a note ("Debes cambiar tu contraseña para continuar"), fields "Contraseña actual", "Contraseña nueva" and "Repite la contraseña nueva" (all `inputType` password), a "Cambiar contraseña" button and an error `TextView`.
  - **Account:** name and class, "Saldo: N puntos", "Puesto: N" (hidden when `rank` is null), a "Cerrar sesión" button, and a `ListView` with an `ArrayAdapter<String>` of rows `"23/10 09:15 · Plástico · +10"` (a row with 0 points reads `"… · Otro · sin puntos"`), or "Aún no tienes depósitos" when the list is empty, and a "Ver más" button shown only while `hasMore` is true.
- [ ] **Step 2: Behavior.** All network work runs on one `Thread`, with `runOnUiThread` for results (no coroutines, no AndroidX).
  - **On `onStart`:** with no token, show login. With a token, locate the endpoint and call `balance` and `history(page 1)`, then render the account. A `PASSWORD_CHANGE_REQUIRED` error shows the change-password group instead.
  - **"Entrar":** call `login` and store the token **immediately** in `AppSettings.studentToken`, whatever `mustChangePassword` says. If `mustChangePassword` is true, show the change-password group. Otherwise load the account.
  - **"Cambiar contraseña":** refuse locally, with "Las contraseñas no coinciden", when the two new passwords differ. Otherwise call `changePassword` and, on success, clear the three fields and load the account. A `VALIDATION` error shows the server's message. No password is ever stored.
  - **"Ver más":** calls `history(token, nextPage)` and appends the rows.
  - **Error mapping** (all strings in `strings.xml`):

    | Condition | Message |
    |---|---|
    | `UNAUTHORIZED` | Set `studentToken = null`, show login with "Tu sesión expiró. Inicia sesión de nuevo." |
    | `BAD_CREDENTIALS` | "Correo o contraseña incorrectos, o la cuenta no es de estudiante" |
    | `VALIDATION` | the server's Spanish message |
    | `NOT_LINKED` | "Tu cuenta no está vinculada a un estudiante. Avisa a tu docente." |
    | `THROTTLED` | "Demasiados intentos, espera un minuto" |
    | Locate returns null | "No se encontró el servidor. Revisa la red o pon la dirección en Ajustes." |
    | `IOException` / `SERVER` | "No se pudo contactar al servidor" |

  - **"Entrar"** disables itself while a request is in flight. The password field is cleared after any attempt.
  - **"Cerrar sesión"** clears the token locally first, then calls `logout` on the background thread and ignores any failure, so a dead network never traps the user in a session.
- [ ] **Step 3: Verify.**

  Run: `./gradlew :app:testDebugUnitTest :app:assembleDebug`. Expected: PASS.

  Manual, against the web backend once its plan ships:
  1. A good login shows the name, balance, rank and history.
  2. Killing and relaunching the app keeps the user signed in.
  3. Revoking the token server-side (`php artisan tinker` → `$user->tokens()->delete()`) and reopening shows "Tu sesión expiró".
  4. A wrong password shows the right message.
  5. With airplane mode on, the app shows the server-not-found message and does not crash.
  6. With `must_change_password` set to true on the account (`User::where('email', …)->update(['must_change_password' => true])`), login shows the change form. Killing the app and reopening it shows the change form again. A successful change loads the account.
  7. "Cerrar sesión" makes the token stop working (`tokens()->count()` is 0).
- [ ] **Step 4: Commit.** `git commit -m "feat(app): vista del estudiante con saldo, puesto, historial y cambio de contraseña"`

### Task 9: UI skin — blocked on Penpot approval

**Model:** sonnet

**Blocked until** the user approves the mobile screens in Penpot and their exports exist under `design/` (mobile screens, logo, brand sheet; the exact folder comes from the design plan's export step). Do not start before then.

**Files:**
- Modify: the `activity_main.xml`, `activity_settings.xml` and `activity_student.xml` layouts, plus `themes.xml` and `colors.xml`.
- Create: drawables and launcher icons from the `design/` exports, a port of `CredentialCardView.kt` (ISO ID-1 ratio) if the design keeps a card face, and self-hosted fonts in `res/font/` if the design names any.
- Create: `app/tools/gen_brand_assets.py`, adapted from Pulse's, which reads `design/` instead of `B2B-Logo-Suite/`.

- [ ] **Step 1: Read the approved exports** and list every screen state they define. Each state must map to an existing logic state from Tasks 2, 6 and 8. If a design state has no logic behind it, raise it with the user rather than inventing behavior.
- [ ] **Step 2: Apply the skin.**
  - Change views, styles and assets only; logic classes and string meanings stay unchanged.
  - Status colors for errors (`NFC APAGADO` / `NO COMPATIBLE`) stay visually distinct from brand colors.
- [ ] **Step 3: Verify.**

  Run: `./gradlew :app:testDebugUnitTest :app:assembleDebug`. Expected: PASS.

  On a device, take screenshots of each state and compare them side by side with the `design/` exports. Re-run Task 2's bench tap and Task 6's speaker check to confirm nothing regressed.
- [ ] **Step 4: Commit.** `git commit -m "feat(app): piel visual según diseños aprobados en Penpot"`
