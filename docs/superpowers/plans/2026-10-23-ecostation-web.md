# EcoStation web/ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Laravel 13 app in `web/` that ports the recycling slice of Pulse
(B2B-Core): it keeps the device API and HMAC signing byte-compatible, and
adds editable points, teacher class scoping, photo retention, a class
leaderboard, a Sanctum student API and a station realtime role.

**Architecture:** One tap creates one `events` row. Each classification
creates one `recycling_deposits` row plus one append-only `points_ledger`
row. Every statistic is derived from these tables. Stations authenticate
with Pulse-HMAC, keyed by `readers.api_key`. Browsers use session auth, and
the Android app uses Sanctum tokens. A hand-rolled WebSocket server polls
the database and pushes `tap`, `recycling`, `feedback` and `pairing`
frames. Blade views stay deliberately bare until the Penpot designs are
approved (see Task 20).

**Tech Stack:**
- PHP ^8.3, Laravel ^13, laravel/sanctum ^4 and dompdf/dompdf ^3.
- PHPUnit 12 (`#[Test]` attribute style) and Pint.
- SQLite.
- GitHub Actions, including gitleaks.

**Spec:** `docs/superpowers/specs/2026-10-23-ecostation-design.md`

**Source to port:** `/home/jperez/Documents/B2B/B2B-Core`, called `$PULSE`
below. Every "port" step means:
1. Copy the named file.
2. Remove the multi-school layer: `BelongsToSchool`,
   `InheritsSchoolFromParent`, `CurrentSchool`, `OwnedByCurrentSchool`, any
   `school_id`, and `schoolOwnershipPath()`.
3. Remove the meal, PAE, kitchen, reward, attendance and NL-query branches.
4. Shorten TASK-/ADR- comment archaeology to the one-line *why*.
5. Translate user-facing strings to Spanish keys in `lang/es`.

## Global Constraints

- The app lives in `web/` of the monorepo `/home/jperez/Documents/EcoStation`.
  Every path below is relative to `web/` unless it starts with `.github/`
  or `docs/`.
- SQLite in dev, prod and tests (`DB_CONNECTION=sqlite`). There are no
  MariaDB paths. Migrations are fresh and consolidated, with no
  `->after()` and no alter-migrations.
- `APP_LOCALE=es`, `APP_FALLBACK_LOCALE=es`, and every user-facing string is
  Spanish. There is no `lang/en`, no `.es.md` parity and no
  `Accept-Language` switching (`SetApiLocale`/`SetWebLocale` are not ported).
- `APP_TIMEZONE=America/Bogota`.
- `CACHE_STORE=database`. The HMAC and HCE single-use nonces live in the
  cache, so the `array` store is allowed only in `phpunit.xml`.
- **Wire contract frozen.** This covers:
  - the paths `POST /api/v1/events/tap`, `/api/v1/recycling/classify`,
    `/api/v1/recycling/capture`,
    `/api/v1/recycling/captures/{id}/associate` and `/api/v1/admin/cards/pair`;
  - the request rules and the JSON response keys and status codes, exactly
    as in `$PULSE`;
  - the `Authorization: Pulse-HMAC <kid>:<nonce>:<sig>` scheme and its
    canonical strings;
  - the HCE label `"pulse-hce-key-wrap/v1\n"`;
  - the `/login` form (the speaker app scrapes `name="_token"`),
    `GET /realtime/token` → `{token, expires_at, url}` and
    `GET /manifest.webmanifest` (a 200 body containing `"start_url"`, which
    the app's subnet-sweep fallback probes);
  - the Android student API of Task 12, exactly as written there;
  - the speaker result cue: **exactly one** `recycling` frame of type
    `points_awarded` per new classification, carrying `payload.points`
    (int, `0` for an `other` result or any material whose admin rate is 0). No other frame type carries a
    cue, and a duplicate classify emits none (Task 9).

  **Never rename the strings `Pulse-HMAC` or `pulse-hce-key-wrap/v1`.**
- Points defaults: plastic 10, metal 15, glass 8, paper 5, other 0. The
  admin can edit them, and edits are never retroactive.
- Photo retention defaults to 30 days and is an admin setting. A purge
  deletes the file only. The deposit, event and points stay.
- mDNS service `_ecostation._tcp`, TXT `version=1 protocol=1 api=/api`,
  web port 8000, WebSocket port 8081.
- Roles: `admin`, `teacher`, `student`, `station`. There is no `kitchen`.
- Classifier drivers: `stub` (the default) and `deepseek` only.
- DeepSeek request:
  - `POST https://api.deepseek.com/chat/completions`, model `deepseek-flash`;
  - `image_url` with `detail: high`, `response_format: {type: json_object}`;
  - `thinking: {type: disabled}`, `temperature 0`, `max_tokens 200`.
- No real secret from any Pulse repo is ever copied. Station keys are
  generated with `Str::random(32)`.
- Cut entirely: schools/`school_id`, rewards/redemptions, `pae_*`, meal
  settings, `MealServingService`, `AttendanceService`, NL-query, parent
  views, `LocalModelClassifier`, the roster channel, `ReaderType`,
  `EventType`, and reader modes.

## Review Focus

1. **Teacher URL tampering.** If a teacher opens
   `/teacher/students/{id}`, `/deposits/{id}/photo` or an export for a
   student outside their classes, they get a 404 and no data. Tests:
   Task 13 and Task 17.
2. **Changing points mid-term.** After the admin raises plastic from 10 to
   20, existing deposits and ledger rows keep 10. The next deposit gets
   20. Test: Task 9.
3. **Photo already purged or file missing.** The photo route answers 404,
   not 500. Running the purge twice is a no-op, and the balance is
   unchanged after a purge. Test: Task 13.
4. **Speaker login after the UI skin.** The `/login` HTML keeps a hidden
   `name="_token"` input, and a `station` user with
   `must_change_password=false` gets 200 JSON from `/realtime/token`.
   Tests: Task 11 and Task 14. Task 20 must keep them green.
5. **Orphaned or wrong-role token.** A student account whose `student_id`
   is null, or a teacher or station token, calling `/api/v1/me/*` gets
   404 or 403. It never gets another student's data. Test: Task 12.
6. **A zero-point result.** The award rule is Pulse's, unchanged: points
   come from the material class only (`other` is 0 by default), and
   `is_bottle`/`is_recyclable` are recorded but never change the amount.
   A 0-point result writes no ledger row, yet still emits one
   `points_awarded` frame with `points: 0`, so the speaker can play its
   "not recyclable" cue. A duplicate classify
   emits no frame. Tests: Task 9 and Task 14.

---

### Task 1: Scaffold `web/`, slim `./run`, CI

**Model:** sonnet

**Files:**
- Create: `web/` from `composer create-project laravel/laravel:^13.0 web`
- Modify: `.env.example`, `phpunit.xml`, `config/app.php`, `bootstrap/app.php`
- Create: `run`, `.github/workflows/ci.yml`, `.gitignore` (repo root), `.gitleaks.toml` (repo root, empty allowlist placeholder only if gitleaks needs one; otherwise skip)
- Test: `tests/Feature/BootTest.php`

**Interfaces:**
- Produces:
  - `./run setup|serve|test|ci`. `serve` is a stub that runs only
    `php artisan serve` until Task 15.
  - Sanctum installed via `php artisan install:api`, giving
    `routes/api.php` and `personal_access_tokens`.
  - `dompdf/dompdf` required.

- [ ] **Step 1: Create the project and install dependencies.** Run
  `composer create-project laravel/laravel:^13.0 web`. Then, in `web/`, run
  `php artisan install:api --no-interaction` and
  `composer require dompdf/dompdf:^3.0`.
- [ ] **Step 2: Write the failing test** `tests/Feature/BootTest.php`:

```php
#[Test] public function the_app_runs_in_spanish_on_bogota_time_with_sqlite(): void
{
    $this->assertSame('es', app()->getLocale());
    $this->assertSame('America/Bogota', config('app.timezone'));
    $this->assertSame('sqlite', config('database.default'));
    $this->assertSame('EcoStation', config('app.name'));
}
```

- [ ] **Step 3: Run it and confirm it fails.** Run
  `php artisan test --filter=BootTest`. Expected: FAIL on the locale or
  name assertion.
- [ ] **Step 4: Configure.**
  - In `.env.example`, set:
    - `APP_NAME=EcoStation`, `APP_LOCALE=es`, `APP_FALLBACK_LOCALE=es`,
      `APP_TIMEZONE=America/Bogota`;
    - `DB_CONNECTION=sqlite`, `CACHE_STORE=database`,
      `SESSION_DRIVER=database`, `QUEUE_CONNECTION=sync`;
    - `RECYCLING_CLASSIFIER_DRIVER=stub` and `DEEPSEEK_API_KEY=` (empty);
    - `REALTIME_PORT=8081`.
  - In `config/app.php`, set `'timezone' => env('APP_TIMEZONE', 'America/Bogota')`.
  - In `phpunit.xml`, set `DB_DATABASE=:memory:`, `CACHE_STORE=array` and
    `APP_LOCALE=es`, plus a throwaway `APP_KEY=base64:` test value.
  - Run `php artisan lang:publish` and delete `lang/en`. Translate
    `lang/es/{auth,passwords,pagination,validation}.php`, starting from
    `$PULSE/lang/es/*.php` where those files exist there.
- [ ] **Step 5: Write `run`.** It is a bash dispatcher, `set -euo pipefail`,
  with four subcommands:
  - `setup`: `composer install`, `cp -n .env.example .env`,
    `php artisan key:generate`, `touch database/database.sqlite`,
    `php artisan migrate --seed --force`.
  - `serve`: `php artisan serve --host=0.0.0.0 --port=8000`.
  - `test`: `php artisan test "$@"`.
  - `ci`: `vendor/bin/pint --test && php artisan test`.

  Unknown subcommands print the usage text in Spanish and exit 2.
- [ ] **Step 6: Write `.github/workflows/ci.yml`.** It has two jobs:
  - `web`: `shivammathur/setup-php` with php 8.3 and pdo_sqlite,
    `working-directory: web`, `composer install`, `cp .env.example .env`,
    `php artisan key:generate`, `vendor/bin/pint --test`, `php artisan test`.
  - `gitleaks`: `gitleaks/gitleaks-action@v2` over the whole repo with
    `fetch-depth: 0`.

  The firmware and app plans add their own jobs to this same file.
- [ ] **Step 7: Write the root `.gitignore`.** Ignore `web/.env`,
  `web/database/*.sqlite`, `web/storage/app/private/*`, `**/secrets*.h`,
  `*.jks` and `*.keystore`.
- [ ] **Step 8: Run the tests and Pint.** Run `./run ci`. Expected: Pint
  clean and BootTest PASS. Delete the skeleton's `ExampleTest` files.
- [ ] **Step 9: Commit**:
  `git add web .github .gitignore && git commit -m "feat(web): scaffold Laravel 13 app, ./run, CI"`

---

### Task 2: Consolidated schema, models, demo seeder, test helpers

**Model:** sonnet

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`.
  Add `role` (string, default `teacher`) and `must_change_password` (bool,
  default false). Drop `email_verified_at` and `password_reset_tokens`.
  `users.student_id` is added by the ecostation migration right after
  `students` is created, with
  `Schema::table('users', fn ($t) => $t->foreignId('student_id')->nullable()->constrained()->nullOnDelete())`.
- Create: `database/migrations/2026_10_23_000001_create_ecostation_tables.php`.
  This is one migration that creates the tables below in FK order.
- Create: `app/Models/{SchoolClass,Student,Card,Reader,PresenceEvent,RecyclingDeposit,PointsLedger,PendingPairing,PendingCapture,RecyclingUpdate,HceCredentialKey,Setting}.php`
- Create: `app/Enums/{UserRole,CardKind,CardStatus,MaterialClass,PendingCaptureState}.php`
- Modify: `app/Models/User.php`
- Create: `database/seeders/DemoSeeder.php`. Modify `DatabaseSeeder.php` to
  call it.
- Modify: `tests/TestCase.php`
- Test: `tests/Feature/SchemaTest.php`

**Interfaces:**
- Tables. All of them have `id` and `timestamps` unless noted.
  - `classes`: `name` unique.
  - `teacher_class`: `user_id` FK cascade and `class_id` FK cascade, with
    a composite primary key and no id or timestamps.
  - `students`: `name`, plus `class_id` nullable with nullOnDelete. Unique
    on (`name`, `class_id`).
  - `cards`: `credential_uid` unique, `kind` (default `physical`),
    `student_id` FK cascade, `status` (default `active`).
  - `readers`: `label`, plus `api_key` unique. No `type` and no
    `active_event_type`.
  - `events`:
    - `card_id` FK cascade and `reader_id` FK cascade;
    - `occurred_at` and `metadata` (json, nullable);
    - indexes on (`card_id`, `occurred_at`) and on (`occurred_at`);
    - no `type`, `served` or `reason`.
  - `recycling_deposits`:
    - `event_id` unique FK cascade and `image_path` (nullable);
    - `material_class`, `confidence` (float) and `points_awarded` (int);
    - `is_bottle` and `is_recyclable` (bool, default false).
  - `points_ledger`: `student_id` FK cascade, `delta` (int), `reason`,
    `event_id` nullable with nullOnDelete. Index on (`student_id`,
    `created_at`).
  - `pending_pairings`:
    - `student_id` FK cascade;
    - `reader_id` nullable with nullOnDelete, and `card_id` nullable with
      nullOnDelete;
    - `expires_at` and `consumed_at` (nullable);
    - `last_rejected_uid`, `last_rejected_reason` and `last_rejected_at`,
      all nullable;
    - index on (`consumed_at`, `expires_at`).
  - `pending_captures`:
    - `reader_id` FK cascade and `image_path`;
    - `event_id` nullable with nullOnDelete, and `card_id` nullable with
      nullOnDelete;
    - `state` (default `awaiting_card`) and `expires_at`;
    - index on (`reader_id`, `state`, `expires_at`).
  - `recycling_updates`: `type`, plus `payload` (json).
  - `tap_feedback`: `reader_id` and `event_id` (both nullable, no FK),
    `cue` (string 16) and `reason` (string 32).
  - `hce_credential_keys`: `card_id` unique FK cascade, `secret` (text),
    `fingerprint` (string 16), `provisioned_by_reader_id` (nullable,
    nullOnDelete) and `provisioned_at`.
  - `settings`: `key` unique, plus `value` (json, nullable).
- Model contracts:
  - Port from `$PULSE/app/Models`, minus school, meal and reward code.
  - `PresenceEvent` has `public const TYPE = 'RECYCLING_DEPOSIT';`. It is
    the value the API keeps answering in `event_type`.
  - `PresenceEvent::student()` and `PresenceEvent::deposit(): HasOne` are
    kept.
  - `Student::firstName(): string`, `Student::cards()`,
    `Student::pointsLedger()`, `Student::account(): HasOne` and
    `Student::schoolClass(): BelongsTo` are kept.
  - `SchoolClass::teachers(): BelongsToMany` uses `teacher_class` with
    pivot keys `class_id`/`user_id`. `SchoolClass::students(): HasMany`.
  - User changes:
    - `User::classes(): BelongsToMany` uses `teacher_class`;
    - `isAdmin()`, `isTeacher()`, `isStudent()` and `isStation()` are
      added, and `isKitchen()` is removed;
    - `student(): BelongsTo` is kept, and `HasApiTokens` is kept.
  - `UserRole` cases: `Admin='admin'`, `Teacher='teacher'`,
    `Student='student'`, `Station='station'`.
  - `MaterialClass` keeps its cases. `points()` is rewritten in Task 3, so
    leave it reading `config('ecostation.points.'.$this->value)` for now.
  - `Reader` has `$hidden = ['api_key']`. It has no `isRecycling()`: every
    reader is a station.
- Demo data (`DemoSeeder`):
  - Staff accounts:
    - `admin@ecostation.test` with role admin;
    - `docente@ecostation.test`, a teacher assigned to class `5° A` only;
    - `estacion@ecostation.test` with role station.
  - Classes: `5° A` and `5° B`.
  - Students in `5° A`: Maria González, Carlos Pérez and Ana Martínez.
  - Students in `5° B`: Diego López and Lucía Fernández.
  - Each student gets one active physical card with uid
    `DEMO-<n>` and a student account `<firstname-ascii-lowercase>@ecostation.test`.
  - One reader, `Estación Demo`, with `api_key = Str::random(32)`.
  - Every password is `password` with `must_change_password=false`.
  - The seeder prints the reader key once with `$this->command?->info`.
- `TestCase` helpers:
  - `seedDemo(): array{admin: User, teacher: User, station: User, students: Collection}`;
  - `admin(): User` and `teacher(): User` (the demo accounts);
  - `reader(): Reader` (the demo station) and `readerToken(): string`;
  - `cardOf(string $name): Card` and `cardUidFor(string $name): string`;
  - `recyclingTapFor(string $name): PresenceEvent`, which creates an event
    for that student's card at `reader()` with `occurred_at=now()`;
  - `setUp()` calls `SettingsService::flushCache()` once Task 3 exists.
    Add that line in Task 3.

- [ ] **Step 1: Write the failing test** `tests/Feature/SchemaTest.php`:

```php
#[Test] public function the_schema_has_the_recycling_tables_and_none_of_the_pulse_baggage(): void
{
    foreach (['users','classes','teacher_class','students','cards','readers','events','recycling_deposits',
              'points_ledger','pending_pairings','pending_captures','recycling_updates','tap_feedback',
              'hce_credential_keys','settings','personal_access_tokens'] as $t) {
        $this->assertTrue(Schema::hasTable($t), $t);
    }
    foreach (['schools','rewards','reward_redemptions','roster_updates'] as $t) {
        $this->assertFalse(Schema::hasTable($t), $t);
    }
    foreach (['users','students','events','readers'] as $t) {
        $this->assertFalse(Schema::hasColumn($t, 'school_id'), $t);
    }
    $this->assertFalse(Schema::hasColumn('events', 'served'));
    $this->assertFalse(Schema::hasColumn('readers', 'type'));
    $this->assertEmpty(array_filter(Schema::getColumnListing('students'), fn ($c) => str_starts_with($c, 'pae')));
}

#[Test] public function the_demo_seed_links_the_teacher_to_one_class_and_every_student_to_a_card_and_account(): void
{
    $demo = $this->seedDemo();
    $this->assertSame(['5° A'], $demo['teacher']->classes->pluck('name')->all());
    $this->assertSame('station', $demo['station']->role);
    $this->assertCount(5, $demo['students']);
    foreach ($demo['students'] as $s) {
        $this->assertNotNull($s->cards()->first());
        $this->assertNotNull($s->account);
    }
    $this->assertSame('RECYCLING_DEPOSIT', PresenceEvent::TYPE);
}
```

- [ ] **Step 2: Run it and confirm it fails.** Run
  `php artisan test --filter=SchemaTest`. Expected: FAIL because the
  tables are missing.
- [ ] **Step 3: Implement** the migrations, models, enums, seeder and
  helpers listed above.
- [ ] **Step 4: Run the tests and confirm they pass.** Run
  `php artisan test`. Expected: PASS.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): consolidated recycling schema, models, demo seed"`

---

### Task 3: Runtime settings (points per material, photo retention, pairing, accounts)

**Model:** sonnet

**Files:**
- Create: `config/ecostation.php`
- Create: `app/Services/SettingsService.php`, ported from
  `$PULSE/app/Services/SettingsService.php`.
- Create: `app/Support/helpers.php` with `settings(): SettingsService`, and
  add it to composer autoload `files`.
- Modify: `app/Enums/MaterialClass.php` and `tests/TestCase.php`
- Test: `tests/Unit/SettingsServiceTest.php`

**Interfaces:**
- `config/ecostation.php` keys:
  - `points`:
    `['plastic'=>10,'metal'=>15,'glass'=>8,'paper'=>5,'other'=>0]`;
  - `photos.retention_days`: 30;
  - `pairing_window_seconds`: `env('PAIRING_WINDOW_SECONDS', 45)`;
  - `student_email_domain`: `'ecostation.test'`;
  - `student_initial_password`: `'password'`;
  - `capture.ttl_seconds`: `env('RECYCLING_CAPTURE_TTL', 300)`;
  - `capture.classify_window_seconds`: `env('RECYCLING_CLASSIFY_WINDOW', 600)`;
  - `leaderboard.top`: 10;
  - `device_auth.allow_legacy_bearer`:
    `env('DEVICE_AUTH_ALLOW_LEGACY_BEARER', true)`;
  - `classifier.driver`: `env('RECYCLING_CLASSIFIER_DRIVER','stub')`;
  - `classifier.deepseek.{api_key,model,timeout}`, which default to
    `env('DEEPSEEK_API_KEY')`, `'deepseek-flash'` and `15`.
- Registry (key → [config path, rule]):
  - `points.plastic|metal|glass|paper|other` → `ecostation.points.*`,
    `required|integer|between:0,1000`;
  - `photos.retention_days` → `required|integer|between:1,365`;
  - `pairing.window_seconds` → `required|integer|between:10,600`;
  - `accounts.student_email_domain` and `accounts.student_initial_password`
    keep their `$PULSE` rules.
- `SettingsService` API:
  - `get(string $key): mixed`, `all(): array` and
    `setMany(array $values): array`. `setMany` throws
    `ValidationException`, casts integer keys to int, and drops the
    meal-window overlap check.
  - `static flushCache(): void` and `static keys(): array`.
  - New typed accessors: `pointsFor(MaterialClass $m): int`,
    `photoRetentionDays(): int` and `pairingWindowSeconds(): int`, plus
    the two account accessors.
- `MaterialClass::points(): int` returns `settings()->pointsFor($this)`.
  This is the single source for award amounts.

- [ ] **Step 1: Write the failing tests** `tests/Unit/SettingsServiceTest.php`
  (`RefreshDatabase`):

```php
#[Test] public function defaults_are_the_spec_point_table_and_thirty_day_retention(): void
{
    $this->assertSame(10, MaterialClass::Plastic->points());
    $this->assertSame(15, MaterialClass::Metal->points());
    $this->assertSame(8, MaterialClass::Glass->points());
    $this->assertSame(5, MaterialClass::Paper->points());
    $this->assertSame(0, MaterialClass::Other->points());
    $this->assertSame(30, settings()->photoRetentionDays());
}

#[Test] public function an_admin_override_is_live_for_the_next_read(): void
{
    settings()->setMany(['points.plastic' => '20', 'photos.retention_days' => 7]);
    $this->assertSame(20, MaterialClass::Plastic->points());
    $this->assertSame(7, settings()->photoRetentionDays());
}

#[Test] public function invalid_values_and_unknown_keys_are_rejected(): void
{
    foreach ([['points.metal' => -1], ['points.metal' => 'abc'], ['photos.retention_days' => 0], ['pae.lunch_start' => '11:00']] as $bad) {
        try { settings()->setMany($bad); $this->fail(json_encode($bad)); } catch (ValidationException) {}
    }
    $this->assertSame(15, MaterialClass::Metal->points());
}
```

- [ ] **Step 2: Run them and confirm they fail.** Run
  `php artisan test --filter=SettingsServiceTest`. Expected: FAIL because
  the class is missing.
- [ ] **Step 3: Implement** the files listed above.
- [ ] **Step 4: Run the tests and confirm they pass.** Run
  `php artisan test`. Expected: PASS.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): admin-editable points and retention settings"`

---

### Task 4: Device authentication (Pulse-HMAC + bench Bearer)

**Model:** opus

**Files:**
- Create: `app/Services/DeviceAuth/DeviceRequestSigner.php`. Port it
  verbatim except for the namespace. Keep the cache key prefix
  `pulse-hmac-nonce:`.
- Create: `app/Http/Middleware/ResolveReaderToken.php`. Port it, reading
  `config('ecostation.device_auth.allow_legacy_bearer')`.
- Modify: `bootstrap/app.php` to add the alias `reader.auth`.
- Create: `lang/es/api.php`. Start it with `missing_bearer_token`,
  `invalid_bearer_token` and `invalid_device_signature`, using the Spanish
  text from `$PULSE/lang/es/api.php`.
- Test: `tests/Feature/Api/DeviceHmacAuthTest.php`, ported.

**Interfaces:**
- Produces:
  - `DeviceRequestSigner::verify(Request $request, string $header): array{ok: true, reader: Reader}|array{ok: false, reason: string}`;
  - the static helpers `sign`, `authorizationHeader`, `fingerprint`,
    `canonical`, `multipartCanonical` and `canonicalBody`, with the same
    signatures as `$PULSE`;
  - the middleware sets `$request->attributes->get('reader')`.
- Consumes: `Reader` from Task 2.

- [ ] **Step 1: Port the test.** Port `$PULSE/tests/Feature/Api/DeviceHmacAuthTest.php`:
  - Keep every test whose assertions pin the wire format, including
    `the_wire_format_is_pinned_for_firmware_interop` and
    `the_multipart_canonical_format_is_pinned_for_firmware_interop`, with
    their exact expected hex strings.
  - Defer the three tests that hit classify or capture
    (`a_signed_device_classifies_with_the_multipart_canonical`,
    `a_swapped_image_invalidates_the_multipart_signature`,
    `a_signed_device_captures_bottle_first_with_the_multipart_canonical`).
    Mark them `$this->markTestSkipped('Task 9/10')` now, and un-skip them
    in Tasks 9 and 10.
  - Use `seedDemo()`, `reader()` and `readerToken()`.
- [ ] **Step 2: Run it and confirm it fails.** Run
  `php artisan test --filter=DeviceHmacAuthTest`. Expected: FAIL with
  route 404s. Those tests need Task 6, so for now add a throwaway probe
  route `Route::middleware('reader.auth')->post('/api/v1/_probe', fn () => ['ok'=>true])`
  in the test via `Route::` inside `setUp()`, and point the non-tap tests
  at it. Restore the tap-path tests in Task 6.
- [ ] **Step 3: Implement** the signer and the middleware.
- [ ] **Step 4: Run the tests and confirm they pass.** Run
  `php artisan test --filter=DeviceHmacAuthTest`. Expected: PASS, apart
  from the skipped tests.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): Pulse-HMAC device auth (wire-compatible)"`

---

### Task 5: HCE per-credential authentication

**Model:** opus

**Files:**
- Create: `app/Services/Hce/HceCredentialAuth.php`. Port it verbatim. The
  constants stay unchanged, including `WRAP_LABEL = "pulse-hce-key-wrap/v1\n"`.
- Create: `tests/Support/FakeHcePhone.php`, ported verbatim.
- Test: `tests/Unit/HceCredentialAuthTest.php`, ported. It includes the
  shared spec test vector from `HCE_PROTOCOL.md` v1.1 exactly as `$PULSE`
  pins it.

**Interfaces:**
- Produces:
  - `verifyTap(Card $card, ?string $nonceHex, ?string $macHex): ?string`,
    which returns null when the tap is OK and a reason string otherwise;
  - `acceptProvisioning(Reader $reader, string $credentialUid, array $input): array{ok: bool, key?: string}`;
  - `storeKey(Card $card, string $key, Reader $reader): HceCredentialKey`;
  - the statics `challengeMac`, `wrapPad`, `wrapKey` and `fingerprint`;
  - the rule constants `NONCE_RULE`, `MAC_RULE` and `WRAP_NONCE_RULE`.

- [ ] **Step 1: Port the unit test** unchanged, apart from the namespace
  and seeding.
- [ ] **Step 2: Run it and confirm it fails.** Run
  `php artisan test --filter=HceCredentialAuthTest`. Expected: FAIL
  because the class is missing.
- [ ] **Step 3: Port the service** and `FakeHcePhone`.
- [ ] **Step 4: Run it and confirm it passes.** Expected: PASS.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): per-credential HCE challenge auth (protocol v1.1 unchanged)"`

---

### Task 6: Tap endpoint and feedback log

**Model:** opus

**Files:**
- Create: `app/Services/TapService.php`. Port it with the meal and
  classroom branches removed.
- Create: `app/Services/Realtime/TapFeedback.php`. Port it without
  `school_id`, and with `after()` rows of
  `{id, cue, reason, event_id, reader_id}`.
- Create: `app/Http/Requests/TapEventRequest.php` and
  `app/Http/Controllers/Api/V1/TapEventController.php`, both ported.
- Modify: `routes/api.php` to add the `v1` prefix group with `reader.auth`
  and `POST /events/tap` (name `api.v1.events.tap`).
- Modify: `lang/es/api.php` to add `card_not_recognized`, `card_not_active`
  and `hce_credential_unverified`.
- Test: `tests/Feature/Api/TapEventTest.php` and
  `tests/Feature/Api/TapFeedbackTest.php`, both ported.

**Interfaces:**
- Produces:
  - `TapService::registerTap(Reader $reader, string $credentialUid, ?string $clientTimestamp = null, ?string $hceNonce = null, ?string $hceMac = null): array{ok: true, event: PresenceEvent}|array{ok: false, reason: 'not_found'|'inactive'|'hce_auth_failed', message: string}`.
    Every tap writes an event; there is no same-day duplicate rule.
  - `TapFeedback::record(Reader $reader, string $cue, string $reason, ?int $eventId = null): void`
    is called for every rejection, never for an accepted tap.
  - The tap response keeps the `$PULSE` keys:
    - `status`, `event_id` and `student_first_name`;
    - `event_type`, which is `PresenceEvent::TYPE`;
    - `duplicate`, which is always `false`;
    - `next_step`, which is always `'awaiting_classification'`.
  - Statuses: 404 for not_found and inactive, 403 for hce_auth_failed,
    422 for validation errors.

- [ ] **Step 1: Port `TapEventTest`.**
  - Drop `the_tap_uses_the_readers_current_active_mode`.
  - Rename `a_recycling_tap_signals_awaiting_classification` to assert
    `next_step === 'awaiting_classification'` and `event_type === 'RECYCLING_DEPOSIT'`
    on the demo station.
- [ ] **Step 2: Port `TapFeedbackTest`.**
  - Drop `a_first_tap_leaves_no_cue_and_a_repeat_tap_leaves_an_accepted_one`.
  - Keep the rejected-cue and broken-log tests.
  - Rename `recycling_taps_always_write_rows_and_never_log_a_cue` to
    `accepted_taps_write_rows_and_never_log_a_cue`, and assert that two
    taps by the same card write two events.
- [ ] **Step 3: Un-skip the tap-path tests in `DeviceHmacAuthTest`.**
  Remove the probe route.
- [ ] **Step 4: Run the tests and confirm they fail.** Run
  `php artisan test --filter='TapEventTest|TapFeedbackTest|DeviceHmacAuthTest'`.
  Expected: FAIL.
- [ ] **Step 5: Implement** the files listed above.
- [ ] **Step 6: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 7: Commit**:
  `git commit -m "feat(web): /api/v1/events/tap with HCE proof and feedback cues"`

---

### Task 7: Pairing service and device pair endpoint

**Model:** opus

**Files:**
- Create: `app/Services/PairingService.php`. Port it. The window comes from
  `settings()->pairingWindowSeconds()`.
- Create: `app/Http/Requests/PairCardRequest.php` and
  `app/Http/Controllers/Api/V1/CardPairingController.php`, both ported.
- Modify: `routes/api.php` to add `POST /admin/cards/pair` in the
  `reader.auth` group (name `api.v1.cards.pair`).
- Modify: `lang/es/api.php` to add `pairing_no_active_session` and
  `pairing_card_already_paired`.
- Test: `tests/Feature/Api/CardPairingTest.php` and
  `tests/Feature/Api/HceCredentialTest.php`, both ported.

**Interfaces:**
- Produces:
  - `arm(Student $student): PendingPairing`;
  - `activeSession(): ?PendingPairing`;
  - `recentCompletions(int $limit = 8): Collection`;
  - `statusPayload(): array`, with the same shape as `$PULSE`;
  - `pair(Reader $reader, string $credentialUid, string $kind = 'physical', array $hce = []): array`.
    It has the same result union as `$PULSE`: `no_session`,
    `already_paired` or `hce_proof_invalid`.

- [ ] **Step 1: Port `CardPairingTest`.**
  - Arm through `app(PairingService::class)->arm($student)`. The admin arm
    route arrives in Task 19.
  - Drop `arming_requires_the_admin_role`,
    `arming_works_with_a_personal_access_token` and
    `pairing_messages_localize_via_accept_language`. The admin-role check
    moves to Task 19.
  - Keep `concurrent_pair_taps_cannot_double_consume_one_session` and the
    other tests.
- [ ] **Step 2: Port `HceCredentialTest`.**
  - Keep the tests for: provisioning, no key material in responses,
    defaults to physical, bad proofs, wrong reader wrap, replay, wrong
    credential, rekey, never overwriting another student's key,
    physical-to-phone, and RF-UID independence.
  - In `meal_readers_and_capture_association_also_require_the_proof`, keep
    only the capture half and rename the test to
    `capture_association_also_requires_the_proof`. Skip it until Task 10.
  - Defer the revoke tests and the pairing-desk badge tests to Task 19:
    `a_revoked_phone_fails_even_with_a_valid_proof_and_its_key_is_destroyed`,
    `revocation_is_admin_only_and_school_scoped` (renamed to
    `revocation_is_admin_only`), `the_pairing_desk_offers_revoke_and_badges_revoked_credentials`,
    `the_pairing_desk_badges_phone_credentials` and
    `the_pairing_status_feed_reports_the_credential_kind`.
  - Drop `a_reader_of_another_school_cannot_provision_into_this_schools_window`.
- [ ] **Step 3: Run the tests and confirm they fail.** Run
  `php artisan test --filter='CardPairingTest|HceCredentialTest'`.
  Expected: FAIL.
- [ ] **Step 4: Implement** the files listed above.
- [ ] **Step 5: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 6: Commit**:
  `git commit -m "feat(web): arm-then-tap card/phone pairing endpoint"`

---

### Task 8: Material classifier (stub + DeepSeek with retry/402) and `llm-check`

**Model:** sonnet

**Files:**
- Create: `app/Contracts/MaterialClassifier.php`,
  `app/Services/Recycling/ClassificationException.php` and
  `app/Services/Recycling/Drivers/StubClassifier.php`, all ported.
- Create: `app/Services/Recycling/Drivers/DeepSeekClassifier.php`. Port it,
  then make the changes below.
- Create: `app/Services/Recycling/ClassifierFactory.php`. Port it with only
  the `stub` and `deepseek` arms, reading `config('ecostation.classifier.*')`.
- Modify: `app/Providers/AppServiceProvider.php` to add
  `$this->app->bind(MaterialClassifier::class, fn () => ClassifierFactory::make())`.
- Create: `app/Console/Commands/LlmCheckCommand.php` and
  `tests/Fixtures/bottle.jpg`. The fixture is a tiny real JPEG (< 20 KB)
  of a plastic bottle, or a generated 64×64 JPEG via GD if no CC0 image
  is at hand.
- Test: `tests/Unit/StubClassifierTest.php` and
  `tests/Unit/DeepSeekClassifierTest.php` (both ported and extended), plus
  `tests/Feature/Console/LlmCheckCommandTest.php`.

**Interfaces:**
- `MaterialClassifier::classify(string $imagePath): array{material_class: string, confidence: float, is_bottle: bool, is_recyclable: bool}`
  throws `ClassificationException`.
- `ClassificationException::driverUnavailable(string $driver, string $reason): self`.
- New: `ClassificationException::insufficientBalance(): self`. Its message
  is `"Classifier driver [deepseek] failed: saldo insuficiente en DeepSeek (HTTP 402) — recargue la cuenta"`.
- DeepSeek HTTP call:
  - `Http::timeout($this->timeout)->withToken($this->apiKey)->retry(2, 500, fn (Throwable $e) => $e instanceof RequestException && in_array($e->response->status(), [429, 500, 503], true), throw: false)->post(self::ENDPOINT, $payload)`.
    That is two attempts total, meaning one retry, 500 ms apart.
  - A final 402 raises `insufficientBalance()`. Any other failure keeps
    the `$PULSE` message.
- Command: `php artisan llm-check`, signature `llm-check`.
  - It refuses with exit 1 and a Spanish message when
    `ecostation.classifier.deepseek.api_key` is empty.
  - Otherwise it builds `new DeepSeekClassifier(...)` from config and
    classifies `tests/Fixtures/bottle.jpg`.
  - On success it prints `OK: <material_class> (<confidence>)` and the
    line `json_object + thinking:disabled + imagen: compatible`, and exits
    0.
  - On `ClassificationException` it prints the message and exits 1.

- [ ] **Step 1: Port `StubClassifierTest`** unchanged.
- [ ] **Step 2: Port `DeepSeekClassifierTest`.**
  - Assert that `the_request_matches_todays_documented_wire_format` pins
    `detail: high`, `json_object`, `thinking.disabled`, `temperature 0` and
    `max_tokens 200`.
  - Replace the generic `a_…` HTTP-error tests (lines 217 and 230 in
    `$PULSE`) with:

```php
#[Test] public function a_429_or_503_is_retried_once_then_succeeds(): void
{
    Http::fake(['api.deepseek.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'rate']], 429)
        ->push($this->okBody('metal'), 200)]);
    $this->assertSame('metal', $this->driver()->classify($this->jpeg())['material_class']);
    Http::assertSentCount(2);
}

#[Test] public function a_persistent_500_fails_after_exactly_two_attempts(): void
{
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);
    $this->expectException(ClassificationException::class);
    try { $this->driver()->classify($this->jpeg()); } finally { Http::assertSentCount(2); }
}

#[Test] public function a_402_is_named_insufficient_balance_and_not_retried(): void
{
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'Insufficient Balance']], 402)]);
    try { $this->driver()->classify($this->jpeg()); $this->fail(); }
    catch (ClassificationException $e) { $this->assertStringContainsString('saldo insuficiente', $e->getMessage()); }
    Http::assertSentCount(1);
}
```

  `okBody`, `driver` and `jpeg` are the existing helpers of the `$PULSE`
  test. Rename them if they differ there.
- [ ] **Step 3: Write `LlmCheckCommandTest`.**
  - `refuses_without_a_key`: run `llm-check` with no key, expect exit 1 and
    output containing `DEEPSEEK_API_KEY`.
  - `reports_ok_on_a_faked_live_call`: set a key in config and
    `Http::fake` an OK body. Expect exit 0 and output containing `OK:`.
- [ ] **Step 4: Run the tests and confirm they fail.** Run
  `php artisan test --filter='Classifier|LlmCheck'`. Expected: FAIL.
- [ ] **Step 5: Implement** the files listed above.
- [ ] **Step 6: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 7: Commit**:
  `git commit -m "feat(web): stub + DeepSeek classifier with retry, 402 handling, llm-check"`

---

### Task 9: Points, leaderboard (students + classes), classification and classify endpoint

**Model:** sonnet

**Files:**
- Create: `app/Services/PointsService.php`. Port only `balance`,
  `statement` and `awardRecyclingPoints`, without the spend path.
- Create: `app/Services/Recycling/LeaderboardService.php`. Port it and add
  `classes()`.
- Create: `app/Services/Realtime/RecyclingUpdateLog.php`, ported.
- Create: `app/Services/Recycling/ClassificationService.php`. Port it with
  the frame payload changes below.
- Create: `app/Http/Requests/ClassifyRequest.php`, ported. Replace
  `OwnedByCurrentSchool` with `exists:events,id`.
- Create: `app/Http/Controllers/Api/V1/RecyclingClassificationController.php`.
  Port it and remove the event-type check.
- Modify: `routes/api.php` to add `POST /recycling/classify` (name
  `api.v1.recycling.classify`).
- Modify: `lang/es/api.php` to add `event_not_owned_by_reader`,
  `event_expired` and `classifier_unavailable`.
- Test: `tests/Unit/PointsServiceTest.php`,
  `tests/Unit/LeaderboardServiceTest.php` and
  `tests/Feature/Api/ClassificationTest.php`, all ported and extended.

**Interfaces:**
- Produces:
  - `PointsService`:
    - `balance(Student $s): int`;
    - `statement(Student $s): array{balance: int, earned: int, spent: int}`;
    - `awardRecyclingPoints(Student $s, PresenceEvent $e, MaterialClass $m): int`.
      Ported unchanged: it returns `$m->points()` and writes a ledger row
      only when that is greater than 0. `is_bottle` and `is_recyclable`
      do not affect the amount.
  - `LeaderboardService`:
    - `top(int $limit = 10): array<int, array{rank,student_id,student_name,class_name,points}>`;
    - `rankOf(Student $s): array{rank: ?int, points: int}`;
    - `snapshot(int $limit = 3): array`;
    - **new** `classes(): array<int, array{rank: int, class_id: int, class_name: string, students: int, points: int, avg_points: float}>`.
      Every class appears. `avg_points = round(points / max(students, 1), 1)`.
      Classes are ranked by **`avg_points`** (user decision: it's fair to small classes), then
      by `class_id` ascending, using competition ranks. Reuse the existing
      rank-stamping routine.
  - `ClassificationService::classifyAndAward(Reader $reader, PresenceEvent $event, string $imagePath, ?string $storedPath = null, ?callable $insideAwardTx = null): array{duplicate: bool, deposit: RecyclingDeposit, new_balance: int}`.
  - Frame payloads, all written inside the award transaction:
    - `validated` keeps the `$PULSE` keys (including `is_recyclable`) and
      adds `student_id` and `class_id`. It carries no cue.
    - `points_awarded` keeps the `$PULSE` keys
      (`event_id, student_id, student_name, material_class, points,
      new_balance`) and adds `class_id`. **It is the one frame the speaker
      reads for its result cue.** It is written once per new
      classification, **including when `points` is 0** (an `other`
      result). `points` is `$material->points()`, the same value as
      `deposit.points_awarded`.
    - `leaderboard_updated` and `capture_created` are unchanged.
    - `class_id` is the student's class, or null. Task 14 uses `class_id`
      to filter teachers.
    - The duplicate path (an event already classified) writes no frames.
  - `PointsLedger` has no update or delete call sites.

- [ ] **Step 1: Port `PointsServiceTest`.** Drop the two `spend_on_reward`
  tests. Then add:

```php
#[Test] public function changing_a_material_rate_is_never_retroactive(): void
{
    $this->seedDemo();
    $student = $this->cardOf('Maria González')->student;
    $points = app(PointsService::class);
    $points->awardRecyclingPoints($student, $this->recyclingTapFor('Maria González'), MaterialClass::Plastic);
    settings()->setMany(['points.plastic' => 20]);
    $points->awardRecyclingPoints($student, $this->recyclingTapFor('Maria González'), MaterialClass::Plastic);
    $this->assertSame([10, 20], PointsLedger::where('student_id', $student->id)->orderBy('id')->pluck('delta')->all());
    $this->assertSame(30, $points->balance($student));
}
```

- [ ] **Step 2: Port `LeaderboardServiceTest`.**
  - Rewrite `spend_movements_count_against_the_total` to insert a negative
    ledger row directly, with `reason` `'adjustment'`.
  - Add the class-ranking test:

```php
#[Test] public function classes_rank_by_average_points_per_student_with_every_class_listed(): void
{
    $this->seedDemo();
    PointsLedger::create(['student_id' => $this->cardOf('Diego López')->student_id, 'delta' => 15, 'reason' => 'recycling_deposit']);
    PointsLedger::create(['student_id' => $this->cardOf('Maria González')->student_id, 'delta' => 22, 'reason' => 'recycling_deposit']);
    $board = app(LeaderboardService::class)->classes();
    // 5° A has more total points (22 vs 15) but a lower average (7.3 vs 7.5).
    $this->assertSame(['5° B', '5° A'], array_column($board, 'class_name'));
    $this->assertSame([1, 2], array_column($board, 'rank'));
    $this->assertSame([2, 3], array_column($board, 'students'));
    $this->assertSame([15, 22], array_column($board, 'points'));
    $this->assertSame([7.5, 7.3], array_column($board, 'avg_points'));
}
```

- [ ] **Step 3: Port `ClassificationTest`.**
  - Drop `non_recycling_events_cannot_be_classified`.
  - Rename `an_unreachable_local_model_returns_…` to
    `a_classifier_failure_returns_503_and_awards_nothing`, using a fake that
    throws `ClassificationException`.
  - Add to `awarding_records_the_recycling_realtime_frames`: assert that
    the `validated` payload has `is_recyclable`, `student_id` and
    `class_id`, and that the `points_awarded` payload has `points`
    (an int), `new_balance`, `student_id` and `class_id`.
  - Add the cue tests (they pin the frame the app reads):

```php
#[Test] public function an_other_result_awards_nothing_but_still_emits_points_awarded_with_zero(): void
{
    $this->seedDemo();
    $event = $this->recyclingTapFor('Maria González');
    $this->fakeClassifier(['material_class' => 'other', 'confidence' => 0.9, 'is_bottle' => false, 'is_recyclable' => false]);
    $this->postSigned('/api/v1/recycling/classify', $event)->assertOk()->assertJsonPath('points_awarded', 0);
    $this->assertSame(0, app(PointsService::class)->balance($event->card->student));
    $this->assertSame(0, PointsLedger::where('event_id', $event->id)->count());
    $frames = RecyclingUpdate::where('type', 'points_awarded')->get();
    $this->assertCount(1, $frames);
    $this->assertSame(0, $frames[0]->payload['points']);
}

#[Test] public function every_new_classification_emits_exactly_one_points_awarded_and_a_retry_emits_none(): void
{
    $this->seedDemo();
    $event = $this->recyclingTapFor('Maria González');
    $this->fakeClassifier(['material_class' => 'plastic', 'confidence' => 0.9, 'is_bottle' => true, 'is_recyclable' => true]);
    $this->postSigned('/api/v1/recycling/classify', $event)->assertOk();
    $this->postSigned('/api/v1/recycling/classify', $event)->assertOk()->assertJsonPath('already_classified', true);
    $frames = RecyclingUpdate::where('type', 'points_awarded')->get();
    $this->assertCount(1, $frames);
    $this->assertSame(10, $frames[0]->payload['points']);
}
```

  `fakeClassifier` and `postSigned` are small private helpers in the test
  class. `fakeClassifier` binds a `MaterialClassifier` fake that returns the
  given array, and `postSigned` sends the multipart request with the
  Pulse-HMAC header for `reader()`.
- [ ] **Step 4: Un-skip the two classify tests** in `DeviceHmacAuthTest`.
- [ ] **Step 5: Run the tests and confirm they fail.** Run
  `php artisan test --filter='PointsService|Leaderboard|Classification|DeviceHmac'`.
  Expected: FAIL.
- [ ] **Step 6: Implement** the files listed above. Keep
  the `$PULSE` award rule in `ClassificationService` as is, and make sure
  `deposit.points_awarded` and the `points_awarded` frame's `points` both
  use `$material->points()`.
- [ ] **Step 7: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 8: Commit**:
  `git commit -m "feat(web): classify endpoint, ledger points, student+class leaderboard"`

---

### Task 10: Bottle-first capture flow

**Model:** sonnet

**Files:**
- Create: `app/Services/Recycling/CaptureService.php` and
  `CaptureAlreadyAssociated.php`. Port both, and drop the
  `not_recycling_reader` branch. The event is created without `type`.
- Create: `app/Http/Requests/{CaptureRequest,CaptureAssociateRequest}.php`
  and `app/Http/Controllers/Api/V1/RecyclingCaptureController.php`, all
  ported. Drop the `isRecycling` guard.
- Modify: `routes/api.php` to add `POST /recycling/capture` and
  `POST /recycling/captures/{capture}/associate`, with the `$PULSE` names.
- Modify: `lang/es/api.php` to add `no_pending_capture`,
  `capture_not_owned_by_reader` and `capture_already_associated`.
- Test: `tests/Feature/Api/CaptureFlowTest.php`, ported.

**Interfaces:**
- Produces:
  - `CaptureService::store(Reader $reader, UploadedFile $image): array{ok: true, capture: PendingCapture, expires_in: int}`;
  - `CaptureService::associate(Reader $reader, int $captureId, string $credentialUid, ?string $hceNonce = null, ?string $hceMac = null): array`;
  - `CaptureService::sweep(): void`.
  - The `$PULSE` response keys and statuses are unchanged.

- [ ] **Step 1: Port `CaptureFlowTest`** in full. All six of its tests
  apply.
- [ ] **Step 2: Un-skip the remaining deferred tests.** These are
  `capture_association_also_requires_the_proof` (HceCredentialTest) and the
  bottle-first test in `DeviceHmacAuthTest`.
- [ ] **Step 3: Run the tests and confirm they fail.** Run
  `php artisan test --filter='CaptureFlow|HceCredential|DeviceHmac'`.
  Expected: FAIL.
- [ ] **Step 4: Implement** the files listed above.
- [ ] **Step 5: Run the tests and confirm they pass.** Expected: PASS. The
  full suite is green.
- [ ] **Step 6: Commit**:
  `git commit -m "feat(web): bottle-first capture + associate endpoints"`

---

### Task 11: Web auth, roles, forced password change, teacher scope

**Model:** sonnet

**Files:**
- Create:
  - `app/Http/Controllers/Web/{AuthController,PasswordController}.php`,
    ported;
  - `app/Http/Requests/LoginRequest.php`, ported;
  - `app/Http/Middleware/{EnsureRole,EnsurePasswordChanged}.php`, ported;
  - `app/Services/StudentScope.php`, ported. `forUser` uses the
    `classes()` pivot. The station role gets an empty allowlist, which
    fails closed.
- Create: `resources/views/layouts/app.blade.php` (bare: `<main>`, flash
  `status`, logout form), `resources/views/auth/login.blade.php` (with
  `@csrf`) and `resources/views/auth/password.blade.php`.
- Create: `resources/views/station.blade.php`. It contains one Spanish line
  telling the user this account is for the speaker app.
- Modify: `routes/web.php` and `bootstrap/app.php`.
  - Add the aliases `role` and `password.changed`.
  - Append `EnsurePasswordChanged` to the `web` and `api` groups.
  - Add the `login` rate limiter: 5 per minute per email+IP.
- Test: `tests/Feature/Web/AuthTest.php`,
  `tests/Feature/Web/PasswordChangeTest.php` and
  `tests/Unit/StudentScopeTest.php`.

**Interfaces:**
- Routes:
  - `GET|POST /login` (name `login`, guest, `throttle:login`) and
    `POST /logout` (name `logout`).
  - `GET|PUT /password/change` (names `password.change` and
    `password.update`).
  - `GET /` (name `home`) redirects by role: student →
    `student.dashboard`, teacher → `teacher.dashboard`, admin →
    `admin.students`, station → `station`.
  - `GET /station` (name `station`, `role:station`).
- `EnsureRole` is used as `role:admin,teacher` and so on. It answers 403
  otherwise.
- `EnsurePasswordChanged::EXEMPT` = `['login','logout','password.change','password.update','api.v1.auth.password','api.v1.auth.token.destroy']`.
- `StudentScope`:
  - `forUser(User $u): self`, `isUnrestricted(): bool`,
    `classIdFilter(): ?array`;
  - `allowsStudent(Student $s): bool`, `students(): Builder`.

- [ ] **Step 1: Write the tests.**
  - Port `AuthTest` and `PasswordChangeTest`. Drop the locale and kitchen
    cases, and add a `station` redirect case.
  - Add to `AuthTest`:

```php
#[Test] public function the_login_form_keeps_the_csrf_field_the_speaker_app_scrapes(): void
{
    $this->get('/login')->assertOk()->assertSee('name="_token"', false);
}
```

  - In `StudentScopeTest`, using `seedDemo()`:
    - the teacher's scope allows Maria (5° A) and refuses Diego (5° B);
    - `students()->pluck('name')` equals the three 5° A names;
    - the admin is unrestricted;
    - the station allows no one;
    - a student account with `student_id=null` allows no one.
- [ ] **Step 2: Run the tests and confirm they fail.** Run
  `php artisan test --filter='AuthTest|PasswordChange|StudentScope'`.
  Expected: FAIL.
- [ ] **Step 3: Implement** the files listed above.
- [ ] **Step 4: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): session login, roles incl. station, password rotation, teacher scope"`

---

### Task 12: Student API for the Android app (Sanctum tokens)

**Model:** sonnet

**Files:**
- Create: `app/Http/Controllers/Api/V1/{AuthTokenController,MeController,LeaderboardController}.php`.
  `LeaderboardController` is ported.
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/StudentApiTest.php`, plus
  `tests/Feature/Api/LeaderboardTest.php` (ported).

**Interfaces:**
- `POST /api/v1/auth/token` (name `api.v1.auth.token`, `throttle:login`):
  - Body: `{email, password, device_name}`.
  - Only role `student` may get a token. Every other role, and bad
    credentials, get 422 with the `email` error `__('auth.failed')`.
  - 200 returns
    `{token: string, must_change_password: bool, student: {id, name, class_name}}`.
  - The token is `$user->createToken($device_name, ['student'])->plainTextToken`.
- `DELETE /api/v1/auth/token` (name `api.v1.auth.token.destroy`,
  `auth:sanctum`) deletes `currentAccessToken()` and returns 204.
- `PUT /api/v1/auth/password` (name `api.v1.auth.password`, `auth:sanctum`):
  - Body: `{current_password, password, password_confirmation}`.
  - It follows the same rules as the web `PasswordController`, clears
    `must_change_password` and returns 204.
- `GET /api/v1/me/balance` (`auth:sanctum`, `role:student`):
  - returns
    `{student: {id, name, class_name}, balance: int, rank: ?int}`;
  - 404 when `users.student_id` is null.
- `GET /api/v1/me/history` (`auth:sanctum`, `role:student`):
  - returns `simplePaginate(20)` of the student's deposits, newest first,
    in Laravel's default envelope (`data`, `current_page`,
    `next_page_url`, ...). The app reads `data` and `next_page_url`, and
    asks for more with `?page=N`;
  - items are
    `{deposit_id, occurred_at (ISO 8601, America/Bogota), material_class, points_awarded, is_recyclable}`;
  - there are no photo URLs, because photos are staff-only (my call);
  - 404 when `users.student_id` is null.
- `GET /api/v1/recycling/leaderboard` (`auth:sanctum`,
  `role:admin,teacher,student`) returns `{top: [...], classes: [...], me?: {rank, points}}`.
  `me` is present only for students. `?limit` is clamped to 1..100.

- [ ] **Step 1: Write `StudentApiTest`.** Create a token with
  `postJson('/api/v1/auth/token', [...])` and use it as
  `withToken($token)`. Cover these cases:
  - `a_student_gets_a_token_and_reads_only_their_own_balance_and_history`:
    give Maria one classified deposit (fake plastic, 10 points). Then the
    token response has `token`, `must_change_password=false` and
    `student.{id,name,class_name}`; `balance` is 10 and `rank` is 1;
    history `data` has one item with exactly the keys `deposit_id`,
    `occurred_at` (matching `/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d-05:00$/`),
    `material_class=plastic`, `points_awarded=10` and `is_recyclable=true`;
    the JSON has `next_page_url` (null); and Carlos's token sees `balance`
    0 and empty history.
  - `staff_and_station_accounts_cannot_get_a_token`: admin, teacher and
    station all get 422.
  - `a_teacher_token_is_forbidden_from_me_endpoints`: create a token
    directly with `$teacher->createToken('x')` and expect 403.
  - `an_orphaned_student_account_gets_404_not_data`: null out
    `student_id`, then expect 404 on both `/me` endpoints.
  - `a_flagged_account_must_change_password_first`: with
    `must_change_password=true`, the token response says so,
    `/me/balance` and `/me/history` are 403, and `PUT /api/v1/auth/password`
    works with that same token. After it, `/me/balance` is 200. A wrong
    `current_password` is 422.
  - `logout_revokes_the_token`: after the DELETE, `/me/balance` is 401.
- [ ] **Step 2: Port `LeaderboardTest`** to the new body shape, and add
  `assertJsonCount(2, 'classes')`.
- [ ] **Step 3: Run the tests and confirm they fail.** Run
  `php artisan test --filter='StudentApi|LeaderboardTest'`. Expected: FAIL.
- [ ] **Step 4: Implement** the files listed above.
- [ ] **Step 5: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 6: Commit**:
  `git commit -m "feat(web): Sanctum student API (token, balance, history, leaderboard)"`

---

### Task 13: Deposit photos (scoped access) and automatic retention purge

**Model:** sonnet

**Files:**
- Create: `app/Http/Controllers/Web/DepositPhotoController.php`, adapted
  from `$PULSE` `CaptureImageController`.
- Create: `app/Console/Commands/PurgePhotosCommand.php`
- Modify: `routes/web.php` and `routes/console.php` (schedule)
- Test: `tests/Feature/Web/DepositPhotoTest.php` (ported from
  `CaptureImageTest` and extended) and
  `tests/Feature/Console/PurgePhotosCommandTest.php`.

**Interfaces:**
- `GET /deposits/{deposit}/photo` (name `deposits.photo`, `auth`,
  `role:admin,teacher`):
  - It streams `Storage::disk('local')` using the stored mime type.
  - It returns 404 in three cases: `StudentScope::forUser()` refuses the
    deposit's student, `image_path` is null, or the file is missing.
- `php artisan photos:purge`, scheduled `->daily()` in `routes/console.php`:
  1. For each deposit whose event `occurred_at` is older than
     `now()->subDays(settings()->photoRetentionDays())` and whose
     `image_path` is not null, delete the file if it exists and set
     `image_path=null`.
  2. Delete `pending_captures` rows that are in a terminal state
     (`expired`, `rejected`, `failed` or `accepted`) and whose
     `created_at` is older than the cutoff, deleting their files first.
     An `accepted` capture's file is the deposit's file, so step 1
     already handled it. Deleting a missing file is a no-op.
  3. Print `Fotos eliminadas: <n>`.

- [ ] **Step 1: Port `CaptureImageTest` as `DepositPhotoTest`.**
  - Keep the admin-streams, null-image-404, guest-redirect and
    unknown-404 tests.
  - Replace `the_image_door_is_closed_to_teachers_and_students` with:

```php
#[Test] public function a_teacher_sees_photos_of_their_classes_only_and_students_never(): void
{
    $own = $this->depositWithPhotoFor('Maria González');   // 5° A
    $other = $this->depositWithPhotoFor('Diego López');    // 5° B
    $this->actingAs($this->teacher())->get("/deposits/{$own->id}/photo")->assertOk();
    $this->actingAs($this->teacher())->get("/deposits/{$other->id}/photo")->assertNotFound();
    $this->actingAs(User::where('role', 'student')->first())->get("/deposits/{$own->id}/photo")->assertForbidden();
}

#[Test] public function a_photo_whose_file_vanished_is_404_not_500(): void
{
    $d = $this->depositWithPhotoFor('Maria González');
    Storage::disk('local')->delete($d->image_path);
    $this->actingAs($this->admin())->get("/deposits/{$d->id}/photo")->assertNotFound();
}
```

  `depositWithPhotoFor` is a private helper. It uses `Storage::fake('local')`,
  `UploadedFile::fake()->image('b.jpg')->store('recycling-captures','local')`,
  and a `RecyclingDeposit` on `recyclingTapFor($name)`.
- [ ] **Step 2: Write `PurgePhotosCommandTest`**:

```php
#[Test] public function old_photos_are_deleted_but_the_deposit_and_points_stay_and_a_rerun_is_a_noop(): void
{
    settings()->setMany(['photos.retention_days' => 30]);
    $old = $this->depositWithPhotoFor('Maria González', occurredAt: now()->subDays(31), points: 10);
    $new = $this->depositWithPhotoFor('Maria González', occurredAt: now()->subDays(29), points: 10);
    $oldPath = $old->image_path;
    $this->artisan('photos:purge')->expectsOutputToContain('Fotos eliminadas: 1')->assertSuccessful();
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($new->image_path);
    $this->assertNull($old->refresh()->image_path);
    $this->assertSame('plastic', $old->material_class->value);
    $this->assertSame(20, app(PointsService::class)->balance($old->event->card->student));
    $this->artisan('photos:purge')->expectsOutputToContain('Fotos eliminadas: 0')->assertSuccessful();
}

#[Test] public function the_purge_is_scheduled_daily(): void
{
    $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains($e->command, 'photos:purge')));
}
```

  Add one more test: an expired `pending_captures` row older than the
  cutoff loses its file and its row. A younger one is kept.
- [ ] **Step 3: Run the tests and confirm they fail.** Run
  `php artisan test --filter='DepositPhoto|PurgePhotos'`. Expected: FAIL.
- [ ] **Step 4: Implement** the files listed above. Also write
  `docs/PRIVACIDAD.md` at the repo root, in Spanish. The spec requires it
  to say two things:
  - deposit photos are sent to DeepSeek for classification when the
    `deepseek` driver is active;
  - photos are deleted after N days (default 30, an admin setting), while
    the classification, event and points are kept.
- [ ] **Step 5: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 6: Commit**:
  `git commit -m "feat(web): scoped deposit photos + daily retention purge"`

---

### Task 14: Realtime feed (token + WebSocket server, station role)

**Model:** opus

**Files:**
- Create: `config/realtime.php` (ported) and
  `app/Services/Realtime/{Handshake,WsFrame,RealtimeToken,RealtimeFeed,RealtimeRecycling,RealtimePairing}.php`
  (all ported).
- Create: `app/Console/Commands/RealtimeServeCommand.php`. Port it and
  strip the roster channel, `CurrentSchool`, `school_id` and
  `clientSeesSchool`.
- Create: `app/Http/Controllers/Web/RealtimeTokenController.php`, ported.
- Modify: `routes/web.php` and `routes/api.php`.
- Test:
  - `tests/Unit/Realtime/{HandshakeTest,WsFrameTest,RealtimeTokenTest,RealtimeFeedTest,RealtimePairingTest}.php`,
    all ported;
  - `tests/Feature/Realtime/RealtimeServerTest.php`, ported and adapted;
  - `tests/Feature/Web/RealtimeTokenRouteTest.php`, ported.
  - Port `scripts/_lib/realtime-probe.php` too, if `RealtimeServerTest`
    uses it.

**Interfaces:**
- `GET /realtime/token` (name `realtime.token`, `auth`,
  `role:admin,teacher,station`) returns `{token, expires_at, url}`.
- `GET /api/v1/realtime/token` (`auth:sanctum`, `role:station`) runs the
  same controller, for a future token-based speaker.
- `RealtimeFeed` rows keep the `$PULSE` keys with these changes:
  - `school_id`, `served` and `reason` are removed (the app treats a
    missing `served` as accepted);
  - `type` is `PresenceEvent::TYPE`;
  - `feedback` is always `'accepted'`.
- Frame shapes on the wire (the app parses these):
  - `{"type":"tap","event":{...,"feedback":"accepted"}}`;
  - `{"type":"feedback","feedback":{"id","cue":"accepted"|"rejected","reason","event_id","reader_id"}}`;
  - `{"type":"recycling","update":{"id","type","payload":{...},"at"}}`,
    where `points_awarded` carries `payload.points` (Task 9);
  - `hello` carries history for every channel and is never a cue.
- Delivery rules in `realtime:serve`:

  | Channel | Admin | Teacher | Station | Student |
  |---|---|---|---|---|
  | `tap` | all | rows whose `class_id` ∈ their classes | all | — (no token) |
  | `recycling` | all | if the payload has a `class_id` key, only when it is non-null and in their classes; payloads with no `class_id` key (`capture_created`, `leaderboard_updated`) go to everyone | all, **unscoped, every frame type including `validated` and `points_awarded`** | — |
  | `feedback` | all | — | all | — |
  | `pairing` | all | — | — | — |

- `resolveScope()` returns `{admin: bool, role: string, class_ids: ?array<int>}`.
  A teacher's `class_ids` are read from `teacher_class`. An unknown user
  is refused at handshake with a 401, which fails closed.

- [ ] **Step 1: Port the unit tests.** Port the five unit tests and
  `RealtimeTokenRouteTest`. Its role matrix changes: admin, teacher and
  station get 200; student gets 403; guest gets a redirect.
  Add the speaker's real path as one test: `POST /login` as the demo
  station account (`estacion@ecostation.test`, `must_change_password=false`),
  then `GET /realtime/token` with `Accept: application/json` and the same
  session cookie answers 200 with `token`, `expires_at` and
  `url === 'ws://localhost:8081/app'` (the test host and
  `config('realtime.port')`). With `must_change_password=true` the same
  call is a redirect, not JSON.
- [ ] **Step 2: Port `RealtimeServerTest`.**
  - Keep `the_feed_answers_hello_with_history_and_broadcasts_new_taps`,
    `pairing_state_changes_broadcast_to_connected_desks`,
    `pairing_frames_never_reach_teacher_connections`,
    `teacher_connections_only_receive_taps_of_their_own_classes`,
    `invalid_tokens_are_refused_with_plain_http_…` and
    `recycling_updates_broadcast_to_every_connection_and_ride_the_hello`.
    Rename the last one to `recycling_updates_respect_teacher_scope`, and
    assert that a 5° A teacher gets Maria's `validated` frame but not
    Diego's.
  - Drop the roster, kitchen and student-connection tests.
  - Replace `feedback_cues_reach_staff_speaker_connections_but_not_teachers`
    with
    `station_connections_receive_tap_recycling_and_feedback_but_never_pairing`.
    In it, classify an `other` (0-point) deposit for Diego (5° B) and a
    plastic one for Maria (5° A). Assert the station connection receives
    two `points_awarded` frames, one with `payload.points === 0` and one
    with `10`, one per deposit, and no `points_awarded` frame for a
    duplicate classify. Also assert a 5° A teacher connection receives
    only Maria's.
- [ ] **Step 3: Run the tests and confirm they fail.** Run
  `php artisan test --filter=Realtime`. Expected: FAIL.
- [ ] **Step 4: Implement** the files listed above.
- [ ] **Step 5: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 6: Commit**:
  `git commit -m "feat(web): realtime feed with tap/recycling/feedback/pairing + station role"`

---

### Task 15: mDNS `_ecostation._tcp` and `./run serve`

**Model:** haiku

**Files:**
- Create: `scripts/mdns/announce.py`, ported from
  `$PULSE/scripts/mdns/announce.py` with `SERVICE = "_ecostation._tcp.local"`.
  The docstring is Spanish only, and the default `--name` is `EcoStation`.
- Create: `scripts/mdns/ecostation.service`. Port the avahi XML with type
  `_ecostation._tcp` and name `EcoStation en %h`.
- Create: `app/Http/Controllers/Web/ManifestController.php` and the route
  `GET /manifest.webmanifest` (name `manifest`, public, no session
  middleware needed). It returns JSON `{name: config('app.name'),
  short_name: config('app.name'), start_url: '/', display: 'standalone',
  lang: 'es'}` with `Content-Type: application/manifest+json`.
- Modify: `run`. `serve` follows `$PULSE/scripts/serve.sh`, without
  bilingual text:
  - it starts `php artisan serve --host=0.0.0.0 --port=8000`,
    `php artisan realtime:serve` and `php artisan schedule:work`;
  - if `avahi-publish-service` exists, it runs
    `avahi-publish-service EcoStation _ecostation._tcp 8000 version=1 protocol=1 api=/api`
    in the background;
  - it runs `python3 scripts/mdns/announce.py --port 8000` in the
    background;
  - it traps EXIT/INT to kill all children;
  - `ECOSTATION_MDNS=0` skips the advertisement.
- Test: `tests/Unit/ScriptsTest.php` and `tests/Feature/ManifestTest.php`

**Interfaces:**
- The mDNS contract for the firmware and app plans: service
  `_ecostation._tcp.local`, TXT `version=1`, `protocol=1`, `api=/api`, port
  8000. The WebSocket port is not advertised: `/realtime/token` hands it
  out (`ws://<host>:8081/app`).
- The app's subnet-sweep fallback accepts a host only when
  `GET /manifest.webmanifest` answers 200 with a body containing
  `"start_url"`.

- [ ] **Step 1: Write the failing test**:

```php
#[Test] public function the_lan_advertisement_uses_the_ecostation_service_name(): void
{
    $py = file_get_contents(base_path('scripts/mdns/announce.py'));
    $this->assertStringContainsString('SERVICE = "_ecostation._tcp.local"', $py);
    $this->assertStringNotContainsString('_pulse', $py);
    $this->assertStringContainsString('<type>_ecostation._tcp</type>', file_get_contents(base_path('scripts/mdns/ecostation.service')));
    $this->assertStringContainsString('_ecostation._tcp', file_get_contents(base_path('run')));
}
```

  And `ManifestTest`:

```php
#[Test] public function the_manifest_is_public_and_carries_start_url_for_the_apps_subnet_probe(): void
{
    $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', '/')->assertSee('"start_url"', false);
}
```

- [ ] **Step 2: Run it and confirm it fails.** Run
  `php artisan test --filter='ScriptsTest|ManifestTest'`. Expected: FAIL.
- [ ] **Step 3: Implement.** Then run `bash -n run` and
  `python3 -m py_compile scripts/mdns/announce.py`.
- [ ] **Step 4: Run the tests and confirm they pass.** Expected: PASS.
  Smoke-test by hand: `./run serve`, then `avahi-browse -rt _ecostation._tcp`
  lists the server.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): _ecostation._tcp mDNS + ./run serve"`

---

### Task 16: Student web pages (unstyled)

**Model:** sonnet

**Files:**
- Create: `app/Http/Controllers/Web/StudentController.php`
- Create: `resources/views/student/{dashboard,history,leaderboard}.blade.php`.
  These are plain `<h1>`, `<dl>` and `<table>` markup, extending
  `layouts.app`, with no CSS.
- Modify: `routes/web.php`
- Test: `tests/Feature/Web/StudentPagesTest.php`

**Interfaces:**
- All routes are `auth` and `role:student`. The student always comes from
  `auth()->user()->student`, and is 404 when that is null.
- `GET /student` (name `student.dashboard`) passes the view data
  `balance:int`, `rank:?int` and `recent:Collection` (the last 5
  deposits).
- `GET /student/history` (name `student.history`) passes
  `deposits: Paginator` (20 per page) with `occurred_at`,
  `material_class` and `points_awarded`.
- `GET /student/leaderboard` (name `student.leaderboard`) passes
  `top: array` (`LeaderboardService::top(10)`), `classes: array` and
  `me: array{rank, points}`.

- [ ] **Step 1: Write the tests.**
  - Maria with one 10-point deposit sees `assertViewHas('balance', 10)`,
    and her history contains `plastic`.
  - Carlos's history does not contain Maria's deposit.
  - The leaderboard has `classes` with 2 rows.
  - A teacher gets 403 on `/student`, and a guest is redirected to
    `/login`.
- [ ] **Step 2: Run the tests and confirm they fail.** Run
  `php artisan test --filter=StudentPagesTest`. Expected: FAIL.
- [ ] **Step 3: Implement** the files listed above.
- [ ] **Step 4: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): student dashboard, history, leaderboard (unstyled)"`

---

### Task 17: Teacher pages and exports (scoped)

**Model:** sonnet

**Files:**
- Create: `app/Services/RecyclingReportService.php`. Port it with the
  changes below.
- Create: `app/Services/Pdf/RecyclingReportPdf.php`. Port it with
  `BrandedReportPdf` folded in:
  - the header shows `config('app.name')` and no school brand;
  - the colors are protected constants that Task 20 will change;
  - the `daily` and `monthly` methods are not ported.
- Create: `app/Http/Controllers/Web/{TeacherDashboardController,TeacherExportController}.php`
- Create: `resources/views/teacher/{dashboard,class,student,live}.blade.php`,
  unstyled.
- Modify: `routes/web.php`
- Test: `tests/Feature/Web/TeacherPagesTest.php` and
  `tests/Unit/RecyclingReportServiceTest.php`

**Interfaces:**
- `RecyclingReportService` (every method takes `?array $classIds`, where
  null means the whole school):
  - `trend(string $from, string $to, ?array $classIds = null): array<int, array{date, items, points}>`;
  - `byMaterial(string $from, string $to, ?array $classIds = null): array<string, array{items, points, rate}>`.
    `rate` comes from `MaterialClass::points()`, not config.
  - **new** `byClass(string $from, string $to, ?array $classIds = null): array<int, array{class_id, class_name, items, points}>`;
  - `studentHistory(Student $student, int $days = 30): array{student, deposits: array<int, array{deposit_id, date, time, material, points, is_recyclable, has_photo: bool, reader}>, totals: array{items, points}}`.
    The redemption fields are removed.
- `RecyclingReportPdf`:
  - `summary(string $from, string $to, ?array $classIds): Response`;
  - `student(Student $student): Response`.
- Routes (all `auth` and `role:admin,teacher`; scope is
  `StudentScope::forUser`):
  - `GET /teacher` (name `teacher.dashboard`): query `from` and `to`
    (`Y-m-d`), defaulting to the last 30 days. Passes `trend`,
    `byMaterial` and `byClass`.
  - `GET /teacher/classes/{class}` (name `teacher.class`): the class's
    students with balances and its `byMaterial`. 404 if
    `classIdFilter()` excludes it.
  - `GET /teacher/students/{student}` (name `teacher.student`): passes
    `history`. Each deposit with `has_photo` links to
    `route('deposits.photo', $id)`. 404 if `allowsStudent` is false.
  - `GET /teacher/live` (name `teacher.live`): passes `recent`
    (`RealtimeFeed::recent(20, classIdFilter())`) and
    `realtimeTokenUrl = route('realtime.token')`. There is no JS until
    Task 20.
  - `GET /teacher/export.csv` (name `teacher.export.csv`): one row per
    deposit in the range and scope. Columns: `fecha`, `hora`,
    `estudiante`, `curso`, `material`, `puntos`, `reciclable`. It is
    streamed with `response()->streamDownload` and `fputcsv`.
  - `GET /teacher/export.pdf` (name `teacher.export.pdf`).
  - `GET /teacher/students/{student}/export.csv` and `.pdf` (names
    `teacher.student.export.csv` and `teacher.student.export.pdf`), with
    the same 404 scope rule.

- [ ] **Step 1: Write `RecyclingReportServiceTest`.**
  - One deposit for Maria (5° A, plastic) and one for Diego (5° B, metal).
  - `byMaterial(..., [5° A id])['plastic']['items'] === 1` and
    `['metal']['items'] === 0`.
  - `byClass` with a null scope has two rows with points 10 and 15.
  - `trend` is zero-filled across a 3-day range.
- [ ] **Step 2: Write `TeacherPagesTest`.**
  - For the 5° A teacher, `/teacher` has `byClass` with only `5° A`.
  - `/teacher/students/{Diego}`, `/teacher/classes/{5° B}` and
    `/teacher/students/{Diego}/export.csv` are all 404.
  - `/teacher/export.csv` contains `María` or `Maria González` and does
    not contain `Diego López`.
  - The PDF route answers 200 with `Content-Type: application/pdf`.
  - The admin sees both classes, and a student gets 403.
- [ ] **Step 3: Run the tests and confirm they fail.** Run
  `php artisan test --filter='TeacherPages|RecyclingReportService'`.
  Expected: FAIL.
- [ ] **Step 4: Implement** the files listed above.
- [ ] **Step 5: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 6: Commit**:
  `git commit -m "feat(web): scoped teacher dashboard, student breakdown, live feed, CSV/PDF export"`

---

### Task 18: Admin: students, classes, staff (with teacher-class assignment)

**Model:** sonnet

**Files:**
- Create: `app/Services/StudentAccountService.php`. Port it, with the
  domain and password coming from settings.
- Create:
  `app/Http/Controllers/Web/Admin/{StudentController,ClassController,StaffController}.php`
  and `resources/views/admin/{students,classes,staff}.blade.php`. These are
  plain HTML forms with `@csrf` and no JS.
- Modify: `routes/web.php`
- Test: `tests/Feature/Web/AdminRosterTest.php`

**Interfaces:**
- All routes are `auth` and `role:admin`, under the `/admin` prefix with
  names `admin.*`.
- Students:
  - `GET /admin/students` (`admin.students`) lists students with class,
    card count and balance.
  - `POST /admin/students` (`admin.students.store`) takes `{name, class_id}`
    and provisions the account through
    `StudentAccountService::provisionFor`, with
    `must_change_password=true`. It flashes the e-mail and initial
    password once.
  - `POST /admin/students/import` (`admin.students.import`) takes a CSV
    file with header `nombre,curso`. It creates missing classes by name
    and skips duplicates of (`name`, `class_id`). It flashes counts
    `{creados, omitidos}`.
  - `DELETE /admin/students/{student}` (`admin.students.destroy`). The
    cascade removes cards, events, deposits and ledger rows.
- Classes:
  - `GET /admin/classes` (`admin.classes`).
  - `POST /admin/classes` (`admin.classes.store`) takes `{name}`, which is
    unique.
  - `PUT /admin/classes/{class}/teachers` (`admin.classes.teachers`) takes
    `{teacher_ids: int[]}` and calls `$class->teachers()->sync(...)`.
    Only users with role teacher are accepted; anything else is a 422.
- Staff:
  - `GET /admin/staff` (`admin.staff`).
  - `POST /admin/staff` (`admin.staff.store`) takes
    `{name, email, role: admin|teacher|station, password}`.
    `must_change_password` is true for admin and teacher, and **false for
    station** (Review Focus 4).

- [ ] **Step 1: Write `AdminRosterTest`** with one test per route:
  - Creating a student makes a student user with
    `must_change_password=true`.
  - A CSV with 3 rows, one of them a duplicate, gives `creados=2` and
    `omitidos=1`.
  - The teacher sync makes the demo teacher see 5° B in
    `StudentScope::forUser`.
  - Syncing a student-role id is a 422.
  - A station staff account has `must_change_password=false`.
  - Teacher and station users get 403 on every `/admin/*` route.
- [ ] **Step 2: Run the tests and confirm they fail.** Run
  `php artisan test --filter=AdminRosterTest`. Expected: FAIL.
- [ ] **Step 3: Implement** the files listed above.
- [ ] **Step 4: Run the tests and confirm they pass.** Expected: PASS.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): admin roster: students, classes, teacher assignment, staff"`

---

### Task 19: Admin: stations and keys, pairing desk, card revoke/unpair, settings

**Model:** sonnet

**Files:**
- Create:
  `app/Http/Controllers/Web/Admin/{StationController,PairingController,CardController,SettingsController}.php`
  and `resources/views/admin/{stations,pairing,settings}.blade.php`,
  unstyled.
- Modify: `routes/web.php`
- Test: `tests/Feature/Web/AdminStationsTest.php`,
  `tests/Feature/Web/AdminPairingTest.php` (with the deferred
  `HceCredentialTest` cases moved in), `tests/Feature/Web/AdminSettingsTest.php`,
  and `tests/Feature/Api/PairingStatusTest.php` (ported).

**Interfaces:**
- All routes are `auth` and `role:admin`.
- Stations:
  - `GET /admin/stations` (`admin.stations`) lists label, key fingerprint
    (`DeviceRequestSigner::fingerprint`) and last event time. It never
    shows the key.
  - `POST /admin/stations` (`admin.stations.store`) takes `{label}` and
    sets `api_key = Str::random(32)`. The key is flashed once.
  - `POST /admin/stations/{reader}/rotate-key` (`admin.stations.rotate`)
    flashes the new key once.
  - `DELETE /admin/stations/{reader}` (`admin.stations.destroy`).
- Pairing:
  - `GET /admin/pairing` (`admin.pairing`) passes `status`
    (`PairingService::statusPayload()`) and `students`.
  - `POST /admin/pairing/arm` (`admin.pairing.arm`) takes `{student_id}`
    and calls `PairingService::arm`.
  - `GET /admin/pairing/status` (`admin.pairing.status`) returns JSON
    `statusPayload()`.
- Cards:
  - `POST /admin/cards/{card}/revoke` (`admin.cards.revoke`) sets status
    `revoked` and deletes the `hceKey`. This is the `$PULSE`
    `CardRevokeController` logic.
  - `DELETE /admin/cards/{card}` (`admin.cards.destroy`) is unpair: it
    deletes the card row, which cascades.
- Settings:
  - `GET /admin/settings` (`admin.settings`) passes `values`
    (`settings()->all()`).
  - `PUT /admin/settings` (`admin.settings.update`) calls
    `settings()->setMany($request->input('settings'))` and redirects
    back, with errors on a `ValidationException`.

- [ ] **Step 1: Write the tests.**
  - `AdminStationsTest`:
    - a created station's flashed key authenticates `POST /api/v1/events/tap`;
    - after a rotate the old key gets 401 and the new one works;
    - the index HTML never contains any `api_key` value.
  - `AdminPairingTest`:
    - arming through the route and then the device pair succeeds;
    - a teacher gets 403 on the arm route;
    - the deferred HCE revoke and badge cases from Task 7 (revoke kills
      the next proven tap and destroys the key; status reports `kind`).
  - Port `PairingStatusTest` to `/admin/pairing/status`. Drop
    `a_window_written_by_a_clock_the_machine_no_longer_has_is_not_pending`
    only if it depends on the school layer; otherwise keep it.
  - `AdminSettingsTest`:
    - `PUT points.plastic=25` makes the next classify award 25;
    - `photos.retention_days=0` returns a session error and leaves the
      value at 30.
- [ ] **Step 2: Run the tests and confirm they fail.** Run
  `php artisan test --filter='AdminStations|AdminPairing|AdminSettings|PairingStatus'`.
  Expected: FAIL.
- [ ] **Step 3: Implement** the files listed above.
- [ ] **Step 4: Run the tests and confirm they pass.** Run
  `./run ci`. Expected: Pint clean and every test passing.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): admin stations/keys, pairing desk, revoke/unpair, settings"`

---

### Task 20: UI skin — BLOCKED on Penpot approval

**Model:** opus

**Status:** Do not start until the user has approved the logo and the web
screens in Penpot, and their exports exist under `design/` (for example
`design/web/*.png` and `design/brand/*`).

**Files:**
- Create: `public/css/tokens.css` and `public/css/app.css`, from the
  approved "fresh & playful" palette. Fonts are self-hosted under
  `public/fonts/`.
- Create: `public/js/realtime.js`. It fetches `/realtime/token`, opens the
  WebSocket and live-updates `teacher/live` and the pairing desk.
- Modify: every view under `resources/views/`. Layout and markup follow
  `design/web/*`.
- Modify: `app/Services/Pdf/RecyclingReportPdf.php`, for the colors and
  logo.

**Interfaces:**
- Controllers, routes and view-data keys from Tasks 11–19 stay unchanged.
  This task touches only presentation.

- [ ] **Step 1: Map each screen to its file.** List each `design/web/*`
  export with its view file, and confirm the mapping with the user.
- [ ] **Step 2: Implement the skin**, view by view.
- [ ] **Step 3: Confirm the suite still passes.** Run `./run ci`. Expected:
  every test from Tasks 1–19 passes unchanged. In particular,
  `the_login_form_keeps_the_csrf_field_the_speaker_app_scrapes` still
  passes.
- [ ] **Step 4: Check by eye.** Run `./run serve` and compare each screen
  against its Penpot export at 360 px and 1280 px wide.
- [ ] **Step 5: Commit**:
  `git commit -m "feat(web): apply approved EcoStation design"`
