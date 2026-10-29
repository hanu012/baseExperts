# PROGRESS

Session-by-session log of what was actually built. Newest entries at the
bottom. Task numbers refer to BUILD_PLAN.md.

---

## Before Launch Checklist

Standing list — **not** a log entry. Items are added here as they are
discovered during development and must all be cleared before going live
(Phase 8). Add to it whenever something is deferred rather than fixed.

- [ ] **Tune Filament's panel login throttle.** The admin panel currently
      runs on Filament's untuned defaults, while the API login enforces a
      deliberate 5 failed attempts / 15 minutes per email + IP. The admin
      panel is the higher-value target of the two and is presently the
      weaker of the two.
      *(Found in task 0.4.)*

- [x] ~~**Correct the `_.fieldName` rule in CLAUDE.md's Flutter
      conventions.**~~ **Done 2026-08-16.** CLAUDE.md's View bullet now reads
      `builder: (controller)` / `controller.fieldName`, with the reason
      stated inline. No ported code used the old form. `dispose: (_)` and
      `catch (_)` are unaffected — those arguments are genuinely unused, and
      a wildcard is correct there.

- [x] ~~**Move the test database off SQLite for spatial tests.**~~
      **Done 2026-08-16.** Tests run against `marketplace_testing` on MariaDB;
      the SQLite driver guard is removed and the schema is identical in test
      and dev. Suite went 5.84s → 8.16s. Note the one-time setup step:
      `CREATE DATABASE marketplace_testing CHARACTER SET utf8mb4 COLLATE
      utf8mb4_unicode_ci;`

- [ ] **Add the remaining tables SPEC needs that CLAUDE.md's entity list
      omitted:** `support_tickets` (§5.15), admin roles/permissions (§5.16),
      plus `favorites`, `reports` and `device_tokens` for FCM.
      *(`settings` and `cms_pages` were added 2026-08-16.)*

- [ ] **`reports.unique(customer_id, vendor_id)` is "one report ever," not
      "one open report."** There's no status field yet to distinguish a
      resolved report from a pending one, so a customer with a genuinely
      new complaint about a vendor they already reported (and which was
      since resolved) has no way to submit it — the unique pair silently
      no-ops the second attempt. Phase 6 (Support Tickets / report
      lifecycle) needs to revisit this constraint once resolution status
      exists — likely dropping the unique pair in favor of one keyed to
      "no unresolved report exists yet" instead.
      *(Found in the favorites/share/report-vendor/account-deletion task,
      2026-08-24.)*

- [ ] **Set up iOS flavour schemes on a Mac.** Android has three product
      flavours; iOS has only the default `Runner` scheme, so
      `flutter run --flavor <name>` fails on iOS. Needs one Xcode scheme plus
      matching build configurations per flavour, and cannot be done on the
      Windows machine this project is developed on. Blocks any iOS build or
      App Store submission of the salesman and vendor apps.
      *(Found in task 0.5b.)*

- [ ] **Migrate uploaded files from the local public disk to Cloudflare R2.**
      CLAUDE.md specifies S3 driver → R2, but no credentials exist yet, so
      uploads currently land on the local `public` disk via `storage:link`.

      **This is a data migration, not a config swap.** Flipping
      `FILESYSTEM_DISK` only changes where *new* files go — every file already
      written has to be physically copied to the bucket, and every stored path
      in the database has to be updated to match. Miss either half and the
      admin panel and all three apps render broken images against paths that
      no longer resolve.

      Affected so far, and growing:

      | Source | Path column | Disk column | Since |
      |---|---|---|---|
      | Category icons | `categories.icon` | `categories.disk` | Phase 1 |
      | Subcategory icons | `subcategories.icon` | `subcategories.disk` | Phase 1 |
      | Vendor KYC | `vendors.shop_photo_path`, `vendors.id_proof_path` | `vendors.disk` | Phase 3 |
      | Vendor portfolio | `media.path` | `media.disk` | Phase 4 |
      | Banner images | `banners.image_path` | `banners.disk` | Phase 6 |

      Doing it sooner is strictly cheaper — Phase 4 portfolio uploads are
      photos *and videos* per vendor, so the volume to move grows fast once
      vendors start onboarding.

      **Every one of these tables now carries a `disk` column** (added
      2026-08-16), so the migration can proceed row at a time instead of
      all-or-nothing, and files on R2 can coexist with files still local
      while it runs.

      **Proposed command shape — record only, do not build until the
      credentials exist.** An idempotent artisan command, one row at a time:

      1. Select rows whose `disk` is not yet the R2 disk and whose path is
         not null. Rows already migrated are skipped, so the command is
         re-runnable by construction.
      2. **Stream** the file from its current disk to R2 rather than reading
         it into memory — portfolio videos will not fit comfortably otherwise.
      3. **Verify the object exists on R2, and that its size matches the
         source, before touching the database.** This ordering is the whole
         point: a row must never claim R2 until the object is provably there.
      4. Update that row's `disk` and `path` **inside a transaction**, so a
         crash mid-write cannot leave a half-updated row.
      5. Leave the source file in place. Deleting is a separate, later pass
         once the migration has been verified — an interrupted run must never
         be the reason a file no longer exists anywhere.

      Because state lives in each row's `disk` value, an interrupted run is
      resumed simply by running it again: no manual reasoning about how far it
      got, no separate progress ledger to keep in sync.

      ~~Also fix while there: `media.disk` defaults to `'s3'` in the
      schema.~~ **Fixed 2026-08-16** — the `'s3'` default is gone and the
      column is nullable with no default, matching the other four tables.
      Nothing is on S3 or R2, so that default would have stamped the first
      Phase 4 upload with a location the file was never in.

      `media` is the one table here **not** yet using `TracksFileDisk` — it
      has no upload logic to hook into until Phase 4. **When Phase 4 builds
      vendor portfolio uploads, apply the trait to the Media model** (its
      path column is `path`, not `icon`, so override
      `fileDiskPathColumn()`), otherwise media rows will be written with a
      null disk and be invisible to the migration command above.
      *(Found in Phase 1 category CRUD, 2026-08-16.)*

- [ ] **Redraw the zone polygons through the admin map before launch — the
      seeded boxes are approximate centroids with gaps, not tiled real
      boundaries.** `ZoneSeeder` places each of the 15 Ahmedabad sub-zones as
      a ~0.01° (roughly 1.1 km) square around an approximate centre. Real
      sub-zones adjoin and tile the city; these do not.

      **The failure is silent and customer-facing.** A customer standing in a
      gap between two boxes is inside no polygon, so `ST_Contains` matches
      nothing and they fall through to the pincode path — or see "We're not
      in your area yet" (SPEC §4.2) while standing in the middle of a covered
      city. Vendors paying for that zone receive no leads and have no way to
      tell why. Nothing errors; the coverage map simply has holes.

      When redrawing, keep the two properties the seeded set was built to
      hold, both covered by tests in `SeederTest`:

      - **No two sub-zones may overlap.** A point inside two polygons matches
        one customer to two zones, duplicating vendors in search results and
        making the lead records ambiguous.
      - **The parent polygon must enclose every child**, so Ahmedabad remains
        a meaningful grouping.

      **If the redraw changes the number of sub-zones, Platinum's
      `max_zones = 15` must be revisited in the same PR.** It is calibrated to
      mean "all of Ahmedabad", not chosen as a round number, and
      `SeederTest::test_platinum_covers_the_whole_seeded_catalogue()` asserts
      that equality. So a zone-count change on its own will fail a test whose
      name and location look **entirely unrelated to map work** — someone
      redrawing boundaries would reasonably assume they had broken the plan
      seeder, not that the two are coupled by design.

      Splitting them across PRs leaves `main` red on a failure nobody
      associates with the change that caused it. Update
      `PlanSeeder::PLANS['Platinum']['quota']['max_zones']` alongside the new
      polygons.
      *(Found in Phase 1 master data seeders, 2026-08-16.)*

- [ ] **Switch `MAP_TILE_URL` away from OpenStreetMap's free public tile
      server before real production traffic.** The admin zone-drawing map
      defaults to `tile.openstreetmap.org`, whose usage policy explicitly does
      not cover sustained production use — heavy use risks being blocked, and
      a blocked tile server means admins draw zone boundaries onto a blank
      grey canvas with no roads or landmarks to align against.

      Leaflet itself is vendored locally, so **only the tiles are remote**.
      The URL and attribution are read from `config/map.php` via
      `MAP_TILE_URL` / `MAP_TILE_ATTRIBUTION` specifically so this swap is a
      config change, not a code change — point them at a paid provider
      (Mapbox, MapTiler, Thunderforest) or a self-hosted server. Update the
      attribution text at the same time; most providers require their own.
      *(Found in Phase 1 zone CRUD, 2026-08-16.)*

- [ ] **Bundle the DM Sans font files.** `FontFamily.dmSans` names the family
      but no assets are registered, so both the app and any screenshot render
      in the platform default.
      *(Found in task 0.5a.)*

- [ ] **Confirm `ADMIN_EMAIL` / `ADMIN_PASSWORD` are strong and unique in
      production before deploy.** Never reuse the local development
      credentials. `AdminUserSeeder` falls back to a weak default when the
      env vars are unset, so unset vars in production silently produce a
      guessable admin account. Note `updateOrCreate()` matches on
      `ADMIN_EMAIL`, so changing that value creates a *second* admin rather
      than renaming the first.
      *(Found in task 0.4.)*

- [ ] **Add real client-side video compression for portfolio uploads.**
      SPEC section 3 item 5 asks for photos/videos "compressed client-side
      before upload" — photos get it (`flutter_image_compress`), videos
      don't. Real transcoding needs either a native-encoder wrapper
      (`video_compress` — unmaintained) or an FFmpeg wrapper
      (`ffmpeg_kit_flutter` — upstream archived in a 2025 licensing
      dispute), neither safe to add today. Current fallback:
      `pickVideo(maxDuration: 60s)` at capture, plus a 50 MB size cap
      enforced both client-side (fast local rejection,
      `VendorPortfolioController.isVideoTooLarge()`) and server-side (the
      actual authority, `StorePortfolioMediaRequest`). Revisit once a
      maintained Flutter transcoding option exists, or move compression
      server-side (a queued job calling a hosted transcoding service).
      *(Found in task 4.5.)*

- [ ] **Create a real Firebase project and set `FCM_PROJECT_ID`/
      `FCM_CLIENT_EMAIL`/`FCM_PRIVATE_KEY_BASE64` from a real service
      account.** Same situation as R2: no credentials exist in this dev
      environment, so `FcmClient` has never made a real call to FCM.
      Unlike R2's silent local-disk fallback, a blank/missing FCM
      credential fails **loudly** — `FcmClient::requireConfig()` throws
      the moment a push is attempted — and `FcmChannel` catches that
      per-device, so it shows up as `failed_count` on the
      `notifications` dispatch row rather than crashing the caller.
      Confirmed live: recording a lead with no FCM credentials
      configured still returns 201, with a `failed_count = 1` row to
      show for it. Nothing else needs to change once real credentials
      exist — nothing to migrate, no code path split on "is FCM
      configured yet."

      **Also not built in this task, and blocked on the same missing
      Firebase project**: the Flutter-side FCM SDK integration — each
      of the 3 apps requesting push permission, obtaining its own
      device token, and calling `POST /api/device-tokens` on login/app
      start (and `DELETE /api/device-tokens` on logout). Needs real
      `google-services.json`/`GoogleService-Info.plist` from the same
      project. The backend registration/unregistration endpoints exist
      and are tested — there is simply no Flutter caller yet.
      *(Found in task 7.2.)*

---

## Open Questions — resolve before the phase that needs them

Standing list, like the checklist above — **not** a log entry. Design
decisions recorded at the point they were noticed, so they are not re-derived
(or worse, silently answered by whatever the first implementation happens to
do). Resolved entries stay here, pointing at wherever the answer now lives.

### ✅ Phase 5 (`ZoneMatcher`) — zone hierarchy: all resolved

~~Two unresolved questions about zone hierarchy.~~ **Resolved 2026-08-16 and
written into SPEC §8**, which is now the authority — this entry is a pointer,
not a second source of truth.

**Q1 — Does matching ever test a parent zone's polygon directly?**
**No — leaf-only matching.** Only zones with no children participate in
`ST_Contains` or count against quota. Parent zones exist for navigation and
grouping; their polygon is still required (§11) but never matched. The
reasoning is now recorded rather than verbal: matching a parent would let one
selection substitute for its children, breaking quota fairness, and would
return duplicate vendor matches, since a point inside a child is also inside
its parent.

**Q2 — Does deactivating a parent cascade to its children?**
**No physical cascade.** A leaf zone's effective status is computed at match
time as `zone.is_active AND (zone.parent_id IS NULL OR parent.is_active)`,
never written to the child row. This resolves the draft-workflow conflict
flagged when the question was raised: a sub-zone can be created and
individually activated while its parent city is still a draft (§11) — it
simply is not matchable until the parent goes active too.

#### Applied already — Phase 5 inherits these, does not build them

- **The "in use" count is transitive.** `Zone::countSubscriptionReferences()`
  now includes every descendant via `descendantIds()`, the same pattern
  Category uses for its subcategories. SPEC §8 requires this because
  deactivating a busy parent silently drops its whole subtree out of
  matching — a count reading only the parent's own row would show "Not used"
  while the toggle broke coverage for every vendor beneath it. The admin badge
  tooltip changes wording on a parent to say so.
- **The deletion guard is transitive too**, so deleting a parent is no longer
  a way around a child's guard.
- `Zone::isLeaf()` exists for Phase 5 to filter on.

#### Still for Phase 5 to build

`ZoneMatcher` itself — the leaf-only filter and the effective-active
expression above, applied in the matching query. Nothing in Phase 1 applies
them to matching, because there is no matching yet.

#### ✅ The "what counts as a leaf" ambiguity — also resolved

~~SPEC §8's two definitions of "leaf" diverged for a top-level zone with no
sub-zones.~~ **Corrected in SPEC §8, 2026-08-16.** The definition now reads:

> a zone is a "leaf" if no other zone references it as a parent — i.e. it has
> no children, regardless of whether it has a parent itself.

with both edge cases stated outright: a standalone top-level city with no
sub-zones **is** a leaf and matchable; a mid-tier zone that has its own
children **is not**, even though it has a parent.

`Zone::isLeaf()` already implemented exactly this —
`! $this->children()->exists()`, where `children()` is
`hasMany(Zone::class, 'parent_id')`, which is literally "no other zone
references it as parent". **Documentation-only fix; no code changed.**

*(Raised during Phase 1 zone CRUD; resolved by SPEC §8 the same day.)*

---

#### ✅ The depth divergence — also resolved, by constraint

~~The two §8 rules span different depths: effective-active checks one level
up, while the "in use" count is fully transitive. At three levels they would
disagree.~~ **Resolved 2026-08-16 by capping the hierarchy at two levels**
(city → sub-zone), now written into SPEC §8.

The fix removes the bug class rather than managing it: **at exactly two
levels, "effective active checks one level up" and "in use counts every
descendant transitively" are the same statement**, so they cannot diverge.
SPEC notes nothing in the platform needs a third tier — there is no
state/region grouping anywhere in the vendor, salesman or customer flows.

**Enforced in `ZoneResource`**, both directions:

- The parent dropdown offers **only top-level zones**, and a validation rule
  rejects a `parent_id` whose target already has a parent — the forward case
  SPEC names.
- A zone that **already has children cannot be given a parent** — the same
  three-level tree built from the other end. SPEC only names the forward
  case; leaving this open would let an admin reach depth 3 by editing instead
  of creating.

The dropdown filter is UX; the validation rules are the enforcement, since a
Livewire request can carry any id regardless of what the dropdown offered.

The existing "in use" tooltip needed no change — the depth cap makes its
wording accurate.

**Scope note:** enforcement is in the panel, not the schema. `parent_id` is
still a plain self-referencing FK, so a seeder, console command or future API
route could still build depth 3. That is the same shape as the delete guard,
where the model-level `PreventsDeletionWhileSubscribed` backs up the UI — if
zones ever gain a non-panel write path, the depth check should move to the
model alongside it.

*(Noticed while reading the corrected §8; resolved the same day.)*

- [ ] **Add a map preview to vendor/salesman zone selection.** Checked
      2026-08-23 while confirming task 4.4's zone selection was actually
      complete (it is — see that entry) — a visual map confirmation of
      selected zones does not exist anywhere; both
      `vendor_select_services_view.dart` and the salesman-flow
      `select_services_view.dart` are checkbox-list-only, with a live
      "X of Y selected" counter but no polygon rendering. Not a
      functional gap (the counter and checkboxes already confirm the
      selection unambiguously) — a nice-to-have, deferred rather than
      built now since it's a real feature addition, not a quick add:
      needs a new Flutter map package (`flutter_map` or
      `google_maps_flutter`, neither currently a dependency), the
      backend exposing zone polygons to the vendor-facing endpoint
      (`Zone::scopeWithPolygonWkt()` exists but isn't applied there
      today — it's currently admin-only, via `PolygonMap`'s Leaflet
      field), and a WKT/GeoJSON parser on the Flutter side. Worth its
      own scoped task if ever prioritized, not a bolt-on.
      *(Found while closing out the task 4.4 zone-selection re-check.)*

---

## 2026-08-16 — Phase 0, task 0.1: Init Laravel app

**Status:** Complete, verified end to end.

### Delivered

Laravel app scaffolded in `backend/`, connected to the local XAMPP MariaDB
instance, with Sanctum installed and a working token issued and revoked as a
smoke test.

- **Framework:** Laravel **12.66.0** (see deviation below — not 11)
- **Database:** MariaDB 10.4.32 on `127.0.0.1:3306`, database `marketplace`
  created `utf8mb4` / `utf8mb4_unicode_ci`, user `root`, blank password
- **Auth:** Laravel Sanctum v4.3.3, `personal_access_tokens` migrated
- **Composer 2.10.2** installed (was missing entirely)

### Files added or changed

| Path | What |
|---|---|
| `backend/app/Enums/UserRole.php` | Backed enum: `admin` / `salesman` / `vendor` / `customer` |
| `backend/app/Http/Responses/ApiResponse.php` | `success()` / `paginated()` / `error()` — the `{ success, data, error }` envelope |
| `backend/bootstrap/app.php` | Exception renderers keeping API validation / auth / 404 failures inside the envelope |
| `backend/app/Models/User.php` | `HasApiTokens` + `SoftDeletes`; `role` fillable and cast to `UserRole` |
| `backend/database/migrations/*_add_role_and_soft_deletes_to_users_table.php` | `role` enum (indexed, default `customer`) + `deleted_at` |
| `backend/app/Services/` | Created per the architecture rules; empty so far |
| `backend/.env`, `backend/.env.example` | MySQL connection, `APP_TIMEZONE=UTC`, `APP_NAME` |
| `CLAUDE.md` | Renamed from `CLAUDE (1).md` so it loads as project memory |

### Verification performed

- `php artisan migrate` clean against `marketplace` — 10 tables
- `GET /up` → 200
- Unauthenticated `GET /api/user` → `{"success":false,...,"code":"UNAUTHENTICATED"}`
- Created a user, issued a Sanctum token, authenticated over HTTP with
  `Authorization: Bearer`, received the user payload
- Smoke-test user force-deleted and `personal_access_tokens` truncated —
  database left clean (`users=0 tokens=0`)

### Deviation from plan: Laravel 12, not 11

BUILD_PLAN 0.1 and CLAUDE.md both specify Laravel 11. Composer refused to
install it: every 11.x release from 11.31.0 to 11.55.1 (the latest) carries
published advisories, and two have **no 11.x backport** because 11.x is past
its security-support window —

- **CRLF injection in the default `email` validation rule** (high, fixed in
  12.60.0) — every role uses email+password auth and vendors/customers
  self-register, so this rule runs on every signup
- **Temporary signed URL path confusion** (medium, fixed in 12.61.1) — vendor
  email verification per SPEC §3.1 uses signed URLs

Installed 12.66.0, which clears both. Filament v3.3.54 declares
`illuminate/support: ^10.45|^11.0|^12.0|^13.0`, so the Filament v3 requirement
in CLAUDE.md is unaffected. Approved by the user during the session.

> ~~**CLAUDE.md line 10 still reads "Laravel 11" and was deliberately left
> unedited.**~~ **Resolved 2026-08-16** — CLAUDE.md line 10 now reads
> "Laravel 12". BUILD_PLAN.md still says Laravel 11 in the 0.1 prompt, but it
> is a historical planning document and was left as written.

### Open items for later phases

- **MariaDB ignores SRID.** SPEC §8 specifies `POLYGON` at SRID 4326 with
  `ST_Contains`. MariaDB 10.4 parses and stores the SRID, then computes on a
  flat Cartesian plane. Zone matching will work locally, but this diverges
  from MySQL 8 behaviour — revisit when building `ZoneMatcher` (Phase 5).
- **`php artisan db:show` errors** with a missing `performance_schema` table.
  Cosmetic; XAMPP ships `performance_schema` disabled. The connection is fine.
- **A second, running copy of this project exists** at
  `C:\Users\Dell\Desktop\service-marketplace` — a Laravel app serving on port
  8000 plus an `admin_panel/` with esbuild. Untouched by this session. Worth
  confirming which tree is authoritative before Phase 1.
- **Composer PATH** was set for new processes only; existing terminals need a
  restart. A stale `composer` 2.9.5 phar that shadowed the new install was
  renamed to `composer-2.9.5.phar.bak`.
- A superseded copy of `CLAUDE.md` remains in `Downloads/` containing the
  **opposite** backend architecture rules (no repositories/services). Left in
  place, but it is a trap if it ever gets copied over the project one.

### Next

Task 0.2 — auth: register / login / logout, bcrypt, per-device Sanctum tokens,
`RolesMiddleware` reading the `role` column (already migrated), and login rate
limiting at 5 attempts / 15 min.

---

## 2026-08-16 — Phase 0, task 0.2: Auth module

**Status:** Complete. 29 feature tests passing, plus live HTTP verification
against MariaDB.

### Delivered

| Route | Middleware | Returns |
|---|---|---|
| `POST /api/auth/register` | `throttle:register` (10/hour/IP) | `user` + `token`, 201 |
| `POST /api/auth/login` | `throttle:login` (5 / 15 min) | `user` + `token` |
| `POST /api/auth/logout` | `auth:sanctum` | `null` |

All responses use the `{ success, data, error }` envelope. `GET /api/user` was
converted from the scaffold stub to the envelope + `UserResource`.

### Files added or changed

| Path | What |
|---|---|
| `app/Http/Controllers/Api/AuthController.php` | register / login / logout |
| `app/Http/Requests/Auth/RegisterRequest.php` | **Role restricted to `vendor` / `customer`** |
| `app/Http/Requests/Auth/LoginRequest.php` | email, password, `device_name` |
| `app/Http/Middleware/RolesMiddleware.php` | `role:admin,salesman` — 403 in envelope |
| `app/Http/Resources/UserResource.php` | Field allow-list; never exposes `password` / `deleted_at` |
| `app/Providers/AppServiceProvider.php` | Named `login` + `register` rate limiters |
| `bootstrap/app.php` | `role` middleware alias; 429 envelope renderer |
| `routes/api.php` | `/auth` route group |
| `database/factories/UserFactory.php` | `role` attribute + `role()` state helper |
| `tests/Feature/Auth/{Register,Login,Logout,RolesMiddleware}Test.php` | 29 tests |

### Security decisions worth remembering

- **Self-registration cannot create admin or salesman accounts.** SPEC §1
  requires it; without the rule, `POST /api/auth/register` with `role=admin`
  hands out the admin panel. Two tests lock this down.
- **Login does not leak which emails have accounts.** `Hash::check` runs even
  when no user matched, so a missing account and a wrong password return an
  identical 401 in comparable time.
- **Throttle keyed on email + IP.** IP alone lets one attacker behind a shared
  NAT lock out everyone on it; email alone lets an attacker lock out a known
  victim at will.
- **One token per device.** Re-authenticating on a device deletes that
  device's old token first; logout revokes only `currentAccessToken()`, so
  other devices stay signed in.
- **Tokens carry a `role:<role>` ability**, per the Sanctum token abilities
  line in CLAUDE.md.
- **Soft-deleted users cannot log in** (the `SoftDeletes` global scope) and
  their email cannot be re-registered — the unique index still holds the row,
  so `RegisterRequest` deliberately does *not* use `withoutTrashed()`.

### Verification performed

- `php artisan test --testsuite=Feature` — **29 passed, 96 assertions**
- Live HTTP against MariaDB: register → login → authenticated `GET /api/user`
  → logout → `401` on the revoked token
- Live: `role=admin` registration rejected 422 with the custom message
- Live: throttle returns 429 in the envelope; a different email from the same
  IP is unaffected
- Test users and tokens removed afterwards (`users=0 tokens=0`)

### Gotchas found

- ~~**The throttle counts successful logins too.**~~ **Superseded later the
  same day** — throttle middleware counts every request, not just failures, so
  a user signing in legitimately across several devices burned the same
  5-per-15-min budget. Replaced with Fortify-style failure-only counting; see
  the amendment below.
- **Auth guard caching in tests.** Laravel caches the resolved user on the
  guard across requests inside one test, so a revoked token appears to keep
  working. `LogoutTest` calls `$this->app['auth']->forgetGuards()` to force a
  re-read. Real HTTP was verified separately and behaves correctly — worth
  remembering for any future token-revocation test.

### Still open

- **Email verification is not enforced.** Register sets
  `email_verified_at = null`, but login is *not* gated on it yet. SPEC §3.1
  requires it for self-registered vendors — that is task 0.3.
- **No vendor/customer profile rows.** Register creates the `users` row only;
  the `vendors` table arrives in Phase 1, along with the
  `Pending Verification` status from SPEC §7.
- **Re-registration after account deletion** (SPEC §4.10, app-store
  requirement) — resolved in design, see the Phase 5 / task 5.6 note below.
  Nothing to build until then.
- Items carried over from 0.1 still open: MariaDB ignores SRID; duplicate
  project tree on the Desktop. *(The "Laravel 11" line in CLAUDE.md was fixed
  on 2026-08-16.)*

### Next

Task 0.3 — password reset + email verification wired to Resend.

---

## 2026-08-16 — Amendment to 0.2: failure-only login throttling

**Status:** Complete. 34 feature tests passing, plus live HTTP verification.

Replaced the `throttle:login` middleware with a Fortify-style limiter applied
inside `AuthController::login`. The middleware counted *every* login request,
so successful sign-ins consumed the same budget as brute-force attempts — a
user signing in on phone, tablet, and laptop could lock themselves out.

**How it works now:**

- `RateLimiter::tooManyAttempts()` checked before credentials are verified
- `RateLimiter::hit()` **only** on a failed attempt, 15-minute decay
- `RateLimiter::clear()` on success, so a valid sign-in wipes earlier failures
- Still keyed on `email + IP`, unchanged
- Limits are named constants (`MAX_LOGIN_ATTEMPTS`, `LOGIN_DECAY_SECONDS`)
- The 429 body now reports how many seconds remain

The `login` named limiter was removed from `AppServiceProvider` (it is no
longer reachable); the `register` limiter still uses throttle middleware,
which is correct there — every signup request should count.

**Behaviour worth keeping in mind:** once locked out, even the *correct*
password returns 429 until the window expires. That is deliberate — otherwise
an attacker who guesses right on the sixth try walks straight through the
limiter. There is a test for it.

**Verification:**

- `php artisan test --testsuite=Feature` — **34 passed, 134 assertions**
- Live HTTP: 8 consecutive *successful* logins on one email+IP → all 200, no
  budget consumed; then 5 failures → 401, 6th → 429 with a ~898-second
  retry hint; correct password during lockout → still 429
- Test data removed afterwards (`users=0 tokens=0`)

New tests: successful logins never consume budget, a success clears earlier
failures, lockout blocks the correct password, unknown emails still count, and
the 429 reports a retry time.

---

## 2026-08-16 — Phase 0, task 0.3: Password reset + email verification

**Status:** Complete. 65 feature tests passing, plus a full live run through
`storage/logs/laravel.log`.

### Delivered

| Route | Middleware | Purpose |
|---|---|---|
| `POST /api/auth/forgot-password` | `throttle:password-email` | Emails a reset link |
| `POST /api/auth/reset-password` | `throttle:password-email` | Consumes token, sets password |
| `GET /api/auth/verify-email/{id}/{hash}` | `signed`, `throttle:verification` | Named `verification.verify` |
| `POST /api/auth/resend-verification` | `throttle:verification` | Re-sends the link |

- `resend/resend-php` v1.9.0 installed — Laravel 12 already ships the
  `resend` transport in core, and `config/mail.php` / `config/services.php`
  already had the blocks. Only the client library was missing.
- `.env` keeps `MAIL_MAILER=log`; switching to Resend is two env values
  (`MAIL_MAILER=resend`, `RESEND_API_KEY=...`) with **no code change**.
- `config/auth.php`: reset token expiry **60 → 15 minutes**. Single use is
  Laravel's default (the broker deletes the row on success).
- `User implements MustVerifyEmail`.
- New `FRONTEND_URL` env var (`config('app.frontend_url')`) controls where
  emailed links point, falling back to `APP_URL`.

### The login gate

`User::requiresEmailVerification()` is the single seam holding the rule:

- **Vendors** with `email_verified_at = null` are blocked → `403`
  `EMAIL_NOT_VERIFIED` (SPEC §3.1, §7)
- **Customers** are not blocked. SPEC §4.1 asks only for email + password
  self-registration and states no verification requirement; they still
  receive the email and can verify.
- **Salesman / admin** accounts are never gated — created in person.
- Checked **after** the password is verified, so it never tells an
  unauthenticated caller that an account exists.

`hasSalesmanAssignedActiveSubscription()` is a documented stub returning
false, with the exact replacement query in a docblock. The `subscriptions`
table arrives in Phase 1 and salesman-sold subscriptions in Phase 3 —
until then no vendor can have one, so false is correct today.

### Two holes found while building, and closed

1. **Register handed out a token, which walked straight past the gate.**
   Registering as a vendor returned a working Sanctum token, so the login
   block was decorative. Vendor registration now returns `token: null` plus
   a "verify your email" message; customers still get a token. Two tests
   pin this.
2. **`resend-verification` could not require authentication.** A vendor
   blocked from logging in has no token, so an authenticated-only resend
   endpoint would leave them permanently stuck. It is deliberately
   unauthenticated, accepts an email in the body, uses the authenticated
   user when present, and returns an identical response whether or not the
   account exists.

### Security decisions

- `forgot-password` and `resend-verification` return the **same response for
  known and unknown addresses** — no account enumeration, matching login.
- **Resetting a password revokes every existing device token.** A reset is
  the standard response to a suspected compromise, so stale devices must not
  survive it.
- Verification checks `hash_equals` against `sha1(email)` on top of the
  `signed` middleware, so a link stops working if the address changes.
- Reset and verification mail are rate limited (6/hour on email + IP) so
  neither endpoint can be used to flood a third party's inbox.

### Verification performed

- `php artisan test --testsuite=Feature` — **65 passed, 208 assertions**
  (was 34). New: `PasswordResetTest`, `EmailVerificationTest`,
  `LoginVerificationGateTest`.
- Live, with `MAIL_MAILER=log`: registered a vendor (`token: null`) → login
  blocked `403 EMAIL_NOT_VERIFIED` → **pulled the signed link out of
  `storage/logs/laravel.log` and GET it** → verified → login `200`.
  Tampered signature → `403`.
- Live reset: `forgot-password` → link from the log → reset `200` → same
  token reused → `422 INVALID_RESET_TOKEN` → new password logs in `200`,
  old password `401`.
- Email copy renders **"expire in 15 minutes"**, read from the config change.
- `MAIL_MAILER=resend` resolves its transport config and `Resend` class
  loads (no live key needed to confirm wiring).
- Test data and the log cleared afterwards.

### Note on the brief

The instruction cited CLAUDE.md for "15 minutes, single use" — CLAUDE.md did
not actually contain that rule. **Resolved 2026-08-16:** both expiry rules are
now written into CLAUDE.md under "Conventions — API (Laravel)".

### Still open

- **`email:rfc` only, not `email:rfc,dns`.** DNS validation was left off so
  tests and local dev work offline. Consider enabling in production.
- ~~Verification links expire after 60 minutes~~ — **changed to 24 hours the
  same day, see amendment below.**
- Carried over: MariaDB ignores SRID; duplicate project tree on the Desktop.

### Next

Task 0.4 — install Filament v3, admin panel with dark mode, login restricted
to `role=admin`.

---

## 2026-08-16 — Amendment to 0.3: 24-hour verification links, rules into CLAUDE.md

**Status:** Complete. 69 feature tests passing, confirmed against a real
rendered email.

**Verification link expiry 60 minutes → 24 hours.** `config/auth.php` had no
`verification` block at all, so Laravel was silently falling back to its
hardcoded 60. The key is now set explicitly (`'verification' => ['expire' =>
60 * 24]`) so the rule is visible rather than inherited.

**Email copy.** Laravel's stock `VerifyEmail` notification says *nothing*
about expiry — there was no "60 minutes" wording to replace, only an absence.
Added a line via `VerifyEmail::toMailUsing()`, with the wording **derived from
the config** so the sentence and the actual window cannot drift apart.

**Both expiry rules are now in CLAUDE.md** under "Conventions — API
(Laravel)", alongside the envelope and idempotency rules:

- Password reset tokens expire in 15 minutes, single use
- Email verification links expire in 24 hours

### Bug caught during this change

The first version of the copy used `Str::plural('hour', $count, true)`. That
count-prefix mode routes through `Number::format`, which **hard-requires the
`intl` PHP extension — not loaded on this XAMPP/winget PHP**. It threw a
`RuntimeException` at render time.

Every existing verification test passed anyway, because `Notification::fake()`
never calls `toMail()`. Only the test that builds the mail for real caught it.
**Lesson for later phases: a faked notification proves the dispatch, not the
render.** At least one test per mailable should build the message.

Fixed by composing the string by hand (`intdiv` + two-argument
`Str::plural`), which touches no intl code path.

### Verification performed

- `php artisan test --testsuite=Feature` — **69 passed, 213 assertions**
- New tests: expiry is 24 h in config; link valid at 23 h; rejected at
  24 h + 1 min; a link at 61 minutes (dead under the old window) still works;
  the email states the 24-hour window
- Live, `MAIL_MAILER=log`: registered a vendor and read the real email out of
  `storage/logs/laravel.log` — renders "This verification link will expire in
  24 hours." with no intl exception, and the signed URL's `expires` parameter
  is **24.0 hours** ahead
- Test data and log cleared afterwards

---

## 2026-08-16 — Phase 0, task 0.4: Filament v3 admin panel

**Status:** Complete. 76 feature tests passing, panel verified live.

### Delivered

- **Filament v3.3.54** + Livewire 3, panel at `/admin`, login screen only —
  no resources, those start in Phase 1.
- `AdminPanelProvider`: dark mode default, teal accent, slate gray ramp,
  brand name "Service Marketplace".
- `User implements FilamentUser` with `canAccessPanel()` returning
  `role === UserRole::Admin`.
- `AdminUserSeeder` — creates the first admin, since self-registration
  rejects the admin role by design and `make:filament-user` would leave
  `role` at its `customer` default.

### `intl` had to be enabled first

Filament v3.3.53+ **hard-requires `ext-intl`**, and the only older release
without that requirement (v3.3.52) is blocked by security advisory
PKSA-n7tx-gkfb-14yj on `filament/forms`. So the extension flagged as a
formatting nicety in 0.3 turned out to be a hard install blocker.

`extension=intl` uncommented in **both** php.ini files (winget line 944,
XAMPP line 928), each backed up as `php.ini.bak-intl`. ICU 72.1 now loads in
both. `Number::currency(1234.50, 'INR')` returns `₹1,234.50`, so the
`Number::` preference from the previous session is now actually usable.

### Theme

`->defaultThemeMode(ThemeMode::Dark)` — dark by default but still
switchable, so an admin working in daylight is not stuck with it. Verified in
the rendered page:

| Token | Value | Note |
|---|---|---|
| `--primary-400` | `45, 212, 191` | teal-400 `#2dd4bf` |
| `--gray-900` | `15, 23, 42` | slate-900 `#0f172a` — surfaces |
| `--gray-950` | `2, 6, 23` | slate-950 `#020617` — page, **not pure black** |

Teal deliberately stays clear of the red/amber Filament reserves for
destructive and warning states. No custom Tailwind theme was registered, so
there is **no npm build step** — colours come from the panel provider exactly
as CLAUDE.md specifies.

### RolesMiddleware now content-negotiates

It previously always returned the JSON envelope, which would have rendered as
raw JSON in a browser. It now serves the envelope for `api/*` and
`expectsJson()` requests, and a normal HTML error page otherwise — which let
it be reused as `role:admin` in the panel's `authMiddleware` alongside
`canAccessPanel()`. Tests cover both branches.

Note `canAccessPanel()` is not optional: without the `FilamentUser` contract,
Filament admits **any authenticated user** to the panel while `APP_ENV=local`.

### Verification performed

- `php artisan test --testsuite=Feature` — **76 passed, 229 assertions**
- New `PanelAccessTest`: guest redirected to `/admin/login`; admin gets 200;
  salesman / vendor / customer all 403; `canAccessPanel()` true only for
  admin; panel denial returns HTML not JSON; API envelope still intact
- Live: `/admin` → 302 to `/admin/login`; `/admin/login` → 200 with the
  email / password / remember fields and the teal + slate tokens above
- Seeded admin authenticates and passes `canAccessPanel()`

### Security note — admin password

~~The seeder fell back to its development default (`password`).~~
**Resolved 2026-08-16:** `ADMIN_EMAIL` / `ADMIN_PASSWORD` are now set
explicitly in `.env` to a generated 24-character password, and the seeder was
re-run. Verified it updated the existing row in place — same user id, still
exactly one admin, old password rejected.

Production still needs its own credentials — see the **Before Launch
Checklist** at the top of this file.

### Still open

- Panel login rate limiting — moved to the **Before Launch Checklist**.
- Carried over: MariaDB ignores SRID; duplicate project tree on the Desktop.

### Next

Task 0.5a — port the Flutter shared foundation from the demo app.

---

## 2026-08-16 — Phase 0, task 0.5a: Flutter shared foundation

**Status:** Complete. `flutter analyze` clean, 10 tests passing.

### Source

The two reference files were **not attached to the session**. The real
demo-app was found on disk at `C:\Users\Dell\Desktop\demo-app`, with a
byte-identical copy at `C:\Users\Dell\Downloads\demo-app\demo-app` — so the
foundation was ported from the actual source rather than reconstructed from
description. Its imports are `package:fanni/...`, matching CLAUDE.md's
description of the demo app.

Worth knowing: **the demo-app does not compile as shipped.** `pubspec.yaml`
says `name: demo` while every import says `package:fanni/`. Both copies have
this. Our port uses one consistent name, `service_marketplace`.

### Delivered — mobile/

Flutter 3.38.6 / Dart 3.10.7. `flutter create --platforms=android,ios`.

```
lib/
  common_model/  common_response.dart, user_model.dart
  constants/     app.export.dart, color_res.dart, constant.dart,
                 pref_keys.dart, string_res.dart
  network/       data_source.dart
  utils/         injector.dart, utils.dart
  widgets/       base_button.dart, base_text.dart, base_textfield.dart
  screens/auth_flow/create_account_module/  (ported reference)
  main.dart
assets/translations/en.json
```

Layout follows the paths given in the task, not the demo-app's own
(`res/`, `base_class/`, `constants/utils.dart`) — chosen deliberately.

Dependencies: `get`, `dio`, `shared_preferences`, `easy_localization`,
`fluttertoast`, `intl`. Nothing beyond what the foundation uses.

### Deviations, all forced

1. **`builder: (_) => _.field` no longer compiles.** Dart 3.7 made `_` a
   non-binding wildcard, so every `_.fieldName` is an "Undefined name '_'"
   error on Dart 3.10. The builder argument is named `controller` instead.
   *CLAUDE.md's Flutter conventions were corrected the same day to document
   the `builder: (controller)` form.*
2. **`constant.dart` is not exported from `app.export.dart`.** Its
   `IntExtension` collides with GetX's. Screens import it directly — which is
   exactly what the demo-app screens do.
3. **`tr(key)` not `key.tr()`.** GetX and easy_localization both define `tr`
   on String and neither wins. The demo-app uses the function form for the
   same reason.
4. **`textScaleFactor` → `textScaler`**, **`MaterialStateProperty` →
   `WidgetStateProperty`**. Both deprecated; constructor APIs unchanged.
5. **Shadowed fields dropped.** The demo-app's Base widgets redeclared fields
   `Text`/`TextFormField` already have. They are passed to `super` instead —
   same constructor API, no analyzer noise.

### Scope decisions

- **Utils trimmed** from 2,228 lines to what is used:
  `showCircularProgressLottie`, `transitionWithOffAll`, `authLayout`,
  `getSize`, `getFontSize`, `showToast`, asset-path helpers. The rest of the
  demo-app's Utils is image picking, webview, audio, in-app purchase and
  Firebase — a different product's dependency tree.
- **`showCircularProgressLottie` uses no Lottie.** Despite the name, the
  demo-app's implementation is a `CupertinoActivityIndicator`. The name is
  kept so ported screens compile; no Lottie asset or package is needed.
- **`authLayout`'s `blue_bg.png` header** is a teal→slate gradient instead.
  The asset is light-blue branding that does not exist here, and a gradient
  recolours with the palette rather than needing a new export.
- **`authLayout` now honours `onSkipTap`.** The demo-app accepted the
  parameter and ignored it, hardcoding its own landing page — unusable across
  three flavours that land on different screens.
- **DataSource points at the real API** (`auth/register`, `auth/login`,
  `auth/logout`, `auth/forgot-password`, `auth/reset-password`,
  `auth/resend-verification`, `user`). The demo-app's OTP endpoints are
  omitted: CLAUDE.md forbids OTP/phone auth.
- **`CommonResponse` maps this backend's envelope** — `status` ← `success`,
  `message` ← `error.message`, plus `errorCode` and per-field
  `fieldErrors`, while keeping the `.status` / `.data` / `.message`
  accessors ported screens use.
- **`UserModel` was not in the port list** but the reference controller needs
  it. It accepts either the wrapped `{user, token}` auth payload or a bare
  user object, and keeps the demo-app's `authentication.accessToken`
  accessor.

### Theme

`ColorRes` keeps the demo-app's member names (`primaryColor`,
`secondaryColor`, `grayColor`) so ported screens compile untouched; only the
values changed, to the CLAUDE.md palette. `secondaryColor` flipped meaning —
near-black body text in a light theme, near-white in a dark one. Button
labels use `backgroundColor` ink, because white on teal-400 fails contrast.

### Verification performed

- `flutter pub get` — resolved
- `flutter analyze` — **No issues found**
- `flutter test` — **10 passed**: envelope parsing for success / error /
  validation payloads, `UserModel` for both shapes, palette values match
  CLAUDE.md, background is not pure black, and the three Base widgets render
  and fire callbacks
- Not yet done: no on-device or emulator run. Task 0.5b's checkpoint is the
  ported module running under all three flavours.

### Still open

- **No font assets.** `FontFamily.dmSans` names DM Sans but no files are
  bundled, so Flutter falls back to the platform default. Renders correctly,
  off-brand.
- **No `main_*.dart` flavours yet** — task 0.5b.
- `DataSource.baseUrl` defaults to `http://10.0.2.2:8000/api/` (host machine
  from the Android emulator); override with
  `--dart-define=API_BASE_URL=...` for a physical device.

### Next

Task 0.5b — three flavours with `main_salesman.dart` / `main_vendor.dart` /
`main_customer.dart`, and the smoke test of the ported module under each.

---

## 2026-08-16 — Phase 0, task 0.5b: Flutter flavours

**Status:** Complete for Android, verified on a real emulator. **iOS flavours
are not configured** — see below.

### Delivered

| Flavour | applicationId | Launcher label | Entry point |
|---|---|---|---|
| salesman | `...service_marketplace.salesman` | Marketplace Sales | `lib/main_salesman.dart` |
| vendor | `...service_marketplace.vendor` | Marketplace Partner | `lib/main_vendor.dart` |
| customer | `...service_marketplace` | Service Marketplace | `lib/main_customer.dart` |

- `lib/constants/flavor_config.dart` — `Flavor` enum plus `FlavorConfig`
  (app name, backend role). Read as `FlavorConfig.current`; nothing branches
  on flavour by string.
- `lib/app.dart` — one `bootstrap(FlavorConfig)` shared by all three: sets the
  flavour, initialises easy_localization and `Injector`, then runs
  `GetMaterialApp` with the dark theme. Everything after the entry point is
  identical, so flavour drift is impossible by construction.
- Three `main_*.dart` files, each one line. `main.dart` still works and boots
  the customer flavour.
- `android/app/build.gradle.kts` — `flavorDimensions += "app"` with three
  product flavours, distinct `applicationIdSuffix`, and per-flavour
  `resValue("string", "app_name", ...)`. The manifest now reads
  `android:label="@string/app_name"`.

Customer carries no suffix — it is the primary store listing. The other two
do, so **all three install side by side**, which matters in the field: a
salesman may want the vendor app to see what their vendors see.

### Verified on device — Pixel_9 emulator, Android 16

Not just `flutter analyze` this time:

- All three APKs built: `assembleSalesmanDebug` 712s (cold — included an
  Android SDK Platform 33 install), `assembleVendorDebug` 245s,
  `assembleCustomerDebug` 108s
- All three installed simultaneously; `pm list packages` shows three distinct
  package names
- `aapt2 dump badging` confirms each APK's own `application-label`
- Each launched via `monkey`, reached `ResumedActivity` and
  `ActivityTaskManager: Fully drawn`
- Each logged its own line — `Booting flavour: salesman (Marketplace Sales)`,
  `vendor (Marketplace Partner)`, `customer (Service Marketplace)`
- **Zero** `FATAL EXCEPTION` / `AndroidRuntime` entries for any package
- Screenshots show the ported `create_account_module` rendering correctly:
  teal→slate gradient header, white title, Skip badge in white-on-teal,
  slate-400 description, `BaseTextField` with slate-800 fill and slate-700
  border, and the `Create` button correctly greyed because `isFormValid`
  starts false
- `flutter analyze` clean, `flutter test` 10 passed

### Emulator noise, not app defects

The emulator threw `System UI isn't responding` and later
`Process system isn't responding`, and first frames took 1m4s–1m21s. That is
`system_server` starving after three Gradle builds plus installs on the same
machine — the ANR dialogs name the system, never our packages, and there are
no crash entries for the app. The UI underneath rendered correctly in every
capture. Worth knowing the emulator on this machine is slow enough that ANR
dialogs are routine; they are not a signal about the app.

### iOS flavours are NOT set up

`ios/Runner.xcodeproj/xcshareddata/xcschemes/` contains only `Runner.xcscheme`.
Per-flavour iOS builds need one Xcode scheme and matching build configurations
per flavour, which requires Xcode — **not possible on this Windows machine**.
`flutter run --flavor salesman` will fail on iOS until that is done on a Mac.
Added to the Before Launch Checklist.

### Still open

- iOS schemes (above).
- All three flavours currently land on the same screen — the ported
  create_account_module, which is the only screen built. `_firstScreen()` in
  `app.dart` already switches on the flavour, so Phases 3–5 fill in the real
  entry screens.
- Font assets still not bundled (carried from 0.5a).

### Next

Phase 1 — master data: full schema migrations, `plan_quotas`, and the zones
table with `POLYGON` + `SPATIAL INDEX`.

---

## 2026-08-16 — Phase 1, task 1.1: Full schema migrations

**Status:** Complete. 18 migrations, 29 foreign keys, rollback verified,
76 feature tests still passing.

### Delivered

18 tables in FK-safe order: `categories`, `subcategories`, `zones`, `plans`,
`plan_quotas`, `salesmen`, `vendors`, `customers`, `subscriptions`,
`subscription_items`, `payments`, `commissions`, `leads`, `reviews`, `media`,
`banners`, `notifications`, `audit_logs`. `users` already existed and was not
touched; the three profile tables hang off it 1:1.

Money is `unsignedBigInteger` paise throughout. Commission rates are basis
points, integers for the same reason.

### The SRID problem, and what was done instead

SPEC §8 asks for `POLYGON` at SRID 4326. **That cannot be declared on the
column on MariaDB 10.4.** Both syntaxes were tested against the live database
and both are `ERROR 1064` syntax errors:

| Syntax | Origin | Result |
|---|---|---|
| `POLYGON NOT NULL SRID 4326` | MySQL 8 | rejected |
| `POLYGON NOT NULL ref_system_id=4326` | MariaDB 10.7+ | rejected |

This is a live trap, not a theoretical one: Laravel 12's MySqlGrammar emits
`ref_system_id=` when it detects MariaDB, so writing `srid: 4326` in the
Blueprint **fails the migration outright** on this machine.

The column is therefore a plain `POLYGON NOT NULL` with a `SPATIAL INDEX`, and
SRID 4326 travels on the *values* via `ST_GeomFromText(..., 4326)`. Verified
end to end against a real inserted Gota polygon:

```
id  name  ST_SRID  point inside  point far away
1   Gota  4326     1             0
```

MariaDB stores and returns the SRID but computes on a flat plane. Accurate
enough at city-zone scale; a real divergence from MySQL 8's geodetic maths if
production ever runs MySQL. `ST_Distance_Sphere` **is** available on 10.4
(checked — returned 6964 m between two Ahmedabad points), so SPEC §4.6
distance is fine.

### Design decisions

- **`subscription_items` is one table** with `item_type` enum
  (category/subcategory/zone), matching the single entity named in CLAUDE.md.
  Trade-off accepted: `item_id` carries no FK, so deleting master data can
  orphan rows — whatever deletes a category or zone must clear items in the
  same transaction.
- **`zones.polygon` is NOT NULL** (spatial indexes cannot be built on a
  nullable column) **plus `is_active` defaulting to false**, so a rough
  boundary can be drawn now and refined before the zone goes live.
- **`notifications` is a campaign/dispatch log**, not Laravel's per-user
  notifications table. If an in-app inbox is ever needed, Laravel's own
  migration must use a different table name or it will collide.
- **`reviews.lead_id` is unique** — "one review per lead" (SPEC §9) enforced
  in the database, not just in code. The 24-hour edit window stays in the
  policy as `created_at + 24h`; a column could only drift from it.
- **`commissions.subscription_id` is unique** — second line of defence behind
  `subscriptions.idempotency_key`, so a retried request cannot pay twice.
- Price, duration and commission rate are **snapshotted** onto subscriptions
  and commissions, so later plan or rate changes never rewrite history.

### Index choices worth knowing

- `leads (customer_id, vendor_id, created_at)` — makes SPEC §9's 30-day
  review-eligibility check cheap on every review attempt
- `subscription_items (item_type, item_id)` — the hot path for customer
  vendor matching
- `payments (mode, admin_verified_at)` — the cash reconciliation queue (§5.9)
- `subscriptions (status, end_date)` — the daily expiry job and the
  "expiring in 30 days" dashboard count

### SQLite broke the test suite

Adding `spatialIndex` failed all 76 feature tests at once —
`RuntimeException: The database driver in use does not support spatial
indexes`, because `phpunit.xml` runs on SQLite in memory.

The index is now guarded by driver, so SQLite skips it and MySQL/MariaDB keeps
it (confirmed still present after `migrate:fresh`). **Consequence for Phase 5:
SQLite cannot exercise any spatial query — `ST_Contains` does not exist there
either — so `ZoneMatcher` tests will need a MySQL/MariaDB test database.**
Added to the Before Launch Checklist.

### Verification performed

- `php artisan migrate` — 18/18 DONE against MariaDB
- `migrate:rollback --step=18` — 18/18 reversed cleanly, leaving only the
  framework tables; `migrate` restored all 18, proving `down()` FK ordering
- 29 foreign keys confirmed via `information_schema.KEY_COLUMN_USAGE`
- `SHOW CREATE TABLE zones` — `polygon POLYGON NOT NULL` +
  `SPATIAL KEY zones_polygon_spatialindex`
- Live `ST_Contains` on a real polygon, inside and outside
- `php artisan test --testsuite=Feature` — **76 passed**
- 28 tables total in `marketplace`

### Not built (in SPEC, not in CLAUDE.md's entity list)

`support_tickets` (§5.15), `cms_pages` (§5.13), `settings` (§5.17), admin
roles/permissions (§5.16), plus `favorites`, `reports` and `device_tokens` for
FCM. Out of scope for this task — they need adding before the phases that use
them.

### Next

Task 1.2 onward — Eloquent models, relationships and factories over this
schema.

---

## 2026-08-16 — Task 1.1 follow-ups: MariaDB test database, settings/cms_pages, deletion guards

**Status:** Complete. 84 feature tests passing (was 76).

### 1. Test suite moved off SQLite onto MariaDB

`phpunit.xml` now points at **`marketplace_testing`** (created once with
utf8mb4). SQLite is gone: it supported neither `SPATIAL INDEX` nor
`ST_Contains`, so zone matching — the most important query in the customer
flow — could not be tested at all.

**Timing: 5.84s on SQLite → 8.16s on MariaDB. +2.3s, ~40%.** Far cheaper than
feared, because `RefreshDatabase` migrates once per process and wraps each
test in a transaction.

The SQLite spatial-index driver guard in the zones migration is **removed** —
the schema is now identical in test and development. The migration can no
longer run on SQLite at all, which is the honest outcome given SQLite has no
`ST_Contains` either.

**A safety guard was necessary, and the obvious version of it did not work.**
`RefreshDatabase` runs `migrate:fresh`, which drops every table in the target
database — so a misconfigured `DB_DATABASE` would destroy the development
data rather than fail. The first guard checked the database name in
`setUp()` *after* `parent::setUp()`, and testing it against a throwaway
database showed the sentinel table count going **1 → 29**: `migrate:fresh`
had already run before the check fired. `RefreshDatabase` is wired up during
`parent::setUp()`, so the check has to happen **before** it. Now it does, and
re-running the same probe leaves the throwaway database untouched.

**Real MySQL immediately caught a bug SQLite would have waved through:** the
first version of `DeletionGuardTest` inserted `subscription_items` with
`subscription_id = 1`, which does not exist. MySQL rejected it on the foreign
key; SQLite would have accepted it silently. The test now builds a real
user → vendor → plan → subscription chain.

### 2. settings and cms_pages

- **`settings`** — key/value with a `type` column for safe casting, grouped
  for the admin screen. Phase 3 reads its business rules from here rather
  than hardcoding: `free_trial_max_days` (default 15),
  `free_grants_per_salesman_month`, `grace_period_days`,
  `force_update_version`, `maintenance_mode`.
- **`cms_pages`** — slug, title, body, publish state. Terms / Privacy /
  Refund / FAQ / About. Both app stores reject a submission whose privacy
  policy URL does not resolve, so these must be live before first submission.

Both added to **CLAUDE.md's Domain entities** list with a note on what each is
for. Dev database is now 30 tables.

### 3. Deletion guards on Category, Subcategory, Zone

`PreventsDeletionWhileSubscribed` blocks hard deletion when
`subscription_items` still references the record, throwing
`RecordInUseException` with the record name, the reference count, and the
suggested alternative (deactivate instead).

This is a **second control, not the only one** — hiding the delete button in
Filament is not enforcement, since an artisan command, a tinker session or a
future API route all bypass the UI.

One subtlety worth keeping: **deleting a Category cascades to its
subcategories at the database level, which never fires the children's model
events.** So `Category` also counts references to its own subcategories —
otherwise deleting the parent is a way around the child's guard. There is a
test for exactly that.

`isDeletable()` is exposed so Filament can grey out the action rather than
letting an admin hit an exception.

**8 new tests**, including: the guard is scoped by `item_type` (a zone with
the same numeric id must not block a category), the error message is useful,
and deactivating stays possible while referenced.

### Casualty: the dev admin user was wiped earlier

The integrity check for this session found **zero admin users** in
`marketplace`. Cause traced via the migrations table: batch 1 holds 23
migrations in a single batch, the signature of the `migrate:fresh` **I ran
during task 1.1** to verify the spatial index. That rebuilt the dev database
and destroyed the seeded admin — before this session started, and unrelated
to the test-database switch.

Re-seeded and verified: role `admin`, password matches `ADMIN_PASSWORD` in
`.env`, `canAccessPanel()` true. **Lesson: `migrate:fresh` on the dev database
costs the seeded data; use `migrate:refresh --seed`, or re-seed after.**

### Verification performed

- `php artisan test --testsuite=Feature` — **84 passed, 247 assertions**
- Safety guard proven with a throwaway database: run refused, sentinel table
  survived, no DDL executed
- Spatial index confirmed present in `marketplace_testing`; `ST_Contains`
  returns 1 there
- `marketplace` untouched by the switch: 30 tables (28 + settings +
  cms_pages), admin restored

---

## 2026-08-16 — Phase 1: Category & Subcategory CRUD + public API

**Status:** Complete. 102 feature tests passing (was 84).

### Delivered

**Admin (Filament)**

- `CategoryResource` under a new "Master Data" navigation group: form with
  name, auto-slug, icon upload and active toggle; table with drag-to-reorder
  on `sort_order`, an inline `ToggleColumn` for active, a subcategory count,
  and an active/inactive filter.
- `SubcategoriesRelationManager` on the category edit page — same shape, with
  slug uniqueness scoped to the parent category, matching the composite
  unique index.
- Slug is auto-derived from the name **only while creating**. Editing the name
  later leaves the slug alone, because changing it breaks any link already
  published against the old value.

**API**

- `GET /api/categories` — public, no token, `throttle:public-read`
  (60/min/IP). Returns active categories with active subcategories nested,
  ordered by `sort_order`, in the standard envelope.

### No delete action, and why the notice is there

SPEC §10 requires that these resources offer no hard delete at all. Neither
`DeleteAction` nor `DeleteBulkAction` is registered on either resource, and a
test asserts their **absence** rather than that they are disabled — that is
what stops a later edit quietly reintroducing them.

Two supporting touches:

- The list page carries a subheading explaining that categories are
  deactivated rather than deleted, and *why* (subscriptions record what they
  bought; deleting orphans them). The rule is discoverable at the point where
  an admin would otherwise hunt for the missing button.
- An **"In use"** badge column shows `countSubscriptionReferences()` per row,
  so the cost of deactivating is visible before the toggle is flipped. This is
  where `isDeletable()`'s underlying count earns its keep now that no delete
  action exists to grey out.

The model-level `PreventsDeletionWhileSubscribed` guard still backs all of
this for any caller that bypasses the panel.

### Deliberate deviation: no pagination

`GET /api/categories` returns the full tree, against CLAUDE.md's
"paginate every list endpoint" rule. The three apps need the whole tree
before their first screen can draw — the customer browse grid, and the
quota-capped selection screens with their live "X of Y selected" counter.
Paging would cost several round trips, and nesting subcategories inside a
paginated parent makes the meta block ambiguous.

**Recorded in CLAUDE.md** next to the pagination rule so it does not read as
an oversight, and pinned by a test that fails if pagination ever appears.

### Naming collision to keep in mind

`App\Filament\Resources\CategoryResource` and
`App\Http\Resources\CategoryResource` both exist — admin resource and API
transformer. Different namespaces, never imported into the same file, and
both docblocks say so. Worth remembering before adding a third.

### Three bugs found by the tests

1. **Faker overflow.** `fake()->unique()->randomElement([8 items])` exhausts
   after eight rows and throws `OverflowException` — surfaced by the test
   creating 20 categories. `unique()` belongs on the number, not the list.
2. **`reorderTable` is not a chainable test helper** in Filament v3.3; it is a
   Livewire component method. Tests must use
   `->call('reorderTable', [...ids])`.
3. **`ToggleColumn` is not an action**, so `callTableColumnAction` does
   nothing. It calls `updateTableColumnState` on the component, which is what
   the test now drives.

### Verification performed

- `php artisan test --testsuite=Feature` — **102 passed, 306 assertions**
- 18 new tests: reorder persists, toggle flips and is read back from the
  database, delete actions absent on both resources, relation manager scoped
  to its owner, duplicate slug rejected, non-admin gets 403
- API tests: nesting, ordering by `sort_order` at both levels, inactive rows
  excluded at both levels, empty tree returns `[]` not null, response is not
  paginated
- Live: seeded two active categories plus one inactive, and one inactive
  subcategory — `GET /api/categories` returned exactly the active tree in
  order; `/admin/categories` redirects a guest to the panel login
- Demo rows removed afterwards

### Next

Remaining Phase 1 master data: zones (with polygon drawing), plans +
plan_quotas.

---

## 2026-08-16 — Phase 1: Zone Filament Resource + polygon drawing

**Status:** Complete. 132 feature tests passing (was 111).

### Delivered

- **`ZoneResource`** in the "Master Data" group, following the patterns set on
  `CategoryResource`: **no `DeleteAction`/`DeleteBulkAction`** anywhere
  (SPEC §10), an **"In use"** badge showing `countSubscriptionReferences()`,
  and an explanatory subheading on the list page — which here covers both
  rules an admin will trip over: zones are deactivated rather than deleted,
  *and* new zones start as drafts.
- **`PolygonMap`** custom Filament field — Leaflet + Leaflet.draw, one
  polygon per zone, redraws the existing boundary when editing, and recentres
  on it.
- **Parent zone select** for the Ahmedabad → Gota hierarchy (SPEC §5.3), with
  slug uniqueness scoped to the parent to match the composite index — two
  cities can each have a "central".
- **`pincode`** field as the fallback match (SPEC §8).
- `is_active` defaults to **false** (SPEC §11 draft workflow), with a
  `ToggleColumn` to activate once refined.

**No drag-to-reorder**, unlike categories: `zones` has no `sort_order` column
and zones are not a presented-in-order list.

### The lat/lng trap, and how it is contained

**WKT is `lng lat`. Leaflet is `lat, lng`. GeoJSON is `[lng, lat]`.**
Transposing them still produces a geometrically valid polygon that saves
without error — it just sits in the wrong hemisphere, and nothing complains
until customer search silently matches no vendors.

So the swap happens in exactly one place, `Zone::polygonExpression()`, and
the field's state uses **explicit `{lat, lng}` keys rather than bare pairs**,
so a caller cannot transpose them by accident. Tests pin it with real
Ahmedabad coordinates and a real `ST_Contains`, asserting a point inside
matches and a point outside does not.

### SQL safety

`polygon` cannot be bound as a plain parameter — it has to go through
`ST_GeomFromText`. Rather than interpolating a user-supplied WKT string,
`polygonExpression()` validates every coordinate as numeric and in range,
then re-renders them with `sprintf('%.8F')`. **No caller-supplied string ever
reaches SQL** — the generated WKT contains only digits, signs, dots, commas,
spaces and parentheses. There is a test feeding an injection-shaped string
(`"23.09') --"`) that asserts it is rejected by the numeric check.

### Leaflet is vendored, tiles are not

`npm install leaflet leaflet-draw`, registered through `FilamentAsset` from
`node_modules` and published with `php artisan filament:assets`. **No
third-party script executes inside an authenticated admin session**, which
matters here because this panel edits plans, payments and vendor
verification. Verified: all four assets serve locally from `/js/app/` and
`/css/app/`, and a test asserts the rendered page references no CDN host.

Tiles are necessarily remote — an admin needs roads and landmarks to know
where a zone ends. `config/map.php` makes the URL, attribution, default
centre (Ahmedabad) and zoom configurable. **Added to the Before Launch
Checklist:** OSM's public tile server does not permit sustained production
use, and the env-configurability exists precisely so that swap is a config
change.

### Bug found

`getDefaultView()` is already declared on Filament's `ViewComponent` as
`?string` — the Blade view name. Defining it as `array` on `PolygonMap` was a
**fatal error**, not a warning. Renamed to `getMapCenter()`. Worth remembering
for any future custom Filament field: the base class occupies more method
names than it looks like.

### Verification performed

- `php artisan test --testsuite=Feature` — **132 passed, 364 assertions**
- 11 polygon tests: WKT is written lng-first, ring auto-closes, a saved zone
  contains an interior point and excludes an exterior one, SRID stays 4326,
  points round-trip through storage, and bad input (too few points,
  out-of-range latitude, non-numeric, malformed WKT) is rejected
- 12 resource tests: delete actions absent on list *and* edit pages,
  `is_active` toggled via `updateTableColumnState` and read back from the
  database, a drawn zone saves as a draft and lands in the right place, a
  zone without a boundary is refused, editing reloads the boundary, slug
  uniqueness scoped to parent, non-admin 403, map renders with the configured
  tile URL, no CDN references
- Live: all four Leaflet assets return HTTP 200 from the local server

### Two questions left open for Phase 5

Building this surfaced two unresolved decisions about zone hierarchy —
whether matching tests parent polygons or only leaf zones, and whether
deactivating a parent cascades to its children. **Both were resolved the same
day in SPEC §8** (leaf-only matching; no physical cascade). See **Open
Questions** at the top of this file for what that changed here.

### Next

Remaining Phase 1 master data: plans + plan_quotas.

---

## 2026-08-16 — Phase 1: Plan Resource + quotas

**Status:** Complete. 179 feature tests passing (was 145).

### Delivered

- **`PlanResource`** in "Master Data": plan fields plus the six quota fields
  nested in the same form via `Fieldset::make()->relationship('quota')`, so
  the 1:1 `plan_quotas` row is created and saved with the plan.
- **Quota summary card** in the table — a `ViewColumn` rendering
  "5 Categories · 15 Subcategories · 3 Zones · 20 Photos · 2 Videos" plus a
  priority-rank badge, instead of six numeric columns. A plan reads as a
  package, so the numbers are more legible grouped.
- Price formatted with `Number::currency` (intl enabled since task 0.4),
  drag-to-reorder on `sort_order` as on categories, `is_active` toggle, no
  delete actions, explanatory subheading.
- New models: `Plan`, `PlanQuota`, and a **minimal `Subscription`** — enough
  to count what runs on a plan. Phase 3 builds the real one.

### No delete — but not for SPEC §10's reason

SPEC §10 covers categories, subcategories and zones specifically, because
`subscription_items.item_id` carries no foreign key and a delete orphans
rows. **Plans are different**: `subscriptions.plan_id` is a real FK with
`ON DELETE RESTRICT` (verified against the database). The outcome is the
same — no delete action — but the reason is that the database already
refuses, so the action could only ever surface a raw integrity error. The
docblocks say this rather than miscite §10.

This also means **`PreventsDeletionWhileSubscribed` does not apply to Plan**:
that trait reads `subscription_items` by `item_type`, and plans never appear
there. Plan counts off `subscriptions.plan_id` instead.

### "In use" shows active and history

The badge reads `3 active` when that is the whole story, and
`1 active / 3 all time` when they differ. The second number matters because
`RESTRICT` blocks on *every* subscription ever — including expired and
**soft-deleted** rows, which still hold the foreign key. A retired plan
showing "0 active" is still undeletable, and deactivating it still affects
renewals for everyone in the total. Tests cover all four shapes.

### Money: the float trap, and a wrong assumption caught

Price is entered in rupees and stored as integer paise (CLAUDE.md).
`Plan::rupeesToPaise()` parses the decimal **as a string** rather than
multiplying a float.

**I initially documented the wrong example.** The comment and test claimed
`(int) (10.10 * 100)` yields 1009 — it does not, it yields 1010, and the test
failed on that assertion. Rather than drop the claim, I swept every paise
value from 0.01 to 200.00 and found the real picture:

> **1145 of 20000 amounts convert wrong** under `(int) ($rupees * 100)` —
> including ₹1.15 → 114, ₹0.29 → 28, ₹1.13 → 112. Each undercharges by a
> paisa, on values no one would think to check.

So the concern was real and the example was wrong. Comments and tests now use
verified failing values, `MoneyConversionTest` sweeps the full range as a
regression net, and it asserts the naive approach *still* misconverts
something — so if a future PHP makes floats safe here, the test says so
instead of silently justifying a workaround that is no longer needed.

### Two Filament mechanics worth remembering

1. **`dehydrated(false)` hides a field from `mutateFormDataBefore*`.** The
   price field used it to stay off the model, which also meant the mutate hook
   received nothing and the price saved as **0**. Keep it dehydrated and
   `unset()` it in the hook instead.
2. **`Fieldset->relationship('quota')` calls `statePath('quota')`**, so nested
   fields live at `quota.max_zones`, not `max_zones`. Tests must fill them
   nested — a flat `fillForm` silently leaves the defaults in place, and the
   assertion then fails on a value that looks arbitrary.

### Verification performed

- `php artisan test --testsuite=Feature` — **179 passed, 20,470 assertions**
- 15 resource tests: delete actions absent on list and edit, toggle via
  `updateTableColumnState` read back from the DB, reorder writes `sort_order`,
  plan and quota save together, price round-trips rupees → paise → rupees,
  quota editable through the plan form, all four "in use" shapes, duplicate
  slug rejected, non-admin 403
- 19 money tests including the 0.01–200.00 sweep
- Live: `₹999.00` formatting, quota card values, `/admin/plans` routes present
  and a guest redirected to login; demo rows removed afterwards

### Next

Phase 1 master data is complete — categories, subcategories, zones, plans and
quotas. Next is Phase 2, user management for all four roles.

---

## 2026-08-16 — Phase 1: Master data seeders

**Status:** Complete. 195 feature tests passing (was 179).
`php artisan migrate:fresh --seed` now leaves a working admin login and a
browsable catalogue.

### Delivered

| Seeder | Produces |
|---|---|
| `CategorySeeder` | 10 categories × 4 subcategories = **40** |
| `ZoneSeeder` | Ahmedabad + **15** active sub-zones with polygons and pincodes |
| `PlanSeeder` | Silver / Gold / Platinum with quota rows |
| `DatabaseSeeder` | Calls `AdminUserSeeder` first, then the three above |

All real master data, not demo fixtures — the same seeders are safe on a
fresh production database. Every one is idempotent (keyed on slug, or email
for the admin), with a test proving a second run changes nothing.

### The plan ladder

| | Silver | Gold | Platinum |
|---|---|---|---|
| price | ₹999.00 | ₹2,499.00 | ₹4,999.00 |
| stored paise | 99900 | 249900 | 499900 |
| duration | 365 d | 365 d | 365 d |
| max_categories | 2 | 5 | **10** |
| max_subcategories | 5 | 15 | **40** |
| max_zones | 2 | 5 | **15** |
| max_photos | 10 | 30 | 100 |
| max_videos | 1 | 5 | 20 |
| priority_rank | 1 | 2 | 3 |

Platinum's ceilings are the seeded catalogue exactly — 10 categories, 40
subcategories, 15 sub-zones — so the top tier means "everything" against real
data rather than an arbitrary number. A test asserts that equality, so adding
an 11th category without revisiting the plan will fail loudly.

### Two things that were load-bearing, not incidental

**Ahmedabad is seeded active.** Effective status is
`own is_active AND (no parent OR parent.is_active)` (SPEC §8), so an inactive
parent would make all 15 sub-zones unmatchable while each looked perfectly
fine in the admin panel. There is a test for it.

**`WithoutModelEvents` removed from `DatabaseSeeder`.** Laravel's stub
includes it. This project's models depend on model events for correctness —
`TracksFileDisk` stamps the `disk` column on `creating`/`updating` — so
suppressing them would seed rows whose recorded storage location is null,
which is precisely the mislabelling that column exists to prevent.

Laravel's `test@example.com` stub user is gone; the only seeded user is the
admin.

### Zone geometry

Coordinates are approximate centroids of the real areas, drawn as
**non-overlapping** ~0.01° boxes (about 1.1 km). Non-overlap is deliberate:
two polygons covering one point would match a customer to two zones and
distort both search results and the lead records written against them. A test
runs `ST_Intersects` across all 105 sub-zone pairs and asserts none overlap,
plus that each zone contains its own centre and that Ahmedabad's polygon
encloses every child.

These are seed approximations, **not surveyed boundaries** — and because the
boxes do not tile, there are gaps between them where a customer matches no
polygon at all. Replace them through the admin map before launch; this is on
the **Before Launch Checklist** at the top of this file, with the failure
mode and the two invariants to preserve.

### Verification performed

- `php artisan test --testsuite=Feature` — **195 passed, 20,613 assertions**
- 16 seeder tests: counts, two-level depth, parent is not a leaf while all 15
  children are, every zone active, centre containment, no pair overlaps,
  parent encloses children, ladder ascends on all six quota dimensions plus
  price, Platinum matches the catalogue, admin still authenticates, stub user
  absent, re-seeding is a no-op
- Live `migrate:fresh --seed` on the dev database: admin password verifies,
  `GET /api/categories` returns all 10 categories with their subcategories,
  and `POINT(72.560 23.036)` resolves to exactly one zone — Navrangpura,
  pincode 380009

### Next

Phase 2 — user management for all four roles.

---

## 2026-08-16 — Phase 2: User Resource + account tombstone

**Status:** Complete. 225 feature tests passing (was 195).

### Delivered

- **`UserResource`** (new "People" group) — CRUD for all four roles
  (SPEC §5.2), with the profile fieldset switching on the selected role.
- **Models**: `Salesman`, `Vendor`, `Customer` (all three were missing), plus
  `users.original_email`.
- **Temp password shown once** (SPEC §5.2) — generated for *every* created
  account, since an admin-created user has no password otherwise. 20 chars
  from an alphabet excluding `0O1lI`, because these get read down a phone or
  typed off a WhatsApp message. Persistent notification with a copy action;
  never stored in plaintext, never shown again.
- **Delete + restore** via the tombstone mechanism (see the updated Phase 5.6
  decision below).

### Role-conditional profiles

Each profile is a `Fieldset::make()->relationship(..., condition: …)`. The
`condition` argument matters: Filament **deletes the related row when it
evaluates false**, so a role switch cannot strand an orphan profile. A test
asserts only the matching row is created.

**Role is fixed after creation** — changing it later would strand the profile
and any subscriptions, and there is no sane migration from vendor to customer.

**Admin-created vendors start as `draft`.** SPEC §7 reserves
`pending_verification` for self-registered vendors; an admin-created vendor
has no subscription yet, the same position the salesman flow is in before
payment. `pending_verification` is deliberately absent from the status
dropdown.

### The two registration rules do not drift

SPEC §1 bars salesmen from self-registering — enforced on the API by
`RegisterRequest` limiting `role` to vendor|customer. SPEC §5.2 requires the
panel to create all four. Both are now pinned by tests in the same file: the
panel can create an admin, and the API still rejects `role=salesman` with 422.

### Three bugs found by tests

1. **`Unique::orWhereNull()` does not exist** — a fatal error that took out 9
   tests at once. The scoping was wrong anyway: the unique index covers
   trashed rows, so validation must check all rows. Plain `unique()`, matching
   `RegisterRequest`.
2. **`email_verified_at` is not in `User::$fillable`**, so setting it in
   `mutateFormDataBeforeCreate` was silently dropped. Now set via
   `markEmailAsVerified()` in `afterCreate`, rather than widening fillable for
   a field no user input should set.
3. A test asserted `where('name', 'like', 'Asha%')` counted 2 rows, but the
   factory gives the deleted user a random name — the assertion was wrong, not
   the code.

### Verification performed

- `php artisan test --testsuite=Feature` — **225 passed, 20,821 assertions**
- 18 resource tests: each role creates its own profile row and no other,
  salesman gets `must_change_password`, vendor starts `draft`, accounts are
  email-verified, password is bcrypt and never plaintext, generated passwords
  avoid ambiguous glyphs across 50 samples, role disabled on edit, delete and
  restore through the panel, action visibility flips with trashed state, no
  bulk delete, duplicate email rejected, non-admin 403
- 12 tombstone tests (below)

### Next

SPEC §5.8 Vendor Verification Queue — §5.2 also mentions a Verify/Reject
action on vendor rows, which is that separate module and is **not** built
here.

---

## 2026-08-16 — Phase 2: Audit logging mechanism

**Status:** Complete. 241 feature tests passing (was 225).

### Delivered

`AuditLog` model plus a `RecordsAuditLog` trait using Eloquent `created` /
`updated` events. Models opt in by using the trait, so what is audited is
visible on the model rather than registered elsewhere. Applied to **User**,
**Plan** and **PlanQuota**.

Column mapping — the table exists from Phase 1 with Laravel-idiomatic names:
`actor_id` → `user_id`, `entity_type/id` → `auditable_type/id`,
`before/after` → `old_values/new_values`. No migration needed.

### What before/after contain

**Diff by default.** `old_values` and `new_values` carry only the keys that
changed — a role change reads `{"role":"vendor"}` → `{"role":"salesman"}`.

**Plan and PlanQuota additionally snapshot the full commercial terms** in
`new_values`. The reason is specific: a subscription copies `price_paise` and
`duration_days` at purchase, but **quota is copied nowhere**. Raise Gold's
`max_zones` from 5 to 8 and every existing Gold subscriber silently gains
quota; lower it and they are over their limit — with nothing in the
subscription recording what they actually bought. This log is the only answer
to "what did this plan offer on the day they signed up", and a pure diff would
require replaying every entry from creation to reconstruct it.

Verified live:

```
entity: Plan #2 (Gold)   action: updated   actor: null (system)
before: {"max_zones":5}
after:  {"max_categories":5,"max_subcategories":15,"max_zones":8,
         "max_photos":30,"max_videos":5,"priority_rank":2}
```

**Quota changes are filed against the Plan**, not the `plan_quotas` row, so a
plan's history is one timeline. An admin edits "the Gold plan" — the quota is
a section of the plan's own form, not somewhere they navigate to separately.

### The case model events cannot see

`User::deleteWithTombstone()` renames the email with `saveQuietly()` **by
design** — that was the Phase 5.6 decision, so the rename fires no events.
A `saving`/`saved` listener would therefore never see the single most
audit-worthy change in the system.

So delete and restore **write their audit entries explicitly** inside those
methods, where the real before and after are still in hand. By the time
`restored` fires, the tombstone value has already been swapped away and no
listener could recover it. Both docblocks say so.

The delete entry also records `tokens_revoked`, since revocation is part of
the same transaction and is otherwise invisible.

### Never recorded

`password` and `remember_token`, by deny-list rather than by remembering,
plus `created_at`/`updated_at` as noise. A password-only change writes no
entry at all, because nothing loggable moved. Tested.

`AuditLog` itself carries no trait — audit rows about audit rows would
recurse, and the model has `UPDATED_AT = null` because an audit trail that
can be edited is not one.

### Note on the brief — resolved

The instruction cited a CLAUDE.md rule about avoiding event/listener chains
that was not in CLAUDE.md at the time; it had been in the superseded copy
discarded in session 1.

**Resolved 2026-08-17:** the rule is back in CLAUDE.md in a refined form —
prefer a well-named Artisan command over a custom Event + Listener class pair
for a one-off, single-consumer action, **explicitly excluding** Eloquent model
lifecycle events used for cross-cutting concerns like this audit log and the
SPEC §10 deletion guards. Both implementations are on the blessed side of that
line; nothing changed in the code.

### Verification performed

- `php artisan test --testsuite=Feature` — **241 passed, 20,865 assertions**
- 16 audit tests: diff isolation, no entry when nothing changed, passwords
  never logged, actor recorded when signed in and null for system changes,
  delete records the email release and token count, restore records what it
  was restored from, a **refused** restore writes nothing (the transaction
  rolls back), plan snapshot completeness, quota filed against the plan with
  no entry against the quota row, one timeline per plan, no self-auditing

### Still open

Only User, Plan and PlanQuota are audited. SPEC §5.14 calls out free-trial
grants by salesmen as the case that matters most — that is Phase 3, and
`Subscription`, `Payment` and `Commission` should take the trait when built.
**Tracked in BUILD_PLAN as of 2026-08-17**, so it is carried in the phase
plan rather than only here.

---

## 2026-08-17 — Phase 2: Sub-admin permissions and policies

**Status:** Complete. 262 feature tests passing (was 241), two consecutive
clean runs.

### Delivered

- `users.permissions` JSON column — a flat array of `module.action` strings.
  Super-admins hold the single wildcard `["*"]`.
- `App\Enums\Permission` — one registry read by the policies, the admin
  checkbox UI and the tests, so an ability cannot be grantable but unchecked,
  or checked but ungrantable.
- `AdminModulePolicy` base plus `Category`, `Zone`, `Plan` and `User`
  policies. Filament's `can()` already routes `canEdit`/`canDelete` through
  them, so no per-resource override — which is also the thing most likely to
  be forgotten on the fifth resource.
- Permission checkboxes on `UserResource`, visible only to super-admins.

Because `User` already carries `RecordsAuditLog`, **privilege changes are
audited automatically** with a before/after diff — which is what SPEC §5.14
wants recorded, at no extra cost.

### Authorization now fails closed

Filament's default is the opposite, and it is worth knowing: with
`shouldCheckPolicyExistence = true`, `Filament\authorize()` falls through to
before-callbacks when **no policy exists or the policy lacks that method**,
which permits the action. A resource added without a policy would be silently
open to every sub-admin, with nothing in the UI to indicate it.

`Resource::checkPolicyExistence(false)` is now set, so a model with no policy
is denied. `PolicyCoverageTest` enumerates every registered resource against
`Gate::getPolicyFor()` and asserts none is missing, plus asserts the setting
itself has not flipped back.

A useful side effect: master-data policies deliberately have **no `delete`
method**, so SPEC §10's "never hard-deletable" is enforced by absence — no
permission can grant it, and a test proves even a super-admin cannot delete a
category or plan.

### The escalation guard

`UserPolicy` carries the rule that makes every other permission meaningful. A
sub-admin holding `users.update` could otherwise grant themselves
`plans.update`, mint a second super-admin, or delete the super-admin who
scoped them. So:

- only a super-admin may create, edit or delete an **admin** account —
  including their own, because the permissions field is on that same form;
- the permissions field is **hidden**, not disabled, for anyone else. A
  disabled field still round-trips through the request and Filament would
  save an injected value;
- nobody may delete themselves — an admin locking themselves out has no
  in-panel recovery.

### Three bugs found

1. **`CategoryPolicy` returned `categorys`.** Generated by naive `+ 's'` from
   the model name; the enum says `categories`. A prefix mismatch does not
   error — it silently denies the whole module, because `hasPermission()`
   compares against a string nothing grants.
2. **The audit trait logged raw, uncast values.** `getChanges()` returns
   pre-cast attributes, so the new `permissions` array was recorded as a JSON
   *string* — JSON nested inside the log's own JSON. Now read through
   `getAttribute()`, so arrays stay arrays and enums serialise to their backed
   value. This was latent before today; the array column is what exposed it.
3. **A flaky test, revealed by the fail-closed change.**
   `test_a_quota_change_is_filed_against_the_plan` updated `max_zones` to 8
   while `PlanQuotaFactory` randomises it 1–10 — when the factory rolled 8 the
   update was a no-op, no event fired, no audit row was written. It passed
   alone and failed in the suite. Both affected tests now pin the starting
   value first.

### The fail-closed change broke 47 existing tests — correctly

Every admin fixture had `permissions = null`, so they were denied everywhere.
That is the intended behaviour, and it surfaced a real deployment bug:
**`AdminUserSeeder` would have produced an admin locked out of the panel.**
The migration backfills pre-existing admins, but `migrate:fresh --seed`
creates that row *after* the migration runs, so the backfill never sees it.
The seeder now sets `["*"]` explicitly, and `UserFactory::role()` gives admins
the wildcard by default with a new `subAdmin([...])` state for scoped ones.

### Verification performed

- `php artisan test --testsuite=Feature` — **262 passed, 20,925 assertions**,
  run twice to confirm the flakiness is gone
- 18 permission tests: fail-closed on null/empty, exact grants, wildcard,
  non-admin roles hold nothing, view does not imply edit, panel routes 403,
  no permission grants master-data deletion, and the full escalation guard
- 3 policy-coverage tests
- Live after `migrate:fresh --seed`: seeded admin is a super-admin and can
  reach every resource but still cannot delete a plan; a sub-admin scoped to
  `categories.*` sees categories only, with plans, zones and users all denied

### Still open

Permissions exist for modules not yet built — `reviews.*` is the SPEC §5.16
worked example and is excluded from the coverage assertion until the Reviews
resource lands in Phase 5. Vendors, subscriptions, payments and commissions
need entries as their resources arrive.

---

## 2026-08-18 — Docs caught up, and a hole the new rule exposed

**Status:** Docs verified. One security fix; 264 feature tests passing
(was 262).

### Both documented rules confirmed

- **SPEC §5.16** now states the escalation guard explicitly — only a
  super-admin may modify any admin account's permissions, including their own,
  and the field must be hidden rather than disabled because a disabled field
  still round-trips. `UserPolicy` and `UserResource` already matched this; no
  change was needed.
- **CLAUDE.md** generalises hidden-vs-disabled to *any* field whose
  editability depends on the current user's role.

Both docs verified as genuine supersets — every prior decision spot-checked
and intact (leaf definition, depth cap, no-hard-delete, draft workflow,
commission rule, notifications shape; Laravel 12, both expiry rules, theme,
entity list, Flutter builder rule, intl, Artisan-vs-Event).

### The generalised rule exposed a live hole

Applying it beyond the permissions field found the `role` select failing the
same test. It was `->disabled()` on edit **and** carried a bare
`->dehydrated()`, which overrides Filament's default of excluding disabled
fields from the save. So `disabled` was purely cosmetic:

```
>>> role after crafted submit: admin
```

A customer was promoted to admin by a crafted Livewire submission. That
**bypasses `UserPolicy::create()`**, which restricts making admins to
super-admins — so a sub-admin with `users.update` could mint an admin account
without holding that right. The promoted account fails closed on permissions,
but it passes `canAccessPanel()`.

**The existing test did not catch it** because
`assertFormFieldIsDisabled(role)` asserts how the field *renders*, not that
a submission is rejected — exactly the "silently no-ops" failure mode
CLAUDE.md already warns about for `ToggleColumn`.

Fixed by dehydrating only on create:
`->dehydrated(fn ($operation) => $operation === 'create')`. Two tests added
that submit crafted payloads and assert the value is unchanged — for both
`role` and `permissions`.

### Lesson worth carrying

`disabled()` + `dehydrated()` is equivalent to no protection at all. Where a
field must not be settable, either omit it from the schema (`hidden()`/
`visible()`) or stop it being dehydrated. And assert against the **saved
value**, never against the rendered field state.
---

## 2026-08-18 — Phase 3: Salesman login + forced password change

**Status:** Complete. 278 backend tests passing (was 264), Flutter analyze
clean, 10 Dart tests passing, flow verified live end to end.

### This was ~40% backend

The ask was a Flutter screen, but three things had to exist first:

1. **`must_change_password` was on `salesmen`, not `users`.** Migrated and
   backfilled, old column dropped. `UserResource` issues a temporary password
   to *every* role it creates, so a salesman-only flag left admins, vendors
   and customers holding an admin-chosen password forever with nothing
   prompting a change.
2. **The API never exposed it** — `UserResource` had no such field, so the app
   had no way to know it should redirect. Now returned on login.
3. **There was no change-password endpoint at all.** `reset-password` needs an
   emailed token, which a first-login salesman does not have. Added
   `POST /api/auth/change-password` (`auth:sanctum`, throttled 6/hour keyed on
   the user).

### Server-side enforcement, not just a redirect

`RequirePasswordChange` middleware is appended to the whole `api` group and
rejects every authenticated route with `PASSWORD_CHANGE_REQUIRED` (403) while
the flag is set. Two routes stay open and both are necessary: change-password
because it is the way out, and logout because trapping someone in a session
they cannot leave is worse than the risk being managed.

This matters because the login response already contains a working token — a
client-side redirect alone is advisory, and anyone calling the API directly
would keep the admin-chosen password indefinitely.

The endpoint also requires the **current** password (without it, a borrowed
device is a full account takeover) and rejects reusing the same one (a no-op
"change" would otherwise clear the flag and defeat the rule).

### Flutter, to the 0.5a pattern

```
screens/auth_flow/
  salesman_login_module/    view + controller
  change_password_module/   view + controller
  salesman_home_module/     view + controller  (PLACEHOLDER, Phase 3 proper)
```

`GetBuilder` with init/dispose, plain non-reactive fields then `update()`,
`DataSource.instance.xxxAPI()`, `Injector` for the token, progress overlay
around the call, `Base*` widgets, `StringRes` + `.tr()`, and
`builder: (controller)` — never `_`.

No registration screen anywhere in this flavour: salesmen never self-register
(SPEC §1).

`main_salesman.dart` now lands on login, and an already-signed-in salesman
with a pending change is routed to the change-password screen rather than
home — otherwise the app would look signed in and fail on its first request.
The change-password screen sets `canPop: false` for the same reason.

`Injector.deviceName()` persists a per-install device label. CLAUDE.md
specifies one token per device and the server keys on that name, so a fresh
name each sign-in would leave an orphaned live token behind every time.

### Two collisions worth remembering

- **`TestCase::withToken()` already exists in Laravel** and is public.
  Redeclaring it private is a fatal error, not an override — the same
  base-class trap CLAUDE.md documents for Filament components, in a different
  framework class. Laravel's version already sets the Bearer header.
- **`throttle:login` does not exist.** That named limiter was removed when
  login moved to failure-only counting inside `AuthController`. Using it would
  have thrown at runtime; a dedicated `change-password` limiter was added,
  keyed on the authenticated user rather than an email the request never
  carries.

### A gap the migration opened

Moving the column changed its default from `true` (on salesmen) to `false`
(on users), which silently stopped admin-created accounts being flagged at
all. `CreateUser` now sets it explicitly for every role, with a test covering
admin, vendor and customer — not just salesman. The default stays `false` so
self-registered users, who choose their own password, are not asked to change
it immediately.

### Verification performed

- Backend: **278 passed, 20,968 assertions**; 13 new change-password tests
- Flutter: `flutter analyze` clean, `flutter test` 10 passed
- Live against a seeded salesman: login returns
  `must_change_password: true` → `GET /api/user` returns 403
  `PASSWORD_CHANGE_REQUIRED` → wrong current password returns
  `INVALID_CURRENT_PASSWORD` → change succeeds and returns the flag as
  `false` → the same token then gets 200 from `/api/user`

### Still open

`salesman_home_module` is a **placeholder** — My Vendors, Earnings and Profile
(SPEC §2.3–2.5) are the rest of Phase 3. Sign-out is real so the loop can be
exercised. Vendor and customer flavours still land on the ported
create_account_module.

---

## 2026-08-18 — Phase 3: Vendor draft API + Add Vendor step 1

**Status:** Complete. 298 backend tests passing (was 278), Flutter analyze
clean, 10 Dart tests, flow verified live including resume and multipart
upload.

### Delivered

**Backend**

| Route | Purpose |
|---|---|
| `POST /api/vendors/draft` | Create in Draft, returns the id |
| `POST /api/vendors/{vendor}/kyc` | Multipart KYC upload |
| `GET /api/vendors/{vendor}` | Recover a draft the app has the id for |

All behind `auth:sanctum` + `role:salesman,admin`. `VendorDraftService` holds
the two-table transaction, per CLAUDE.md's rule for multi-table work.

**Flutter** — `vendor_flow/add_vendor_module/` to the 0.5a pattern, plus
`image_picker` and a `_multipart` path on `DataSource` (it could only send
JSON before).

### The duplicate check spans two tables, and had to

There is **no `email` column on `vendors`** — email is the login, so it lives
on `users`. Checking only `vendors` would let a salesman create a vendor
against an address already belonging to a customer, and the insert would then
fail on the users unique index as a raw integrity error instead of a clear
message. The check is `users.email` + `vendors.phone`, both including trashed
rows since the unique indexes do not exempt them.

### Resume, because SPEC §2.2 asks for it

A repeat submit matching **this salesman's own Draft** returns it with
`resumed: true` rather than a duplicate error. If the response is lost after
the row is written, a retry recovers the draft — otherwise the salesman is
permanently blocked on that vendor from the field, which is exactly what
saving-before-payment exists to prevent.

Deliberately narrow: another salesman's draft, or a vendor further along its
lifecycle, is a genuine duplicate and still rejects. Verified live:

```
1. create      -> id=1  status=draft  resumed=false
2. resubmit    -> id=1                resumed=true
3. totals      -> vendors=1  vendor_users=1
4. other sales -> 422 VENDOR_ALREADY_EXISTS (fields.phone)
```

### KYC is a separate call

Matching SPEC §2.2's own ordering — save the draft first, then upload. A slow
or failed photo on a weak connection then costs the upload, not the typed
business details. There is a test for exactly that: a rejected oversized file
leaves the draft intact.

Files land on the local `public` disk (R2 is not configured — Before Launch
Checklist) with `disk` recorded, so the eventual migration moves them row by
row.

### Draft accounts cannot sign in

`vendors.user_id` is NOT NULL, so a draft needs a user row. It is created with
a random 24-character password the salesman never sees; SPEC §2.2 generates
the *shareable* temp password at Subscribe. A vendor who never completes
payment is therefore not left holding working credentials to a Draft account.
Tested.

### Three bugs found

1. **`bootVendorFileDisk()` would never have run.** Eloquent auto-calls
   `boot{TraitName}` for traits and `booted()` on the model — a
   plausible-looking `bootSomething()` on the model is invoked by nothing, and
   the failure is silent: `disk` would simply have stayed null. Renamed to
   `booted()`.
2. **A false-positive test.** `test_uploading_only_the_id_proof_still_stamps_
   the_disk` passed for the wrong reason: `TracksFileDisk` stamps `disk`
   unconditionally on *create*, so it was already set before any file existed.
   The test now nulls `disk` first, and I verified it by disabling the
   override — it fails without it, passes with it. Without that check the
   override was untested code that looked covered.
3. **`Vendor` has two file paths against one `disk` column**, and the trait's
   `updating` hook keys on a single `fileDiskPathColumn()`. Uploading only the
   ID proof would not have stamped the disk; `Vendor::booted()` covers either
   path.

### Behaviour pinned, not endorsed

`TracksFileDisk` stamps `disk` on create even when the row has no file, so a
vendor with no documents still records `disk = public`. Harmless — the R2
migration selects on rows whose *path* is not null — but a test now pins it so
a future change to the trait surfaces here rather than quietly altering what
`disk` means.

### Verification performed

- Backend: **298 passed, 21,033 assertions**; 20 new vendor-draft tests
- Flutter: `flutter analyze` clean, `flutter test` 10 passed
- Live: create → resume → duplicate rejection → real multipart upload of both
  documents, files written to `storage/app/public/vendor-kyc/1/`, URLs
  resolved through the row's disk. Test data and uploaded fixtures removed.

### Still open

Step 1 only. GPS capture is wired through the controller (`latitude` /
`longitude` are sent) but nothing sets them yet — no location plugin. Steps 2
onward (plan → categories → subcategories → zones with the live "X of Y"
counter, review screen, Subscribe) are the rest of Phase 3, as is the
`AddVendorView` entry point from the salesman home placeholder.

---

> **Also for Phase 5:** zone hierarchy is settled — leaf-only matching and
> effective-active computed at match time, per SPEC §8. `ZoneMatcher` still
> has to implement both; see **Open Questions** at the top of this file.

## ✅ Decision — Phase 5, task 5.6: account deletion must free the email

**Recorded 2026-08-16 during Phase 0. ~~Nothing to build until Phase 5.~~
BUILT EARLY on 2026-08-16, during Phase 2 user management** — the admin panel
needed a delete action, and a plain soft delete would have shipped exactly the
bug this decision was written to prevent.

Implemented as `User::deleteWithTombstone()` and
`User::restoreWithOriginalEmail()`, both transactional. Every point below was
followed. Two things were added beyond the original decision:

- **`users.original_email`** (new nullable column) holds the real address so a
  restore can put it back. The decision covered deletion but not recovery; a
  one-way rename would hand back a restored account nobody can sign in as.
- **Restore refuses when the address has been re-registered.** Releasing the
  email is the point, so someone else may legitimately hold it by then.
  Restoring anyway would violate the unique index; keeping the tombstone
  silently would return an unusable account. It fails with a message naming
  the address instead.

The original decision, for reference:

BUILD_PLAN task 5.6 ("Extras") includes account deletion, which SPEC §4.10
requires for app store compliance. There is a collision between that and the
auth module built in 0.2, and it will be expensive to discover late.

**The problem.** `users.email` carries a real unique index, and `SoftDeletes`
leaves the row in place. So a soft-deleted account permanently occupies its
email address: the person can never sign up again with it.
`RegisterRequest` deliberately validates uniqueness *including* trashed rows,
because scoping it `withoutTrashed()` would let validation pass and then fail
at the database level with an integrity violation.

**The fix, when 5.6 is built.** On deletion, mutate the email *before* soft
deleting:

```php
$user->forceFill([
    'email' => "deleted-{$user->id}-".now()->timestamp.'@deleted.local',
])->saveQuietly();

$user->delete();
```

This frees the original address for re-registration while preserving the row
for audit, leads, reviews, and commission history. Points to note:

- **No schema change is needed**, now or then — the unique index keeps working
  because the tombstoned address is unique by construction (id + timestamp).
- Including the timestamp means the same user id deleted, re-created, and
  deleted again does not collide with its own earlier tombstone.
- `@deleted.local` is a reserved TLD and cannot receive mail, so no
  notification can ever reach a tombstoned address by accident.
- Use `saveQuietly()` so the rename does not fire model events that later
  phases may hang observers off.
- **Revoke all Sanctum tokens in the same transaction** as the delete —
  otherwise a live token on another device keeps working against a
  soft-deleted user.
- `RegisterRequest` needs **no change**: the tombstoned address no longer
  matches, so the original email validates as free.
- Worth a test at 5.6: delete an account, then re-register with the same
  email and assert it succeeds.

---

## 2026-08-19 — TracksFileDisk generalized for multi-file models

Follow-up to the previous session's Vendor fix, prompted by a direct question:
was the fix functionally complete, or just patched over on Vendor?

**Verified first, then fixed.** A throwaway probe test (created and deleted)
confirmed the existing `Vendor::booted()` override was NOT functionally
broken — shop-photo-only and id-proof-only uploads both stamped `disk`
correctly. But it was architecturally a dead end: the fix lived in a
Vendor-specific `saving` hook that any future multi-file model would have to
hand-copy, including Phase 4's vendor self-service KYC. That's the exact bug
class already hit twice here (a `bootVendorFileDisk()` that Eloquent never
called, and the original single-column trait gap) — hand-written per-model
hooks fail silently when misnamed or forgotten.

**Fix:** generalized `TracksFileDisk` itself. Added `fileDiskPathColumns():
array` (defaults to `[$this->fileDiskPathColumn()]`), and the `updating` hook
now loops over every declared column instead of one. `Vendor` no longer has a
bespoke `booted()` hook at all — it only declares:

```php
public function fileDiskPathColumns(): array
{
    return ['shop_photo_path', 'id_proof_path'];
}
```

A future multi-file model needs the same one-line override and nothing else;
there is no boot-hook name to get wrong.

**Regression tests added** to `FileDiskTrackingTest.php` (`vendorWithNullDisk()`
helper + 5 tests), and proven meaningful by intentional reversion: temporarily
narrowing `Vendor::fileDiskPathColumns()` back to one column made
`test_a_secondary_file_column_also_stamps_the_disk` fail while the
primary-column test still passed, then restoring the two-column declaration
made both pass again.

**Verification:** full backend suite, 303 passed (21,041 assertions) — no
regressions from the refactor.

---

## 2026-08-19 — GPS capture, and Add Vendor step 2 (plan → categories/subcategories → zones)

**Status:** GPS capture live and tested. Step 2 built end-to-end: two new
backend read-only endpoints, two new Flutter screens, wired to step 1.
Backend suite 317 passed (21,108 assertions, up from 303). Flutter: 19 tests
passed, `flutter analyze` clean.

### GPS capture

`geolocator` wired into `AddVendorController.captureLocation()`: checks
location services, requests permission, sets `latitude`/`longitude` on
success. Deliberately best-effort — the server field is nullable, so a
denial doesn't block the draft. Regression test proves this: denying
permission via a fake `GeolocatorPlatform` leaves lat/lng null AND the draft
still saves, with the request body omitting the keys entirely rather than
sending explicit nulls — proven meaningful by reverting the `if (x != null)`
guards and watching the test fail.

### Two new backend endpoints (prerequisite — neither existed)

A survey before planning found `/api/categories` live but no `/api/plans`
and no `/api/zones` — only their Filament admin resources existed. Both
needed for step 2's real data:

- `GET /api/plans` — `PlanResource` flattens `PlanQuota` onto the plan
  (mirrors `Plan::auditSnapshotAttributes()`'s field list). Unpaginated,
  same CLAUDE.md master-data exception as categories.
- `GET /api/zones` — `ZoneResource` returns top-level active zones with
  their active children nested, `is_leaf` computed off the already
  eager-loaded `children` relation (never calls `Zone::isLeaf()` directly —
  that fires a query per row). Filtering both levels through `active()`
  implements SPEC §8's "effective active status" rule for free: a child
  only appears if it's active AND its already-active-filtered parent is.
  Confirmed live against seeded data: Ahmedabad returns `is_leaf: false`
  with 15 active leaf children.

Both follow `CategoryController`/`CategoryResource` exactly. Tests:
`PlanEndpointTest` (6), `ZoneEndpointTest` (8) — covering the standalone-leaf
case (a top-level zone with no children is `is_leaf: true`, SPEC §8's
explicit resolution), inactive-parent-hides-active-child, and
not-paginated.

### Add Vendor step 2 — two new screens

`select_plan_module` (plan cards, single-select) → `select_services_module`
(categories/subcategories/zones, capped by the chosen plan's quota).
Confirmed decisions before building:

- Checking a subcategory auto-selects its parent category; checking a
  category cascades to select subcategories up to the *remaining* quota
  (not the full set) — proven by reverting the early-exit and watching the
  test overshoot. Unchecking a category cascades down; unchecking a
  subcategory leaves the category selected.
- Zones: a top-level zone with children renders as a non-selectable group
  header; a top-level zone with *no* children renders as a selectable leaf
  itself (SPEC §8's standalone-zone case) — not treated as a special case,
  since `is_leaf` already covers it.
- "Continue" validates and stops (toast) — Review/Subscribe (task 3.4+)
  don't exist yet, so there's nowhere real to navigate to. Matches how
  step 1 already ends.
- Step 1's "Save and continue" now navigates to step 2 on success.

8 new controller-level tests cover the cascade/quota rules directly
(`SelectServicesController`), independent of the API layer.

### Verified, and what wasn't

- Backend: full suite 317 passed; `/api/plans` and `/api/zones` hit live
  against real seeded data (Silver/Gold/Platinum with correct quotas;
  Ahmedabad with 15 correctly-ordered leaf children).
- Flutter: `flutter analyze` clean, 19 tests passed, two regressions proven
  meaningful by intentional revert (GPS body-omission, quota-capped
  cascade).
- **Not verified**: an actual on-device/emulator click-through of step 2's
  UI (no Android emulator available in this environment, and there's still
  no entry point from the salesman home screen into Add Vendor at all — a
  pre-existing gap, not new). The "skipped subcategories render visibly
  greyed, not just unselected" behavior is implemented and covered by the
  quota-state test, but not eyeballed on a real screen. Flag this before
  calling step 2 fully done.

### Still open

Review screen, Subscribe (task 3.4, `POST /api/subscriptions` +
`SubscriptionService` — doesn't exist yet), and the salesman-home →
Add Vendor entry point.

---

**Note (2026-08-20):** `Vendor` status transitions are currently plain
`$vendor->update(['status' => ...])` calls (no state-machine helper exists).
Reasonable with one call site (`SubscriptionService::subscribe()`, this
session), but Phase 4's verification-approval and Phase 7's expiry job will
each add another. Worth revisiting whether named methods
(`Vendor::activate()`/`expire()`) earn their keep once there are three call
sites — not before.

---

## 2026-08-20 — POST /api/subscriptions: SubscriptionService::subscribe()

**Status:** Complete for the confirmed scope (salesman/admin-led subscribe
+ free trial; upgrade/downgrade/add-on and vendor self-service are separate,
later work). 339 backend tests passing (was 317), 22 new. Verified live
against seeded data: cash sale, free-trial grant, idempotent replay, and a
multi-error quota/state rejection all confirmed via curl, with DB rows
checked directly (subscription_items, audit_logs) and test data cleaned up
after.

### Scope, decided before writing code

Asked and confirmed up front: this task builds salesman+admin only.
Self-service (`source=self`) is deferred — nothing anywhere creates a
Vendor profile row for a self-registered user, so a self-service caller
would have nothing to subscribe. That's now a flagged prerequisite for a
future task, not something this endpoint half-builds.

### The models were missing, not the tables

Task 1.4 already migrated `subscriptions`, `subscription_items`,
`payments`, `commissions`, and `settings` — but only `Subscription` had an
Eloquent model, marked "minimal... nothing here should be taken as the
finished shape." Added `Payment`, `Commission`, `SubscriptionItem`,
`Setting` from scratch; extended `Subscription` with the relations it was
missing (`vendor`, `salesman`, `items`, `payments`, `commission`) rather
than touching its existing columns.

### The idempotency-replay ordering bug, caught before it shipped

Laravel resolves and validates a type-hinted FormRequest *before* the
controller method body runs. Several of `StoreSubscriptionRequest`'s own
rules become false specifically *because* the first call succeeded —
`vendor.status` flips draft→active, a free-trial grant makes "phone
already used a trial" trip on itself, and the per-salesman-month count
now includes the row just created. A naive retry-after-drop-response
would therefore fail validation for the wrong reason: not because it's
invalid, but because it already worked.

Fixed with `HandleIdempotentSubscription`, route middleware that looks up
the `Idempotency-Key` and short-circuits with the original result *before*
`StoreSubscriptionRequest` is ever resolved — a genuine replay never
touches that validation at all. Proved this matters by removing the
middleware and re-running the replay test: it failed with a 422 instead of
returning the original subscription, exactly the bug this exists to
prevent. Restored, confirmed passing again.

A second layer catches the true race (two near-simultaneous requests with
a brand-new key, both passing the pre-check before either inserts): the
controller catches the `idempotency_key` unique-constraint violation and
returns the now-existing row instead of a 500, rather than trusting the
pre-check alone.

### Server re-verifies everything the Flutter step-2 screen already checked

Quota caps, subcategory-implies-category, and leaf-only zones are all
re-checked in `StoreSubscriptionRequest`, independently of the client's
own cascade-select UI (CLAUDE.md: server never trusts client-sent values).
A stale app build, a buggy client, or a direct API call all get the same
422, not a silent bypass.

### Money: the same float trap, in a new place

Commission `amount_paise = price_paise * rate_bps / 10000` — dividing by
10000 as a float can misround for the same reason
`Plan::rupeesToPaise()` already guards against (1/10000 has no exact
binary representation). Written as pure integer arithmetic
(`intdiv($pricePaise * $rate_bps + 5000, 10000)`, round-half-up) instead
of `round()` on a float division.

### Audit logging applied per SPEC §5.14

`Subscription`, `Payment`, `Commission` all take `RecordsAuditLog`
(`Category`/`Plan`/`PlanQuota`/`User` were the only prior users). No
snapshot overrides needed — unlike `Plan`, every fact worth logging here is
already a plain column, so the trait's default diff/full-log behavior is
enough. Verified live: a cash sale writes 3 audit rows (Subscription,
Payment, Commission), all correctly attributed to the acting salesman's
`user_id`.

### Two small fixes made along the way, both flagged TODOs this task
directly unblocked

1. `User::hasSalesmanAssignedActiveSubscription()` was hardcoded `false`
   with a TODO reading "replace once Phase 3 fills in salesman-sold
   subscriptions" — now a real query. Added tests for both directions
   (finds a salesman-sourced active one, ignores an expired or
   self-service one) — the existing seam test only proved the delegation
   worked, never the query itself.
2. `CreateUser::generatePassword()`'s alphabet/logic moved to
   `User::generateTemporaryPassword()`, since `SubscriptionService` needs
   the identical "no ambiguous 0/O/1/l/I" generator for the vendor's
   Subscribe-time temp password (SPEC §2.2: shared via WhatsApp). Filament
   now delegates to the model instead of duplicating it.

### Verification performed

- Backend: 339 passed, 21,206 assertions (22 new: 20 in
  `SubscriptionEndpointTest`, 2 in `LoginVerificationGateTest`).
- Two regressions proven meaningful by intentional revert: the idempotency
  middleware (removed → replay test fails with 422; restored → passes),
  and the quota-capped cascade from the previous session (unaffected,
  re-confirmed still passing).
- Live: cash sale (vendor→active, payment captured/unverified, commission
  pending, 3 audit rows), free-trial grant (price/commission both zero,
  correct duration from `free_trial_days` not the plan), idempotent
  replay (same subscription id, no `temporary_password` on the replay),
  and a deliberately-bad request returning multiple field errors at once
  (already-active vendor + invalid/over-quota subcategories). Live rows
  and users cleaned up afterward.

### Still open

Vendor self-service subscribe (blocked on self-registration creating a
Vendor profile row — flagged as a build-plan prerequisite, not started).
Upgrade/downgrade/add-on (SPEC §3.6, `previous_subscription_id` stays
null). A Filament admin view for Subscriptions/Payments/Commissions
(SPEC §5.4) — not built, not asked for here. The commission
pending→paid transition (admin verifying cash reconciliation, SPEC §5.9)
— schema-ready (`admin_verified_at`, `Commission.status`), no admin UI or
endpoint yet.

---

## 2026-08-20 — Flutter Subscribe: payment mode, POST /api/subscriptions, WhatsApp confirmation

**Status:** Complete. Add Vendor flow is now truly end-to-end: step 1
(draft) → plan → categories/subcategories/zones → payment mode → real
subscribe call → confirmation screen with the one-time temp credentials,
ready to share. 24 Flutter tests passing (was 19), `flutter analyze` clean.

### What select_services_module's "Continue" actually does now

Previously it validated selections and stopped — task 3.4 (backend
Subscribe) didn't exist yet. Now: validate → payment-mode dialog (Cash/
Online, per SPEC §2.2's "choose payment mode → confirmation dialog →
single API call") → `POST /api/subscriptions` with a client-generated
`Idempotency-Key` (the `uuid` package — server enforces it via a unique
column, task 3.4) → on success, clears the stored draft id and navigates
to the new confirmation screen with the returned `temporary_password`.

"Join as Free" deliberately stays out — confirmed before starting. It's
SPEC's own separate "Alternative" flow with its own duration picker capped
by a live Settings value, not a variant of this one. Queued as task 3.6b:
reuses the confirmation screen this session builds.

### New module: subscription_confirmation_module

Shows the vendor's login email and one-time temp password, a plan/price
summary, and two actions: Share via WhatsApp (`share_plus`'s native share
sheet — confirmed over a `wa.me` deep link, since it works without
WhatsApp installed and doesn't assume a WhatsApp-reachable phone format)
and Done (returns to `SalesmanHomeView`, the established placeholder
destination for "flow complete").

### A real layout bug the tests caught

The confirmation screen's content wasn't wrapped in a scrolling container
— `flutter test` failed with a `RenderFlex overflowed` error on the first
run against the test harness's viewport, which would have reproduced on
any smaller phone screen too. Fixed: `Column` → `SingleChildScrollView`.
Caught before ever touching a device, which is exactly why the plan called
for the tests to actually pump the resulting screen rather than only
testing controller state.

### Threading vendor identity forward, not re-fetching it

The subscribe response only returns `{id, status}` for the vendor (by
design — task 3.4 didn't need more). Rather than adding a backend field or
an extra `vendorShowAPI` round-trip mid-flow, `business_name` and the login
`email` — both already typed in step 1 — are threaded through the existing
navigation chain (`AddVendorController` → `SelectPlanView` →
`SelectServicesView` → the confirmation screen) as constructor params,
matching how `vendorId`/`plan` already flow forward. Keeps this task
Flutter-only, as scoped.

### Verified

- `flutter analyze` clean, 24 tests passing (5 new: payment mode + valid
  UUID Idempotency-Key sent, defaults to cash, a server rejection doesn't
  navigate, the share message contains the right credentials, plus the
  scroll-overflow catch above).
- Proved the Idempotency-Key test meaningful by swapping in a malformed
  key and watching the UUID-format assertion fail, then restoring it.
- **Not verified**: an actual on-device click-through (no Android emulator
  in this environment, and salesman-home still has no entry point into Add
  Vendor at all — both pre-existing gaps, unchanged by this session).

### Still open

Task 3.6b ("Join as Free" UI). The salesman-home → Add Vendor entry point.
An on-device pass once either is available.

---

## 2026-08-20 — Join as Free: payment-mode option, live-capped duration picker (task 3.6b)

**Status:** Complete. "Join as Free" now sits alongside Cash/Online in the
existing payment-mode dialog, with a duration stepper capped by a live
`free_trial_max_days` Setting — reuses the same `subscribeAPI()` call and
the same confirmation screen from task 3.6 unchanged, exactly as scoped.
Backend: 344 tests passing (was 339). Flutter: 30 tests passing (was 24),
`flutter analyze` clean.

### The gap found before writing any Flutter code

"Fetch it, don't hardcode 15" turned out to require new backend work:
nothing exposed any `Setting` value to a client at all — `Setting::get()`
was only ever called server-side, inside `StoreSubscriptionRequest`'s own
validation (tasks 3.4/3.5). Confirmed in scope before starting: a new
`GET /api/settings` returning a whitelisted subset (currently just
`free_trial_max_days`), not the whole table — same shape as
`/api/plans`/`/api/zones`/`/api/categories`, so a second Settings-driven UI
later just adds a key rather than a new route. `SettingEndpointTest`
specifically proves it's a live read, not cached: changes a `Setting` row
mid-test and asserts the response changes.

### The default that isn't the max

Confirmed before building: the duration picker defaults to 7 days, not
`freeTrialMaxDays`. Reasoning given: defaulting to the cap would make
"grant the longest allowed trial" the path of least resistance instead of
a deliberate choice — reaching the cap now takes an active adjustment via
the stepper, bounded `[1, freeTrialMaxDays]` both directions.

### What changed, precisely

- Backend: `SettingController@index`, `GET /api/settings` route.
- `SelectServicesController`: `selectedPaymentMode` gains `'free'`;
  `freeTrialMaxDays`/`freeTrialDays` state; `fetchMasterDataAPI()` now
  fetches settings alongside categories/zones (best-effort — a failed
  settings fetch keeps the safe 15-day fallback rather than blocking the
  whole screen); `subscribeAPI()`'s body includes `free_trial_days` only
  when mode is free (same omit-don't-send-null pattern as `latitude`/
  `longitude` in step 1); `free_trial_days` field errors (covers all three
  server-side guards — max days, monthly cap, phone-already-used) now
  surface instead of falling through to a generic message.
- `select_services_view.dart`: third payment-mode row, and a duration
  stepper shown only when "Join as Free" is selected, reusing the dialog's
  existing `GetBuilder` so it rebuilds live like the radio rows already do.
  No changes to `subscription_confirmation_module` — the flow converges
  after subscribe regardless of mode, exactly as instructed.

### Verified

- Backend: `SettingEndpointTest` (5 tests) + full suite, 344 passed.
- Flutter: `flutter analyze` clean, 30 tests (6 new: free-trial body
  correct, cash/online omit `free_trial_days` entirely, a server rejection
  on that field surfaces, the stepper clamps both directions, the 7-day
  default, and `fetchMasterDataAPI` actually parses and clamps to a live
  fetched max). The omission behavior proven meaningful by reverting it
  (sent an explicit `free_trial_days` for cash) and watching the test fail
  before restoring it.
- Live: `GET /api/settings` confirmed live (changed the row mid-session,
  response changed, reverted); full free-trial subscribe against the
  running backend returned `price_paise: 0`, `duration_days: 7`,
  `commission: null`; a 30-day request against the 15-day cap correctly
  rejected on `free_trial_days` with both the cap and phone-reuse messages.
  Live rows and users cleaned up afterward.

### Still open

The salesman-home → Add Vendor entry point (pre-existing gap, unchanged).
An on-device pass once either that or an emulator is available — same
caveat as tasks 3.6/3.2, unchanged by this session.

---

## 2026-08-20 — Salesman home: My Vendors + Earnings tabs (SPEC §2.3/§2.4)

**Status:** Complete. `salesman_home_module`'s placeholder is gone —
replaced with a real `DefaultTabController` (My Vendors, Earnings), backed
by two new "my own records" endpoints, the first `/me/...` routes in this
API. Backend: 355 tests passing (was 344). Flutter: 37 tests passing (was
30), `flutter analyze` clean.

### `monthly_target_paise` already existed — the task's own premise was wrong

Checked before writing anything: `monthly_target_paise` is already a
fillable, cast column on `salesmen` (from an earlier migration), so
"flag rather than invent a number" didn't actually apply. But wiring
"target vs achieved" surfaced a real, separate ambiguity: SPEC doesn't say
whether "achieved" means commission earned this month or total sale value
(`price_paise`) sold this month — these are different numbers (commission
is a fraction of sale price). Confirmed scope: build totals only, leave
that comparison for a deliberate follow-up decision rather than guessing.

**Open question, explicitly flagged for whoever picks this up next:**
should "achieved" (against `monthly_target_paise`) be defined as this
salesman's commission `amount_paise` earned this calendar month, or the
`price_paise` of subscriptions they sold this month? My lean is commission
earned — the column reads as a personal-earnings target, not a sales-volume
one — but this is undecided, not implemented either way.

### Backend: two endpoints, one new relation

`SalesmanController@vendors`/`@commissions`, both scoped to
`$request->user()->salesman` (no route-bound id, so no ownership check to
get wrong) under a new `role:salesman`-only route group (not
`salesman,admin` — there's no "own vendors" concept for an admin here).
Added the missing `Salesman::commissions(): HasMany` — `vendors()` already
existed as the equivalent for vendors.

`SalesmanVendorResource` takes each vendor's **most recent** subscription
regardless of status, not filtered to active-only: a draft vendor has none
(`plan_name`/`days_to_expiry` both `null`), an expired vendor still shows
its last plan with a negative days-to-expiry ("−5") rather than going
blank — more informative than hiding it. `days_to_expiry` is computed
(`diffInDays`), never stored.

**No leads column, anywhere** — not a placeholder, not a fake zero. Phase
5's leads table doesn't exist; the backend resource omits the field
entirely and the Flutter row layout has no space reserved for it.

**A real bug caught by `sum('amount_paise')`**: MariaDB/PDO returns
aggregate `SUM()` as a string, not an int — the first commission-totals
test failed on `'3000'` vs `3000` before casting `(int)` explicitly.

### Flutter: two new modules, salesman_home restructured

`my_vendors_module/` and `earnings_module/` follow the established
controller+view/`GetBuilder`+`update()` pattern exactly. `SalesmanHomeView`
stays a const no-arg widget — wrapped in `DefaultTabController` internally,
so all four existing call sites (`app.dart`, login, change-password,
subscription-confirmation's "Done") needed no changes.

A widget-level test caught a real formatting bug the same way the
`RenderFlex` overflow was caught in task 3.6: the negative-days text was
initially built without negating the number, which would have rendered
"Expired -4 days ago" instead of "Expired 4 days ago". Proven meaningful
by reverting the fix and watching the test fail before restoring it.

### Verified

- Backend: 2 new test files (11 tests), full suite 355 passed.
- Flutter: `flutter analyze` clean, 37 passed (7 new — including two
  widget-pump tests that actually render the views, not just assert on
  controller state, specifically to catch layout/formatting bugs before a
  device would).
- Live: seeded a salesman with a draft vendor (null plan/days), an active
  vendor (+12 days), an expired vendor (−5 days), one pending and one paid
  commission; both endpoints returned exactly the expected shape and
  numbers via `curl`. Live rows and users cleaned up afterward.

### Still open

The `monthly_target_paise` "achieved" definition (flagged above). Profile
tab (SPEC §2.5) — not asked for here. The salesman-home → Add Vendor entry
point (pre-existing gap, still unchanged — this session added tabs
alongside it, not a way to reach it). An on-device pass — same standing
caveat as tasks 3.2/3.6/3.6b, no emulator available in this environment.

---

## 2026-08-20 — Vendor flavor: login + self-registration (task 4.1)

**Status:** Complete. Vendor flavor now has real login and self-registration
— `app.dart`'s `Flavor.vendor` case no longer unconditionally hard-routes
to the dead `CreateAccountView()` placeholder regardless of login state.
Backend: 366 tests passing (was 355). Flutter: 46 tests passing (was 37),
`flutter analyze` clean.

### The gap task 3.4 flagged, closed

Self-registration created only a `User` row — confirmed by reading
`AuthController::register()` before touching it. Now, when `role=vendor`,
a `Vendor` row is created in the same `DB::transaction()`, same
half-applied-create risk `VendorDraftService` already guards against (an
orphaned `users` row squatting the unique email index with nothing
reachable behind it).

**The `draft` vs `pending_verification` question, resolved before writing
code, not left ambiguous:** `Vendor.php`'s and `VendorDraftService`'s own
docblocks say "`pending_verification` is for self-registered vendors
only," which reads like a contradiction of "start at `draft`." It isn't —
that comment describes which flows *ever pass through*
`pending_verification` (only self-registered, never salesman-led), not
what self-registration starts at. Per the lifecycle
(`draft → pending_payment → pending_verification → active`),
self-registration reaches `pending_verification` later, at the
self-service Subscribe step (task 4.2, not built) — exactly like the
salesman flow only reaches `active` at *its* Subscribe step. Both start
at `draft`.

**The one real scope gap this surfaced**: `vendors.business_name` and
`vendors.phone` are `NOT NULL` (`phone` also unique), but `RegisterRequest`
collected neither. Added both as `required_if:role,vendor`. `owner_name`
did **not** need its own field — the salesman-led flow's own
`VendorDraftService` already treats the account `name` as the owner's
name, so self-registration reuses `name` for both `users.name` and
`vendors.owner_name` rather than asking twice.

### `GET /api/vendors/me` — built for task 4.2, not consumed by this one

Scoped off `$request->user()->vendor` (no route-bound id), mirroring
`SalesmanController`'s established "my own record" shape exactly. Had to
be registered **before** the existing `GET /vendors/{vendor}` wildcard
route in `routes/api.php` — otherwise Laravel would route-model-bind "me"
as a vendor id and 404/error before ever reaching the new route. Added one
field to `VendorResource`, `has_active_subscription` — the concrete thing
SPEC §3.2's "check for an existing active subscription" needs to query,
verified live (`false` for a fresh draft vendor).

This task deliberately does **not** call the new endpoint from Flutter at
all — wiring "check subscription → branch to plan-selection or dashboard"
is task 4.2's job, per the task's own framing. `VendorHomeView` is a bare
placeholder (mirroring `salesman_home_module`'s original pre-tabs shape),
same as `SalesmanHomeView` started before Phase 3 filled it in.

### Flutter: two new auth screens, one small new screen, no vendor-home build-out

`vendor_login_module` mirrors `salesman_login_module`'s shape closely,
plus a login↔register switch link and a redirect to a new "check your
email" screen on `EMAIL_NOT_VERIFIED` (a concrete next action, not just a
toast). `vendor_register_module` is built fresh — `create_account_module`
was confirmed to be dead scaffolding (a single-field name capture posting
to an unrelated endpoint, never calling `registerAPI`), not a usable
template. `email_verification_pending_module` is genuinely new UI — the
`resendVerificationAPI()` method and `verifyEmail`/`resendVerification`
string keys existed already but were wired to nothing anywhere until now.

### Verified

- Backend: extended `RegisterTest` (7 new cases) + new
  `VendorMeEndpointTest` (6 cases), full suite 366 passed. Two pre-existing
  tests (`LoginVerificationGateTest`, `EmailVerificationTest`) needed their
  vendor-registration payloads updated with the newly-required fields —
  caught immediately by the first full-suite run, not shipped broken.
- Flutter: `flutter analyze` clean, 46 passed (9 new — first test coverage
  for any auth-flow controller in this app; no prior precedent existed to
  match). Proved the "role comes from FlavorConfig, never a form field"
  test meaningful by hardcoding `role: 'customer'` and watching it fail
  before restoring it.
- Live: registered a vendor via `curl`, confirmed the `vendors` row
  (`owner_name` mirroring `name`, `status=draft`, `created_by_salesman_id`
  null); `GET /api/vendors/me` returned `has_active_subscription: false`
  before any subscription existed; verified the email and confirmed login
  then succeeds. Live rows and tokens cleaned up afterward.

### Still open

**Task 4.1b, queued**: confirm whether `UserResource`/admin-created vendor
accounts (task 2.1, User Management) have the same orphaned-vendor-row gap
self-registration just had, and fix it if so. Task 4.2 (self-service
subscribe, the actual consumer of `has_active_subscription`). Vendor
dashboard/leads/subscription UI (SPEC §3, beyond the bare placeholder).
An on-device pass — same standing caveat as every prior Flutter session,
no emulator available in this environment.

---

## 2026-08-21 — Task 4.1b: closed, no bug found

Investigated whether `UserResource` (task 2.1) lets an admin create a
`role=vendor` user with no paired `Vendor` row, per the premise that it
predated `business_name`/`phone` as requirements. It didn't, and it
already doesn't.

`UserResource`'s Vendor profile fieldset
(`app/Filament/Resources/UserResource.php:165-205`) has required
`business_name`, `owner_name`, and `phone` since task 2.1, bound via
`->relationship('vendor', condition: ...)` — Filament's standard
mechanism for validating and creating a paired related model alongside
the parent record. Confirmed by running the existing tests, not just
reading the code: `test_an_admin_created_vendor_starts_as_a_draft` and
`test_only_the_matching_profile_row_is_created` both pass, asserting the
`Vendor` row exists with the right fields and that no stray profile rows
get created for other roles.

The premise was wrong: `RegisterRequest` (self-registration) and
`UserResource` (admin-created) are separate forms with separate
histories. Only `RegisterRequest` was missing `business_name`/`phone` —
that's what task 4.1 fixed. `UserResource` never had the gap.

No code changes made.

---

## 2026-08-21 — Task 4.3: vendor verification queue (admin panel)

SPEC §5.8's "Vendor Verification Queue" module: pending self-registered
vendors, their KYC docs, Approve/Reject with reason. `pending_verification`
(task 4.2 puts vendors there) had nothing reviewing it until now.

### Two judgment calls, resolved

**What Reject transitions the vendor to.** The vendors status enum had no
rejected state, and by the time an admin reviews a self-service vendor
they already have a live subscription (task 4.2 creates it before
verification happens) — reverting to `draft` would silently erase both
that a rejection happened and that a paid subscription exists. Added
`rejected` as a new enum value via a raw `DB::statement` migration
(`ALTER TABLE ... MODIFY ... ENUM(...)` — Laravel's `->change()` isn't
reliably supported for enum columns via Doctrine DBAL). **CLAUDE.md now
documents this as a standing convention** for any future lifecycle gaining
a new state, since this is the first of what will be several.

**How deep the push-notification stub goes**: a `PushNotificationService`
with `notifyVendorApproved()`/`notifyVendorRejected()` methods that are
genuine no-ops (matching `PaymentService`'s seam-method precedent), each
docblocked to point at the already-migrated `notifications` table — its
own docblock names `verification_approved` as a worked example trigger
type — for Phase 7 to write into. No new `Notification` model was built;
that table's real consumer is the Phase 7 admin composer.

### What was built

- Migration: `rejected` added to `vendors.status`.
- `Vendor` model: `scopePendingVerification()` (mirrors `scopeActive()`),
  `verifiedBy()` relation (the `verified_by` column existed but was
  unused until now), and `use RecordsAuditLog;` — Vendor didn't have it
  yet; an approve/reject decision is exactly the kind of action SPEC's
  audit trail exists for, and it auto-hooks on `static::updated` with no
  extra call needed.
- `VendorVerificationService::approve()/reject()` — the actual state
  transition, called from both the table's inline actions and the view
  page's header actions so the transition logic lives in one place
  despite Filament using different Action classes for each.
- `VendorPolicy` (new) + `vendors.viewAny`/`vendors.verify` permissions —
  no create/update/delete permissions, since this queue only transitions
  status, never creates/edits/deletes a vendor.
- `VendorVerificationResource` — a dedicated queue (`getEloquentQuery()`
  scoped to `pendingVerification()`, not a toggleable filter), business
  details + KYC docs on a `ViewRecord` Infolist page. Shop photo shown via
  `ImageEntry` resolved through `Vendor::fileUrl()` (the row's own `disk`,
  not a fixed one — no existing resource needed this before, since
  `CategoryResource`'s icon uses a single fixed disk). ID proof shown as a
  link rather than an image embed, since it isn't guaranteed to be one.
  No Create page, no Delete action — vendors aren't created or deleted
  through this queue.

### Verified

- Backend: new `VendorVerificationResourceTest` (9 cases — queue scoping,
  approve/reject transitions and their audit trail, reason required on
  reject, no create action, permission gating for both directions).
  `PolicyCoverageTest`/`PermissionTest` needed no changes and still pass,
  confirming the new policy/permissions slot into the existing
  fail-closed machinery correctly. Full suite: 384 passed (up from 374).
- Live: via tinker — two vendors created at `pending_verification`,
  confirmed both in the queue; approved one (`status=active`,
  `verified_by`/`verified_at` set, 2 audit log entries) and rejected the
  other with a reason (`status=rejected`, reason stored, 2 audit log
  entries); confirmed both left the queue afterward. Rows cleaned up.

### Still open — not blocking, flagged for later

When a vendor is rejected, their already-created `Subscription`/`Payment`
rows (task 4.2 creates these before verification happens) are left
untouched — the subscription itself still reads `status=active` at its
own level; `vendor.status=rejected` is the only gate. Functionally safe
today since customer search filters on `vendor.status` (SPEC §4.4), not
subscription status directly, but it's a real decision someone needs to
make before Phase 9's payment gateway work lands: does rejection
void/refund the subscription, or does vendor-level status remain the sole
source of truth permanently, with the subscription row just becoming
inert data?

---

## 2026-08-21 — Task 4.2: vendor self-service — dashboard-or-subscribe branch on login

Wires the SPEC §3.2 vendor-login branch: `has_active_subscription` →
dashboard, none → subscribe. The subscribe path reuses
`POST /api/subscriptions` (task 3.4), opened to a vendor caller for the
first time — self-service (`source=self`, mode always online, vendor
status → `pending_verification`, no commission) was explicitly deferred
to this exact moment.

### Backend: five precise changes to open self-service subscribe

Per the plan, shown and confirmed before any Flutter work started:

1. **Route middleware** — `POST /subscriptions` moved out of the
   `role:salesman,admin` group into its own
   `role:salesman,admin,vendor` group in `routes/api.php`, leaving the
   vendor-draft routes (`/vendors/draft`, `/vendors/{vendor}/kyc`)
   untouched in their existing group.
2. **`StoreSubscriptionRequest::validateVendor()`** — added the missing
   vendor-ownership branch. Before this, a vendor caller fell through
   with **zero ownership check** (`vendor_id` could theoretically be
   anyone's) since only the salesman branch existed. Now:
   `$actor->role === UserRole::Vendor` requires
   `vendor_id === $actor->vendor?->id`.
3. **Payment-mode restriction** — a new `withValidator()` check rejects
   `cash`/`free` for a vendor caller; self-service is always
   `payment_mode=online` (a vendor can't hand themselves cash, and a
   free trial is a salesman-granted concept, not self-service).
4. **`SubscriptionService::subscribe()` — three branches**:
   - `source`: added a third branch, `'self'`, alongside
     `'salesman'`/`'admin'`.
   - `vendor.status`: branches to `pending_verification` for self-service
     vs `active` for salesman/admin-led — a self-registered vendor
     subscribing themselves hasn't been met in person, unlike a
     salesman-added one.
   - **The priority fix**: the temp-password reset was previously
     *unconditional* — correct only for salesman/admin-led subscriptions,
     where the vendor's password is an unusable random string nobody was
     given. For self-service, resetting the vendor's own chosen password
     and forcing `must_change_password` would have locked them out of the
     account they just used to log in and subscribe. Now skipped entirely
     when the actor is a vendor; `temporary_password` is `null` in that
     case. Proved meaningful by reverting to the unconditional reset and
     watching `test_self_service_does_not_reset_the_vendors_own_password`
     fail, then restoring.
5. **`SubscriptionController::store()`** response — `temporary_password`
   is now nullable (present for salesman/admin, `null` for self); the
   Flutter self-service flow never reads it.

Commission creation needed no change — `$isSalesman` is already `false`
for a vendor caller, so SPEC §12's "never on self-service" already held
by construction.

### `GET /api/vendors/me` extended with dashboard data

`VendorController::me()` gained a second top-level key,
`active_subscription`, built directly in the controller (same
"aggregate built in the controller" precedent as
`SalesmanController::commissions()`): plan name, end date, days
remaining, and used/max quota per resource (categories, subcategories,
zones), the last computed from `subscription_items` grouped by
`item_type`. `null` when there's no active subscription. **Photos/videos
quota omitted** — same reasoning as leads in task 3.7: Phase 5's
portfolio upload doesn't exist yet, so "used" would always be a fake
zero.

### Flutter: fresh self-service modules, not the salesman-flow screens reused

`select_plan_module`/`select_services_module` are wired specifically for
the salesman flow (`businessName`/`loginEmail` threaded through to a
WhatsApp confirmation screen) — neither applies to a vendor already
logged in as themselves, so new modules were built rather than branching
the existing ones on caller role:

- **`vendor_landing_module`** (new) — the single shared gate every
  post-auth vendor entry point routes through (login, register,
  already-logged-in app boot). Calls `GET /vendors/me`, branches to
  `VendorDashboardView` or `VendorSelectPlanView` on
  `has_active_subscription`, shows a retry state on failure. Replaces
  the bare `vendor_home_module` placeholder from task 4.1 entirely —
  that module was deleted, not kept alongside it.
- **`vendor_dashboard_module`** (new) — plan name, days remaining, a
  quota bar per resource. Fetches its own data on every entry (not just
  what login handed forward) so returning to the app later still shows
  live numbers. No leads, no rating — omitted entirely rather than
  stubbed, same approach as task 3.7's My Vendors tab.
- **`vendor_select_plan_module` + `vendor_select_services_module`**
  (new) — resolve `vendorId` themselves from `GET /vendors/me` rather
  than taking one as a constructor argument, matching how `/vendors/me`
  and `/salesmen/me/*` already avoid a client-supplied id. No
  payment-mode dialog — mode is always `online`, so Continue submits
  directly once categories/subcategories/zones are picked. Success
  navigates to `VendorDashboardView`, not a confirmation screen.
- `vendor_login_module`, `vendor_register_module`, and `app.dart`'s
  already-logged-in branch all rewired from `VendorHomeView` to
  `VendorLandingView`.

### Verified

- Backend: extended `SubscriptionEndpointTest` (5 new cases covering the
  vendor-caller path — self-subscribe succeeds with `source=self`,
  `status=pending_verification`, no commission, no password reset;
  cash/free rejected; cross-vendor subscribe blocked) and
  `VendorMeEndpointTest` (3 new cases for the `active_subscription`
  payload). Full suite: 374 passed, 21,309 assertions (up from 366).
- Flutter: `flutter analyze` clean. Four new controller-test files —
  `vendor_landing_controller_test.dart`,
  `vendor_dashboard_controller_test.dart`,
  `vendor_select_plan_controller_test.dart`,
  `vendor_select_services_controller_test.dart` — following the
  established fake-`DataSource` pattern, including a widget-pump test on
  the dashboard's plan/quota rendering and its no-subscription fallback
  state. `vendor_auth_test.dart` updated for the `VendorHomeView` →
  `VendorLandingView` rename. Full suite: 63 passed (up from 46).
- Live: registered a vendor, verified email, subscribed via `curl` as
  that vendor's own token with `payment_mode=online` — confirmed
  `source=self`, `vendor.status=pending_verification`, `commission=null`,
  `temporary_password=null`. **Then logged in again with the vendor's
  original password** (not just read from code, per explicit
  instruction) — succeeded, proving the password-reset-skip fix is real.
  Confirmed `GET /api/vendors/me` returns the correct plan/quota/days
  detail matching the selections made. All live rows and tokens cleaned
  up afterward.

### Still open

An on-device Flutter pass — same standing caveat as every prior session,
no emulator available in this environment.

---

## 2026-08-22 — Task 4.4: ongoing vendor services management (add within remaining quota)

### Survey first, per instruction

Confirmed before building anything: task 4.2 built only the one-time
initial-subscribe flow, gated so `POST /api/subscriptions` flatly rejects
any vendor whose `status !== 'draft'` — an already-active vendor couldn't
reach that endpoint at all, let alone hit a quota check. No "remaining
quota" concept existed anywhere (`SubscriptionItem` rows are inserted
once, per-subscription, never diffed against). `GET /vendors/me` returned
used/max counts only, never which categories/subcategories/zones were
actually selected. SPEC §3.3 ("select within remaining quota, enforced
server-side") names this as real, unbuilt scope, distinct from §3.6's
much larger Upgrade/Downgrade/Add-on machinery (credited days, quota
purchases, forced deselection) — this task is deliberately §3.3's narrow
slice only: filling already-paid-for, currently-unused quota on the
existing active subscription. Upgrade/downgrade/add-on-purchases/renewal
remain untouched.

### Backend

- **`ServiceSelectionValidator`** (new, `app/Support/`) — the three
  structural checks (category active, subcategory belongs to a selected
  category, leaf-zone-only) extracted out of
  `StoreSubscriptionRequest::validateSelections()` into a shared static
  method, since the new endpoint needs the exact same rules. The two
  FormRequests stay separate — only this proven-identical part moved.
  Per your explicit ask, a **drift-detection test** submits the same
  invalid input (a non-leaf zone, a subcategory without its category) to
  both `POST /subscriptions` and the new endpoint and asserts they reject
  identically — it fails specifically if a future edit ever lets one
  call site stop using the shared class, not if either endpoint breaks
  on its own.
- **`Vendor::currentActiveSubscription()`** (new) — extracted the query
  `VendorController` already ran inline, now shared by both `me()` and
  the new endpoint.
- **`POST /api/vendors/me/services`** (new, `role:vendor` only, no id in
  the URL — resolves everything from the caller's own current active
  subscription, same "never trust a client id" shape as `/vendors/me`).
  `AddSubscriptionItemsRequest` computes `submitted - existing` per
  dimension; only that diff is structurally validated and quota-checked
  (`existing + new > max` is the rejection, not `submitted > max`) —
  this is the actual point of the task. **Purely additive by
  construction**: the service only ever inserts the diff, so omitting an
  existing item from a submission is simply never acted on — there is no
  removal code path to guard against, matching the "omission is the
  enforcement" shape already used for master data's missing delete
  action.
- **`GET /vendors/me`** extended: `active_subscription.items` now lists
  the actual selected `{id, name}` pairs per resource, hydrated from
  `Category`/`Subcategory`/`Zone` by id (not filtered to currently-active
  rows — deactivating a category doesn't retroactively drop a vendor's
  existing selection).
- `SubscriptionService::addItems()` — thin diff-and-insert, reuses the
  existing private `recordItems()` insert helper unchanged.

### Flutter

- **`vendor_select_services_module` extended in place, not duplicated.**
  `VendorSelectServicesController` gained optional constructor params
  (`isAddingMore`, three `existing*Ids` sets, empty by default — the
  initial-subscribe call site needed zero changes) that pre-seed the
  same selection state the existing toggle/cascade/quota-cap logic
  already reads, so that logic needed no changes at all. The view locks
  existing (pre-selected) items so they can't be unchecked. A new
  `addServicesAPI()` sends only the genuinely-new ids to the new
  endpoint (no vendor_id/plan_id/payment_mode — everything resolves
  server-side). Pre-seeding moved into the constructor body rather than
  `onInit()` — it's synchronous set manipulation, not a fetch, and
  keeping it out of `onInit()` let a controller test exercise it without
  also triggering the (unstubbed) master-data network calls.
- **Vendor dashboard gained an Overview/Services tab split** — same
  `DefaultTabController`/`TabBar`/`TabBarView` shape
  `salesman_home_view.dart` already established, but **deliberately NOT
  split into two separate GetX modules** the way My Vendors/Earnings
  are: those are genuinely independent data with their own fetches;
  Overview and Services here are two views over the exact same
  `GET /vendors/me` response, so splitting them would risk two
  independent fetches of the same data disagreeing with each other
  mid-session. `VendorDashboardController` keeps its single fetch; the
  view adds the tab chrome around two builder methods reading the same
  `controller.vendorMe`. Services tab shows the actual selected names
  per resource plus "X of Y (Z remaining)", with an "Add more services"
  button that navigates into the extended picker and refetches on
  return.

### Verified

- Backend: new `AddSubscriptionItemsEndpointTest` (12 cases, including
  the cross-endpoint drift-detection test) + 2 new `VendorMeEndpointTest`
  cases for the `items` block. Full suite: **399 passed** (up from 384).
- Flutter: `flutter analyze` clean. New assertions in
  `vendor_dashboard_controller_test.dart` (Services tab renders names +
  remaining quota) and 5 new cases in
  `vendor_select_services_controller_test.dart` (locked items, combined
  quota counting, correct request body shape, no-op on nothing-new,
  failure handling). Full suite: **69 passed** (up from 63).
- Live: created a vendor with an active subscription already using 1 of
  3 categories. `curl`'d the new endpoint — adding 1 more succeeded
  (`used: 2`), adding a 3rd exactly filled the plan (`used: 3`), a 4th
  was rejected citing **"0 remaining"** (the remaining-quota check, not
  the absolute max). `GET /vendors/me` reflected each change immediately.
  All live rows, categories, and tokens cleaned up afterward — confirmed
  clean against a direct query (the only name matches remaining were
  pre-existing seeded categories, unrelated ids).

---

## 2026-08-22 — Task 4.5: vendor portfolio media upload

### Survey first, per instruction

`Media` model didn't exist; the `media` table did, reserved exactly for
this — PROGRESS.md's own earlier note said applying `TracksFileDisk` to it
was Phase 4's job (`fileDiskPathColumn()` → `'path'`, no
`fileDiskPathColumns()` override needed, single file per row unlike
Vendor's KYC pair). Vendor KYC upload
(`VendorDraftController::storeKyc()`/`StoreKycRequest`) was the direct
precedent for the file-handling shape; `AddSubscriptionItemsRequest`
(task 4.4) was the direct precedent for "remaining, not absolute" quota
enforcement. `PlanQuota.max_photos`/`max_videos` already existed as
columns, exposed read-only in `PlanResource`, enforced nowhere. No
Filament resource in the app used a grid layout yet.

### The video-compression gap, flagged rather than skipped

Real client-side video transcoding needs either a native-encoder wrapper
(`video_compress` — unmaintained) or an FFmpeg wrapper
(`ffmpeg_kit_flutter` — upstream archived in a 2025 licensing dispute).
Neither is a responsible dependency to add today. **Fallback shipped
instead**: `ImagePicker.pickVideo(maxDuration: 60s)` caps length at
capture; a picked file over **50 MB** is rejected client-side before any
upload attempt (`VendorPortfolioController.isVideoTooLarge()`); the server
enforces the same 50 MB ceiling as the actual authority
(`mimetypes:video/mp4,video/quicktime,video/webm` + `max:51200` in
`StorePortfolioMediaRequest`). Photos get real compression via
`flutter_image_compress` (quality 80, 1600px max dimension) — that half
of SPEC's requirement is fully met. **Added to the Before Launch
Checklist below** so the gap isn't silently forgotten.

### Backend

- `Media` model (new): `TracksFileDisk`, `RecordsAuditLog` (a moderation
  decision is exactly the kind of action this project already audits),
  `morphTo('mediable')`, `scopePending()`. `Vendor::media(): MorphMany`
  added.
- **Quota semantics, decided explicitly**: a `pending` upload counts
  toward quota the same as `approved` — only a `rejected` upload frees
  its slot back up. Otherwise "quota-capped" is meaningless: a vendor
  could upload past the cap and just wait in the queue.
- **`POST /api/vendors/me/portfolio`** (new) — `StorePortfolioMediaRequest`
  resolves `Vendor::currentActiveSubscription()` (task 4.4), validates the
  declared `subcategory_id` is one the vendor currently offers (SPEC's
  "under each subcategory" is real scoping, not decoration), and rejects
  when `existingCount + 1 > max` for the declared type — citing remaining
  quota, same arithmetic shape as task 4.4's check but **deliberately not
  extracted into a shared helper**: that extraction (`ServiceSelectionValidator`)
  was correctness rules with real business meaning; this is a single
  trivial inequality reused across two otherwise-unrelated domains, and
  forcing an abstraction over it would add more indirection than it saves.
  `VendorPortfolioController::store()` writes the file
  (`vendor-portfolio/{vendor->id}`, same disk-resolution shape as
  `storeKyc()`) and creates the row at `moderation_status = pending`.
- **`GET /api/vendors/me/portfolio`** (new, same controller) — the read
  companion: the vendor's own uploads plus current quota, without which
  the Flutter screen would be an upload button with no gallery.
- **`GET /api/vendors/me`**: the `quota` block's old comment said
  photos/videos were "deliberately absent — Phase 4's portfolio upload
  doesn't exist yet." That premise is what this task changes — `photos`/
  `videos` used/max now sit alongside categories/subcategories/zones.
- `MediaModerationService` (new, mirrors `VendorVerificationService`) —
  `approve()`/`reject()`. No push-notification seam — not asked for in
  this task, unlike task 4.3's explicit ask.

### Filament: `MediaModerationResource` — first grid resource in the app

Named after SPEC's own module title ("Media Moderation Queue"), same
"queue-scoped `getEloquentQuery()`, empty `bulkActions()`, policy
`verify()`-style custom method" shape `VendorVerificationResource`
established. `table()->contentGrid(['md' => 2, 'xl' => 4])` — cards, not
rows, since a table of filenames would be useless for moderating images/
video. New small custom `ViewColumn` backed by a Blade partial
(`resources/views/filament/tables/columns/media-preview.blade.php`) —
`<video controls>` for `type=video`, `<img>` for `type=image` — the first
custom Filament column in the app, needed because `ImageColumn` alone
can't play video and a grid of unplayable placeholders would defeat the
point. No separate View page: the grid card already shows the full media,
so a dedicated Infolist page would be redundant (unlike
`VendorVerificationResource`'s, which needed one for KYC business-detail
text). `MediaPolicy` (new, `module()` → `'media'`, custom `moderate()`)
+ `media.viewAny`/`media.moderate` permissions, same shape as
`VendorPolicy`.

### Flutter

New `vendor_portfolio_module`, its own `GetxController`/fetch — **not**
sharing the dashboard's single fetch the way the Services tab does (task
4.4), since portfolio is genuinely independent data (own list, own quota
source), matching `salesman_home_module` → `my_vendors_module`'s split
instead. Added as the vendor dashboard's third tab
(`DefaultTabController(length: 3)`: Overview/Services/Portfolio).
`VendorPortfolioController.uploadFile()` is `@visibleForTesting` and
deliberately separate from the two picker-driven methods
(`pickAndUploadPhoto`/`pickAndUploadVideo`) so the validation/API-call
logic is directly testable without mocking `image_picker`'s platform
channel — no test in this codebase exercises the picker itself, same as
`add_vendor_controller`'s KYC upload was never tested that way either.

### Verified

- Backend: new `VendorPortfolioEndpointTest` (12 cases: upload within
  quota, remaining-vs-absolute quota rejection, rejected-frees-a-slot,
  approved-still-counts, subcategory-not-offered rejection, wrong
  declared-type-vs-mime rejection, oversized-video rejection, no-active-
  subscription rejection, role gating, index listing/scoping) + new
  `MediaModerationResourceTest` (8 cases, mirrors
  `VendorVerificationResourceTest`) + 1 new `VendorMeEndpointTest` case for
  the `photos`/`videos` quota block. Full suite: **419 passed** (up from
  399).
- Flutter: `flutter analyze` clean. New
  `vendor_portfolio_controller_test.dart` (7 cases: fetch populates
  state, failed fetch doesn't crash, missing-subcategory guard, oversized-
  video rejected locally with no network call, boundary check at exactly
  50 MB, correct request shape, server-rejection handling). Full suite:
  **76 passed** (up from 69).
- Live: uploaded a real photo (JPEG) and a real video (minimal valid MP4,
  correctly sniffed as `video/mp4`) against a running server with a
  1-photo/1-video quota — both succeeded at `pending`; a second photo was
  rejected citing "0 remaining"; rejected the video via
  `MediaModerationService`, confirmed a re-upload then succeeded (the
  freed-slot behavior); approved the photo, confirmed
  `moderation_status`/`moderated_by`/`moderated_at` and a real audit log
  entry. All live rows, uploaded files, and tokens cleaned up afterward,
  confirmed empty by direct query.

### Still open

Added to the **Before Launch Checklist**: no real client-side video
compression exists — only the length/size-cap fallback above. Revisit
once a maintained Flutter video-transcoding option exists, or once
server-side transcoding (e.g. a queued job calling a hosted service) is
in scope.

---

## 2026-08-22 — Task 4.6: customer flavor (register/login + home screen location)

### Survey first, per instruction

Confirmed before building: `RegisterRequest` already accepted
`role=customer` (task 4.1 only added vendor-specific fields, nothing
customer-side changed) and `RegisterTest` already proved the token issues
immediately. **Gap found**: `AuthController::register()` never created a
`Customer` row for `role=customer` — only the `users` row existed, so
location (SPEC §4.2) had nowhere to persist to. Fixed as a direct
prerequisite, mirroring the existing `Vendor::create()` branch exactly.
GPS/permission handling (task 3.2, salesman Add Vendor) was confirmed
entirely inline in `add_vendor_controller.dart`, no shared helper — and no
zone point-lookup endpoint existed anywhere (`ST_Contains` proven only in
tests, never in application code; no `ZoneMatcher` class, despite
PROGRESS.md reserving that exact name for this work).

### Backend

- **Customer row on registration** — `AuthController::register()` gains
  an `elseif role === 'customer'` branch, same transaction shape as the
  vendor branch. Every field but `user_id` stays null until GPS/pincode
  populates them.
- **`ZoneMatcher`** (new, `app/Services/`) — `matchPoint()`/
  `matchPincode()`, both built on one shared `matchableZones()` query
  implementing SPEC §8 exactly: leaf-only (`whereDoesntHave('children')`)
  + effective-active (`is_active AND (parent_id IS NULL OR
  parent.is_active)`), written once so the two lookup paths can't drift
  on what counts as matchable. Reuses the exact `ST_Contains`/WKT
  lng-then-lat shape `ZonePolygonTest` already proved correct.
- **`POST /api/customers/me/location`** (new) — self-resolving
  (`$request->user()->customer`, 404s if missing, same defensive shape as
  `VendorController::me()`), accepts a GPS point and/or a pincode,
  resolves via `ZoneMatcher`, and **persists regardless of match
  outcome** — SPEC: a pincode that matches nothing is still "captured for
  expansion planning." No `GET /customers/me` built this pass (flagged,
  not forgotten) — the header always does a fresh detection on load per
  what was actually asked.
- Considered extracting the quota-style shared-validation pattern here
  too, decided against it — there was no second copy to protect against
  drifting in the first place, so `matchableZones()` is just written
  correctly once, not "extracted."

### Flutter

- **`LocationCapture`** (new, `mobile/lib/utils/`) — the salesman Add
  Vendor GPS flow (service check → permission check/request → high-
  accuracy fix) extracted verbatim out of `add_vendor_controller.dart`'s
  inline `captureLocation()`, which now just calls the shared helper.
  Reused, not duplicated, per the explicit instruction — both call sites
  share one implementation.
- New `customer_login_module`/`customer_register_module` — same shape as
  the vendor auth modules, trimmed to what `RegisterRequest` actually
  needs for `role=customer` (name/email/password only), no email-
  verification-pending detour (customers aren't gated).
- New `customer_home_module` — `onInit()` runs `LocationCapture.detect()`
  → GPS success calls the location endpoint; denial or a `zone: null`
  response (SPEC §4.2's two fallback triggers) shows an **inline**
  pincode-fallback state on the same screen (matching the vendor
  dashboard's `noActiveSubscriptionState()` precedent) rather than a
  separate screen for one text field. "Change location" re-runs GPS
  detection from the top.
- `app.dart`'s customer branch now checks `Injector.isLoggedIn` like
  vendor/salesman already do (previously unconditional). The dead
  `create_account_module` scaffolding (confirmed posting to an unrelated
  demo-app endpoint, never wired to `registerAPI`) is deleted along with
  the now-unused `DataSource.userNameAPI()` it was the only caller of —
  same "replace, don't keep alongside" precedent as `vendor_home_module`
  in task 4.2.

### Verified

- Backend: 2 new `RegisterTest` cases (customer registration creates a
  matching `Customer` row; vendor registration creates no `Customer`
  row), new `ZoneMatcherTest` (9 cases — leaf-vs-parent priority proven
  against a real geometric overlap, not just asserted; effective-active
  in both directions; pincode mirrors the same rules), new
  `CustomerLocationEndpointTest` (7 cases). Full suite: **437 passed**
  (up from 419).
- Flutter: `flutter analyze` clean. New `location_capture_test.dart` (6
  cases, faking `GeolocatorPlatform.instance` the same way
  `add_vendor_controller_test.dart` already does), `customer_auth_test.dart`
  (mirrors `vendor_auth_test.dart`), `customer_home_controller_test.dart`
  (7 cases covering both SPEC §4.2 fallback triggers plus "change
  location"). Confirmed the `LocationCapture` extraction didn't break
  `add_vendor_controller_test.dart`. Full suite: **97 passed** (up from
  76).
- Live: registered a customer via `curl`, confirmed a `Customer` row now
  exists. Seeded a parent zone with a leaf child nested inside it, hit
  the location endpoint with a point inside the child — matched
  correctly; a point inside only the parent (outside any child) — no
  match, confirming the parent itself is never matched. Pincode match
  and an unmatched pincode (persisted with `zone: null`) both confirmed.
  One live-check point coincidentally also fell inside a pre-existing
  seeded zone ("Naranpura") from real dev data occupying the same
  Ahmedabad-area coordinates — confirmed via a direct `ST_Contains` query
  that this is a genuine geometric overlap between two unrelated leaf
  zones (not a bug; nothing in the schema prevents non-sibling zones from
  overlapping), not something `ZoneMatcherTest`'s isolated
  `RefreshDatabase` cases would ever hit. All live rows (customer, user,
  token, ad-hoc zones) cleaned up afterward, confirmed empty.

## 2026-08-22 — Task 5.1: customer home screen — category browse grid

### Survey

- `GET /api/categories` (task 1.2) already served everything this task
  needed — active-filtered on both categories and subcategories,
  eager-loaded, unpaginated per CLAUDE.md's bounded-master-data
  exception, icon URLs resolved on both resources. **No backend changes
  in this task.**
- One grid precedent existed in the whole app —
  `vendor_portfolio_view.dart`'s `GridView.builder` +
  `SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 2, ...,
  childAspectRatio: 1)` — reused verbatim rather than inventing a second
  visual convention.
- No category→subcategory tap-to-navigate precedent existed; the only
  other category consumers (vendor/salesman services selection) are
  multi-select toggle screens for the subscribe flow, useful only for
  how the models are shaped.

### Backend

None — confirmed by survey that the existing endpoint already serves
this exactly as built.

### Flutter

- **`customer_home_controller.dart`** (extended) — `onInit()` now fires
  `fetchCategoriesAPI()` alongside the existing `detectLocation()`,
  concurrently; genuinely independent data, neither blocks the other.
  New `categories`/`isLoadingCategories` fields.
- **`customer_home_view.dart`** (extended) — body changed from a bare
  `Padding` around the location header into a scrollable `Column`
  (location header unchanged, up top) with a new category grid section
  below it (loading spinner / empty-with-retry / grid, matching the
  portfolio grid's exact delegate config).
- **`customer_subcategories_module`** (new) — two files, matching the
  standard module shape. `CustomerSubcategoriesController` takes the
  tapped `CategoryModel` + the customer's resolved `zoneId` via
  constructor and does **no fetch of its own** — the category tree
  (subcategories included) already arrived fully loaded on the home
  screen. `selectSubcategory()` is a documented stub — same tone as
  `PushNotificationService` — naming exactly what task 5.3's real
  implementation will do (navigate to vendor search, matching
  `subcategory.id` + `zoneId` against SPEC §4 item 4's query) and
  confirming the call site won't need to change, only the body will.
  `zoneId` is threaded through specifically so task 5.3 doesn't have to
  re-derive the customer's already-resolved zone.
- New `StringRes`/`en.json` entries: `browseCategoriesTitle`,
  `noCategoriesYet`, `noSubcategoriesYet` (kept distinct from
  `noCategoriesYet` — corrected mid-implementation since reusing the
  categories-empty copy on a subcategories-empty screen read as
  confusing), `vendorSearchComingSoon`.

### Verified

- Flutter: `flutter analyze` clean. Extended
  `customer_home_controller_test.dart` with 2 cases
  (`fetchCategoriesAPI()` populates `categories` from a faked response;
  a failed fetch leaves `categories` empty without crashing). New
  `customer_subcategories_controller_test.dart` (2 cases): subcategories
  come from the constructor-supplied category against a `DataSource`
  fake whose `categoriesAPI()` override calls `fail()` if ever invoked,
  proving no network call happens on that screen; `selectSubcategory()`
  doesn't throw. Full suite: **100 passed** (up from 97).
- No backend changes, so no backend test run — confirmed by the survey
  that the existing `GET /api/categories` endpoint already serves this
  task exactly as built.
- No live check for this task — pure Flutter UI/navigation over an
  already-verified backend endpoint, per the plan's own Verification
  section.

## 2026-08-22 — Task 5.3: GET /api/vendors/search (core customer vendor matching)

### Survey

- `ZoneMatcher::matchPoint()`/`matchPincode()` (task 4.6) already
  implement SPEC §8's leaf-only + effective-active rules and return
  `?Zone` — reused as-is, no changes to that class.
- `subscription_items` is a flat `item_type`/`item_id` junction, not a
  pivot — coverage is two `whereExists` checks (one `item_type =
  'subcategory'`, one `item_type = 'zone'`) against the same
  subscription row.
- `Vendor::scopeActive()` is `status = active AND is_suspended = false`
  only, by design — its own docblock says the unexpired-subscription
  check is layered on by the caller, which is exactly what this query
  does via the `subscriptions` join.
- `PlanQuota.priority_rank` (not on `Plan` itself) is the sort's first
  key. `vendors.rating_avg`/`rating_count` already exist on the schema
  (decimal 3,2 / unsigned int, both default 0, already indexed) — added
  ahead of the reviews feature specifically for this sort. No `Review`
  model/controller/routes exist yet — task 5.5 is genuinely unbuilt.
- `ApiResponse::paginated()` existed but had never been called anywhere
  in the codebase — this endpoint is its first real caller, per
  CLAUDE.md's pagination rule for vendors as an unbounded collection.

### Design decisions

- **Location is always an explicit query param** (`latitude`+`longitude`
  or `pincode`, point wins if both given — same either/or shape
  `UpdateCustomerLocationRequest` already validates), never resolved
  from the customer's stored profile. This isn't a "my own record"
  endpoint — it returns other vendors' data, not the caller's — and
  explicit params also allow searching a location other than the
  customer's saved one, or searching before any location is stored.
  Consequently the endpoint is **public** (`throttle:public-read`, no
  auth), registered before the `/vendors/{vendor}` wildcard route for
  the same shadowing reason `/vendors/me` already documents.
- **Rating sort is a real query against real (currently empty)
  columns, not a stub.** Order: `plan_quotas.priority_rank ASC`, then
  `CASE WHEN rating_count >= 5 THEN rating_avg ELSE 0 END DESC`
  (`VendorSearchService::MIN_REVIEWS_FOR_RATING_SORT`, a named constant
  since SPEC never specifies the exact threshold number), then
  `vendors.created_at DESC` (recency). Every vendor has `rating_count =
  0` today, so the middle tier is currently a no-op for everyone and
  the sort falls straight through to recency — achieved by writing the
  correct SQL once rather than branching on whether review data exists.
- The envelope nests `{ zone, vendors }` under `data` in both the match
  and no-match cases (built manually rather than through
  `ApiResponse::paginated()`, which only has room for a flat item list)
  — a no-match is `200` with `vendors: []`, not an error, mirroring
  `CustomerController::updateLocation()`'s exact same outcome.

### Backend

- **`VendorSearchService`** (new, `app/Services/`) — resolves the zone
  via `ZoneMatcher`, then a single query joining `vendors` →
  `subscriptions` → `plan_quotas`, gated by two `whereExists` subqueries
  against `subscription_items`. Relies on the standing invariant that a
  vendor has at most one active+unexpired subscription at a time (no
  `distinct()`/`groupBy()` needed) — called out explicitly in a comment
  since it's a silent assumption the join depends on.
- **`VendorSearchRequest`** (new,
  `app/Http/Requests/Vendor/`) — `subcategory_id` required, `latitude`/
  `longitude`/`pincode` validated the same either/or way as
  `UpdateCustomerLocationRequest`, `per_page` capped at 50.
- **`VendorSearchResource`** (new) — deliberately narrower than the
  existing `VendorResource` (shaped for the salesman/self "my draft"
  case and leaks KYC/id-proof fields): only `id, business_name,
  address, latitude, longitude, shop_photo_url, rating_avg,
  rating_count`.
- **`VendorSearchController`** (new) — the entry point
  `CustomerSubcategoriesController.selectSubcategory()`'s task 5.1 stub
  documents and is waiting on.
- **`GET /api/vendors/search`** (new route) — public, `throttle:public-
  read`, registered in the public master-data block ahead of the
  `/vendors/{vendor}` wildcard.

### Verified

- Backend: new `VendorSearchEndpointTest` (13 cases) — coverage
  matching (subcategory+zone both required, either alone excludes),
  expired-subscription exclusion, suspended-vendor exclusion, pincode
  parity with point search, no-match returns `200`/`vendors: []` not an
  error, validation (missing `subcategory_id`, neither point nor
  pincode), plan-tier sort ordering ahead of recency, same-tier sort
  falling through to recency while `rating_count = 0` for both
  (proving the rating tier is genuinely inert), a rated vendor
  (`rating_count >= 5`) outranking an unrated one on the same tier
  (proving the seam activates correctly once data exists), and
  pagination (`meta.total`/`last_page`/`per_page`). Full suite: **450
  passed** (up from 437).
- Live: caught a real instance of the same real-vs-ad-hoc zone overlap
  first seen in task 4.6 — an ad-hoc zone built around the seeded
  Ahmedabad dev data matched a pre-existing zone instead of the new
  one, so the search returned the wrong (empty) result on the first
  try. Relocated the live-check zone to an unused coordinate area,
  confirmed a clean match with the seeded vendor returned in the right
  shape, confirmed a non-matching point returns `vendors: []`,
  confirmed the two validation error cases return the right HTTP
  status/error shape. All seeded rows (user, vendor, subscription,
  subscription_items, plan, plan_quota, zone, subcategory, category)
  force-deleted afterward, confirmed zero remaining.
- No Flutter changes in this task — task 5.3 was backend-only per the
  request. The Flutter call site
  (`CustomerSubcategoriesController.selectSubcategory()`) stays a
  documented stub; wiring it to this endpoint is deferred to task 5.4,
  which folds the results list in alongside the vendor detail page
  since they belong in the same screen design.

## 2026-08-23 — Task 5.4: vendor search results, vendor detail, POST /api/leads

### Survey

- The `leads` migration (Phase 1) already had every column SPEC needs
  (`customer_id, vendor_id, subcategory_id, zone_id nullable, channel
  enum(call,whatsapp)`) — genuinely greenfield above the schema; no
  `Lead` model/controller/resource existed.
- No Haversine/distance helper existed anywhere (backend or Flutter).
  `Media::scopePending()` existed but no `scopeApproved()` —
  `VendorPortfolioController::mediaResource()` was the shape precedent,
  trimmed for the public response (no moderation/rejection fields, since
  only approved rows are ever returned).
- `CustomerHomeController` only kept the matched `zone` (id/name), not
  the lat/lng/pincode that produced it — needed since `GET
  /vendors/search` and the new detail endpoint both take location as an
  explicit param (task 5.3's decision), not something re-resolved
  server-side.
- No `url_launcher` dependency, no paginated-list-consuming screen, and
  no distance-display precedent existed anywhere in the Flutter app —
  all three introduced for the first time this task.

### Design decisions

- **The customer's resolved lat/lng/pincode is threaded through the
  whole navigation chain**, not just the zone:
  `CustomerHomeController` → `CustomerSubcategoriesController` →
  `VendorSearchController` → `VendorDetailController`, so both the
  search and detail endpoints' explicit-location requirement is met
  without re-deriving anything.
- **The lead's `zone_id` comes from the search response's resolved
  zone**, not the `zoneId` originally passed in from the home screen —
  the home screen's zone can be stale; search re-resolves the
  authoritative one server-side and that's what's threaded into detail.
- **Lead creation validates FK existence only, not vendor-covers-
  subcategory-in-zone.** `CreateLeadRequest` checks the ids are real
  rows but does not re-verify the vendor's active subscription actually
  covers that subcategory+zone at write time — a lead is an
  append-only evidence-of-contact-intent record, not a capacity- or
  money-bounded write like a subscription. **Recorded permanently in
  SPEC section 9** (not just here) per explicit instruction, since
  Phase 6's leads analytics (task 6.2) needs to know this data isn't
  guaranteed internally consistent.
- Detail is public/unauthenticated, mirroring task 5.3's reasoning
  exactly (`latitude`/`longitude` optional — distance is simply omitted
  without them, not a validation error). `POST /leads` is the one write
  here and the one place `customer_id` matters, so it's the only
  endpoint gated to `auth:sanctum,role:customer` — no new login gate on
  the funnel, since the customer app already requires login before the
  home screen (task 4.6).
- No reviews section anywhere in the response or UI — not even a
  placeholder — same treatment task 5.3 gave rating's dormant sort tier,
  applied to a whole section this time since task 5.5 isn't built.
- Pagination UI is a "Load more" button, not scroll-triggered infinite
  scroll — first paginated list in the app, and a button is trivially
  testable versus a first-of-its-kind `ScrollController` pattern.

### Backend

- **`Lead`** (new model) — append-only: no `SoftDeletes` (not in the
  migration), no `RecordsAuditLog` (a customer-generated event log, not
  admin-edited master data).
- **`CreateLeadRequest`/`LeadController`** (new) — `customer_id` is
  never accepted from the client, always `$request->user()->customer`,
  same defensive shape as `CustomerController::updateLocation()`.
- **`VendorDetailController`/`VendorDetailResource`/
  `VendorDetailRequest`** (new) — same active-vendor +
  active-unexpired-subscription visibility floor as vendor search;
  services come from the active subscription's `subscription_items`
  joined to `Subcategory`/`Category`; media is
  `where('moderation_status','approved')`; distance is a private
  Haversine helper on the controller (one caller, not extracted per "no
  premature abstraction" — flagged for the day a second caller needs
  it).
- **Routes**: `GET /vendors/{vendor}/detail` (public,
  `throttle:public-read`) and `POST /leads` (new `throttle:leads`
  limiter, keyed on the authenticated customer like `subscribe` is —
  blunts a customer flooding the table by mashing Call/WhatsApp, not
  meant to ration normal use).

### Flutter

- **`CommonResponse`** gains `meta` (task 5.3's pagination block was a
  sibling of `data` that nothing parsed yet).
- **`DataSource`**: `_get` gains `queryParameters`;
  `vendorSearchAPI()`/`vendorDetailAPI()`/`createLeadAPI()` added.
- **New models**: `vendor_search_model.dart`, `vendor_detail_model.dart`
  — `distanceKm` parses via `num?.toDouble()` since a whole-number
  Haversine result (e.g. `1.0`) serializes as a bare JSON integer, not
  a float.
- **`customer_home_controller.dart`**: gains `latitude`/`longitude`/
  `pincode`, set in `_reportLocation()` alongside the existing `zone`.
- **`customer_subcategories_controller.dart`**: constructor gains the
  same three fields; `selectSubcategory()`'s stub body is replaced with
  real navigation to `VendorSearchView`.
- **`vendor_search_module`** (new) — "Load more" pagination, vendor
  cards (rating or a "New" label when `rating_count` is 0, matching the
  dormant-tier treatment), tap navigates to detail carrying the
  search-resolved zone.
- **`vendor_detail_module`** (new) — shop photo, rating/"New", distance
  (hidden when null), services chips, photos/videos grid (third use of
  the grid precedent), Call/WhatsApp buttons. `launchUrlFn` is a
  `@visibleForTesting` seam (same extraction pattern as
  `VendorPortfolioController.uploadFile()` for `image_picker`) so the
  must-succeed-before-launch ordering is actually testable.
  `_recordLead()` is the one chokepoint both buttons share.
- `pubspec.yaml` gains `url_launcher: ^6.3.0`.

### Verified

- Backend: new `LeadEndpointTest` (8 cases — customer_id always from
  the token never the client, FK validation, role gating, unauth
  rejection) and `VendorDetailEndpointTest` (7 cases — services scoped
  to the active subscription, 404 with no active subscription, 404
  suspended, approved-only media, distance present/absent). Full suite:
  **465 passed** (up from 450).
- Flutter: `flutter analyze` clean. New
  `vendor_search_controller_test.dart` (3 cases: populates
  vendors/zone, load-more appends and requests the next page, a
  no-match zone leaves vendors empty without crashing). New
  `vendor_detail_controller_test.dart` (6 cases) — **the load-bearing
  one**: `callAPI()`/`whatsappAPI()` call the `launchUrlFn` seam only
  when the fake lead-create call succeeds, and provably do NOT call it
  on failure or on a thrown exception, proving SPEC section 4 item 7's
  ordering requirement directly rather than just that the buttons don't
  crash. Extended `customer_subcategories_controller_test.dart` to
  assert `selectSubcategory()` navigates to `VendorSearchView` carrying
  the threaded lat/lng/pincode (point and pincode-only cases both
  covered), replacing the old "shows a toast" stub assertion from task
  5.1. Full suite: **110 passed** (up from 100).
- Live: seeded a vendor with an active subscription, one approved and
  one pending portfolio photo, and a customer token, at coordinates
  outside the seeded Ahmedabad range per the CLAUDE.md hygiene note.
  Confirmed the detail endpoint returns distance when a point is given
  and omits it when not, returns only the approved photo, and 404s for
  an unknown vendor. Confirmed `POST /leads` creates exactly one row
  with `customer_id` resolved from the token (not client-supplied), and
  that an invalid `vendor_id` gets a `422` and writes nothing.
  **Cleanup note**: the first cleanup attempt hit a duplicate-email
  error from a leftover row task 5.3's live-check left behind —
  `User::where(...)->delete()` soft-deletes (the model uses
  `SoftDeletes`), which still occupies the unique email index; the
  correct cleanup is `forceDelete()`, same as every other model in
  these scripts. Re-verified zero rows remaining, including trashed,
  after switching this session's cleanup to `withTrashed()->forceDelete()`
  throughout.

## 2026-08-23 — Task 5.5: reviews (write, 24h edit, vendor reply, admin hide, rating aggregate)

### Survey

- `reviews` (Phase 1 migration) already had every column this task
  needed: `lead_id` as a `foreignId->unique()` — "one review per lead"
  was already a DB constraint, not something to add. No 24h-edit
  column — deliberate, per its own docblock, computed from `created_at`
  at check time so it can't drift from itself.
- Nothing existed above the migration — genuinely greenfield.
- `vendors.rating_avg`/`rating_count` were confirmed not in
  `$fillable`, already read by three sites (`VendorSearchResource`,
  `VendorDetailResource`, `VendorSearchService`'s sort tier) that all
  needed to keep working unchanged.
- `RecordsAuditLog`/`TracksFileDisk` were the exact
  `boot{TraitName}()` model-lifecycle-hook precedent to mirror.
  `MediaModerationResource` was the exact "admin moderates
  customer-generated content without hard-delete" precedent, adapted
  from a content grid to a text table.

### Design decisions

- **`POST /api/reviews` takes `vendor_id`, not `lead_id`** — the server
  resolves the customer's most recent lead with that vendor, within 30
  days, not yet reviewed (`Lead::whereDoesntHave('review')`, new
  `Lead::review(): HasOne`). Still satisfies "one review per lead"
  literally: a customer with two valid unreviewed leads for the same
  vendor can leave two reviews, one per lead, proven by a dedicated
  test.
- **Rating aggregate: a real trait, not a stub.** New
  `RecalculatesVendorRating` (`app/Models/Concerns/`), applied to
  `Review`, hooks `saved`/`deleted` and recomputes `AVG(rating)`/
  `COUNT(*)` over non-hidden reviews, written via a query-builder
  `update()` (bypassing mass assignment, matching the columns'
  deliberate absence from `$fillable`). Live-verified end to end:
  `rating_avg`/`rating_count` went from `0.00`/`0` to `5.00`/`1` on
  review creation, and back to `0.00`/`0` on admin hide — task 5.3's
  sort tier is no longer silently inert.
- **Known gap, flagged for task 5.6**: a `Lead`'s `cascadeOnDelete()`
  removes its `Review` at the DB layer without firing Eloquent events,
  so a hard-deleted lead would leave a vendor's rating aggregate stale
  until the next review write. No lead-delete feature exists today, so
  this is currently inert — but **task 5.6 (customer account deletion)
  could plausibly cascade through leads/reviews depending on how it's
  implemented, and should explicitly check whether it triggers this
  gap** rather than discovering it live. Documented on
  `RecalculatesVendorRating`'s own docblock too, not just here.
- **Admin hide is a flag flip** (`is_hidden`/`hidden_by`/
  `hidden_reason`, already on the schema), filtered out of both the
  public vendor-detail response and the rating aggregate, same "no hard
  delete on customer content" stance as Media.
- **Vendor reply is backend-only this pass.** The endpoint
  (`POST /vendors/me/reviews/{review}/reply`) is built and tested, and
  customers see any existing `vendor_reply` inline — but there's no
  Flutter screen for a vendor to submit one from. That's **task 4.8
  (Leads + Reviews tabs)**, not built yet — corrected mid-task from an
  initial mislabeling as "task 5.6," which is actually the unrelated
  customer-account-deletion task.
- **Flutter builds display + write, not edit.** The 24-hour edit
  window is a real, tested backend capability
  (`PATCH /api/reviews/{review}`), but no Flutter edit UI this pass —
  the vendor-detail response is intentionally public/unauthenticated
  (task 5.4's decision), so there's no clean way for the app to know
  "which of these reviews is mine" without adding auth to that
  endpoint or a second lookup call.
- **The admin fraud filter (SPEC §5 item 6) is real, not decorative.**
  Since `lead_id` is `NOT NULL`+FK, "no matching lead" can't physically
  occur — the filter instead surfaces a review whose `created_at` is
  more than 30 days after its own lead's, which the normal write path
  should never produce. Built as a genuine `whereHas` filter and
  covered by a test that seeds exactly that drift.

### Backend

- **`Review`** (new model, `use RecalculatesVendorRating`), **`Lead`**
  gains `review(): HasOne`.
- **`StoreReviewRequest`** resolves and stashes the eligible lead in
  `withValidator()` so the controller doesn't re-query it.
  **`UpdateReviewRequest`** — ownership/24h checks live in the
  controller (not expressible as field rules), same style
  `AddSubscriptionItemsRequest` already uses. **`ReplyToReviewRequest`**.
- **`ReviewController`** (`store`/`update`), **`VendorReviewController`**
  (`reply`) — both the "resolve from token, 404 if null, then check
  ownership" shape `LeadController`/`VendorPortfolioController` already
  use.
- **`VendorDetailController`/`VendorDetailResource`** extended with a
  5th constructor arg, `reviews` — visible-only, `customer.user` eager
  loaded so `ReviewResource`'s `customer_name` is never a query per row.
- **Routes**: `POST /reviews`, `PATCH /reviews/{review}` (role:customer,
  alongside `/leads`), `POST /vendors/me/reviews/{review}/reply`
  (role:vendor, alongside `/vendors/me/portfolio`). New `reviews`
  throttle limiter, same shape as `leads`.
- **Filament `ReviewResource`** (new) — a table (not a grid, unlike
  Media — reviews are text), shows both visible and hidden reviews (a
  management list, not a triage queue), `hide`/`unhide` actions
  delegated to new `ReviewModerationService`, the fraud-signal filter.
  New `ReviewPolicy` (`hide()` ability) and `Permission::ReviewsViewAny`/
  `ReviewsHide`. Removed `PolicyCoverageTest`'s stale `reviews.*`
  carve-out — it existed only because this resource didn't exist yet;
  leaving it in would have silently masked a real future policy bug.

### Verified

- Backend: new `ReviewEndpointTest` (15 cases), `VendorReviewReplyEndpointTest`
  (5 cases), Filament `ReviewResourceTest` (10 cases — including the
  fraud filter), `VendorDetailEndpointTest` extended (2 cases — visible
  review + reply shown, hidden review never appears). Full suite:
  **497 passed** (up from 465).
- Flutter: `flutter analyze` clean. New `review_model.dart`,
  `VendorDetailModel` gains `reviews`. `vendor_detail_controller_test.dart`
  extended (4 cases): reviews parse correctly; `submitReviewAPI()`
  re-fetches on success (proving the new review and recalculated
  rating come from the server, not a locally-inserted stand-in); the
  "no eligible lead" 422 surfaces its specific field message, not the
  generic one; a generic failure doesn't re-fetch. Full suite: **114
  passed** (up from 110).
- Live: seeded a vendor + customer + lead, created a review via curl
  and confirmed `rating_avg`/`rating_count` moved from `0.00`/`0` to
  `5.00`/`1` on the vendor detail response — task 5.3's dormant sort
  tier is now genuinely live. Vendor replied via curl and confirmed the
  reply appeared in the same response. Hid the review via
  `ReviewModerationService` (the Filament action's own code path) and
  confirmed it disappeared from the response and the aggregate dropped
  back to `0.00`/`0`. Confirmed a duplicate review attempt on the same
  (now-used) lead returns `422` and writes nothing. All seeded rows
  force-deleted afterward (including `withTrashed()`, per the task
  5.4 cleanup note), confirmed zero remaining.

## 2026-08-24 — Task 4.7: subscription upgrade, downgrade, add-on

### Survey

- `previous_subscription_id` had sat on `subscriptions` since Phase 1,
  schema-ready and completely unused — its own migration comment
  already anticipated this task. No proration/upgrade/downgrade method
  existed anywhere in `SubscriptionService`.
- `StoreSubscriptionRequest` can only ever create a vendor's *first*
  subscription (`vendor.status === 'draft'` gate) — an already-active
  vendor genuinely cannot hit `POST /subscriptions` today.
- No add-on/top-up concept existed anywhere in the schema (confirmed by
  a full migrations grep).
- `PaymentService`'s three methods read `amount_paise` from
  `$subscription->price_paise` implicitly — this assumption breaks the
  moment a payment needs to charge something OTHER than the
  subscription's own price (an add-on, priced independently). Touched
  as a necessary consequence, not scope creep.
- `commissions.subscription_id` is **unique** — discovered mid-build,
  not in the original survey, and it matters: a subscription that
  already earned a commission on its original sale cannot receive a
  second `Commission` row from a later add-on purchase without hitting
  that constraint.

### Design decisions

- **Proration**: `remaining_value = old.price_paise * unused_days /
  old.duration_days`; `amount_due = max(0, new_plan.price_paise -
  remaining_value)`. A credit reduces price, never extends the new
  term — the new subscription always runs the new plan's own
  `duration_days`. One formula, both directions: a downgrade's credit
  can exceed the new plan's price, in which case the amount charged
  floors at 0 and **the excess is never refunded** (no refund
  mechanism without a real payment gateway). Recorded permanently in
  **SPEC §13**, flagged for revisit at Phase 9. `subscriptions.price_paise`
  on the new row is the amount actually charged (not the plan's list
  price) — commission is computed off this same discounted amount, so
  a salesman never earns commission twice on money already collected.
- **Add-on quota**: new `subscription_addons` table (one row per
  purchase, matching the existing pattern of a dedicated row per
  priced business event) plus five new `addon_price_per_*_paise`
  columns on `plan_quotas`. New `Subscription::effectiveQuota()` = bare
  plan max + purchased add-on quantity, applied consistently across
  **four** call sites, not the three originally scoped —
  `AddSubscriptionItemsRequest`, `VendorPortfolioController`/
  `StorePortfolioMediaRequest`, `ChangeSubscriptionPlanRequest`'s
  downgrade-blocking check, and a fourth found mid-build:
  `VendorController::activeSubscriptionSummary()` (the vendor
  dashboard's own quota display), which would otherwise have shown a
  "used" count exceeding its own displayed "max" the moment an add-on
  let a vendor go past the bare plan limit. Add-ons are scoped to
  `subscription_id` and do **not** carry forward across a plan change —
  confirmed with a test that a downgrade-blocking check on the OLD
  subscription's add-on-expanded quota still gets blocked by the NEW
  plan's bare limit, proving the non-carry-forward rule is actually
  enforced, not just stated.
- **Who can call it**: `change-plan` and `add-ons` both use the same
  `role:salesman,admin,vendor` dual-path gate `POST /subscriptions`
  already has (ownership/payment-mode rules copied from
  `StoreSubscriptionRequest`) — the dividing line the existing code
  actually draws is *whether money moves*, not onboarding-vs-ongoing;
  task 4.4's add-services stayed vendor-only specifically because it's
  commission- and payment-free, and both new endpoints are neither.
  `payment_mode=free` is not offered for add-ons — a free trial is a
  full-subscription concept, nothing in SPEC describes a free add-on.
- **No commission on add-on purchases** — not the original design,
  discovered necessary once the `commissions.subscription_id` unique
  constraint was hit mid-build. A second `Commission::create()` against
  a subscription that already has one throws. Extending commissions to
  credit ongoing add-on purchases would need a schema change (drop the
  uniqueness, add add-on linkage) that's out of scope here — flagged
  explicitly, not silently dropped, and add-on purchases still require
  the same role/ownership gate as change-plan for consistency even
  though no commission is currently earned on the sale.
- **Two new endpoints, not one overloaded `POST /subscriptions`** —
  SPEC's "all routed through the same subscription endpoint / service
  method" is read as one cohesive `SubscriptionService`, mirroring the
  exact relationship task 4.4's add-services already has with
  `POST /subscriptions` (a distinct route sharing internals, not a mode
  flag on one giant request class). Stated explicitly as a real
  divergence from SPEC's literal wording.
- **Add-on idempotency needed its own middleware**, discovered
  mid-build — `HandleIdempotentSubscription` only looks up
  `Subscription` rows by key; an add-on purchase never creates one, so
  reusing it unchanged would have let a replayed add-on request charge
  twice, every time. New `HandleIdempotentAddonPurchase` mirrors the
  same shape against a new `subscription_addons.idempotency_key`
  column instead.
- **Old subscription's fate**: new `superseded` enum value on
  `subscriptions.status` (the standing raw-`ALTER TABLE` pattern for
  widening a lifecycle enum, same shape as `vendors.status` gaining
  `rejected` in task 4.3) — kept distinct from `cancelled` so admin
  reporting can tell an upsell apart from actual churn. `end_date`
  moved to today on supersession.
- **Filament add-on pricing fields entered directly in paise**, not
  rupees-converted like the plan's own price field — a deliberate
  simplification to avoid introducing new nested-Fieldset
  rupees-conversion hook plumbing for five fields; flagged as a
  possible future polish item, not silently done. Both this Fieldset
  and the existing quota-limits one map to the same `quota`
  relationship — verified (not assumed) this doesn't cause either to
  clobber the other's save.

### Backend

- **Migrations**: `plan_quotas` gains five `addon_price_per_*_paise`
  columns; new `subscription_addons` table (with its own
  `idempotency_key`); `subscriptions.status` widened to add
  `superseded`.
- **`SubscriptionService`**: new `changePlan()` (one transaction:
  proration, new subscription + items, payment, commission, old
  subscription superseded) and `purchaseAddOn()` (attaches to the
  current subscription, no new row, no commission). Commission-writing
  extracted into a shared `recordCommission()` private method used by
  `subscribe()` and `changePlan()`.
- **`PaymentService`**: `payViaCash()`/`payOnline()` now take an
  explicit `$amountPaise` rather than implicitly reading
  `$subscription->price_paise` — the one existing caller
  (`SubscriptionService::subscribe()`) updated to pass it explicitly.
- **New `FreeTrialValidator`** (`app/Support/`), extracted from
  `StoreSubscriptionRequest` the same way `ServiceSelectionValidator`
  already was — shared by `StoreSubscriptionRequest` and
  `ChangeSubscriptionPlanRequest` so the two free-trial-cap checks
  can't drift apart.
- **New `ChangeSubscriptionPlanRequest`/`PurchaseAddOnRequest`**,
  **`SubscriptionController::changePlan()`/`addOns()`**, new
  **`SubscriptionAddon`/`SubscriptionAddonResource`** models/resource.
- **Routes**: `POST /subscriptions/{subscription}/change-plan` and
  `/add-ons`, inside the existing `role:salesman,admin,vendor` group.
- **`app/Filament/Resources/PlanResource.php`**: second `Fieldset`
  (add-on unit pricing), same `->relationship('quota')` as the
  existing quota-limits fieldset.

### Verified

- Backend: new `SubscriptionChangePlanEndpointTest` (13 cases —
  including the exact hand-computed proration amount, the
  add-on-doesn't-carry-forward proof, and idempotency replay),
  `SubscriptionAddOnEndpointTest` (10 cases — including the
  commission-collision proof), extended `AddSubscriptionItemsEndpointTest`,
  `VendorPortfolioEndpointTest`, and `PlanResourceTest` (one case each,
  for the fourth-call-site fix and the two-Fieldset save). Full suite:
  **523 passed** (up from 497).
- **Testing-database schema drift, unrelated to this task's code**:
  the first full-suite run after this task's migrations showed 53
  unrelated failures (`categories.disk` column missing) — the
  `marketplace_testing` database's schema had drifted out of sync with
  the migrations table somehow (recorded as "ran" but not actually
  present). Not caused by anything in this task; fixed with
  `DB_DATABASE=marketplace_testing php artisan migrate:fresh --force`
  (the disposable test database only — no dev or production data
  touched), after which the full suite ran clean.
- Live: seeded a vendor with a known 100-day, ₹100-priced subscription
  50 days in; curled an upgrade to a ₹200 plan and confirmed the
  charged amount was exactly ₹150 (the hand-computed credit), the old
  subscription came back `superseded`, and `previous_subscription_id`
  was set on the new row. Purchased a "+2 categories" add-on and
  confirmed the charged amount matched `plan_quotas`' unit price ×
  quantity. Confirmed the vendor dashboard's own quota display showed
  the addon-expanded max (7, not the bare plan's 5), and that
  `POST /vendors/me/services` actually accepted a selection that used
  the expanded room. All seeded rows force-deleted afterward, confirmed
  zero remaining.

## 2026-08-24 — Task 4.8: vendor Leads + Reviews tabs (closes Phase 4)

### Survey

- `Lead` (task 5.4/5.5) already had `review(): HasOne` and a
  `['vendor_id','created_at']` index whose own comment names "Vendor
  Leads tab" — built ahead of this task. No `review_requested_at`
  column existed — genuinely new.
- `VendorReviewController::reply()` (task 5.5) was already complete
  and tested — its own docblock named this task as the thing that
  would finally give it a Flutter caller. No backend change to reply
  itself was needed.
- `ApiResponse::paginated()` existed but had never actually been
  called — `VendorSearchController` (task 5.3) built its envelope by
  hand in the identical shape instead. This task is its first real
  caller, closing that gap too.
- `PushNotificationService` (task 4.3) was the exact "documented no-op
  stub until Phase 7 FCM" pattern to mirror for the review-request
  notification.

### Design decisions

- **`review_requested_at` timestamp, once per lead, ever** — no
  history table, no cooldown-timer config. A second request on the
  same lead is rejected outright (`ALREADY_REQUESTED`), so there's
  nothing a history table would add. Asking again requires a
  genuinely new lead (real re-engagement), not a timer.
- **Added mid-task, not in the original plan**: `requestReview()` also
  rejects a lead older than 30 days (`REVIEW_WINDOW_EXPIRED`),
  mirroring `StoreReviewRequest`'s own eligibility window — asking for
  a review the customer can no longer actually leave is a dead end
  worth catching at request time, not discovered later when the
  customer hits the same 422 themselves.
- **Leads tab shows customer name only, not phone** — SPEC's field
  list ("every customer who tapped Call, with date and service
  requested") doesn't name phone, and it isn't exposed to a vendor
  anywhere else in this app. A conservative call, stated explicitly.
- **New `VendorReviewResource`, not the existing customer-facing
  `ReviewResource`** — the customer-facing one deliberately excludes
  `is_hidden`; a vendor's own management view should show every
  review, hidden or not, with an `is_hidden` flag, so nothing silently
  disappears from their own list with no explanation.
- **`notifyReviewRequested(Vendor, Lead)`** added to
  `PushNotificationService`, same one-line no-op tone as the two
  existing methods — sent to the customer on the lead, not the
  vendor, since the customer is who'd act on it.

### Backend

- **Migration**: `leads` gains `review_requested_at` (nullable
  timestamp).
- **New `VendorLeadController`**: `index()` (self-resolving, paginated,
  `ApiResponse::paginated()`'s first real caller) and
  `requestReview()` (three guard checks — window, already-reviewed,
  already-requested — each its own error code).
- **New `LeadResource`/`VendorReviewResource`**.
- **`VendorReviewController`** gains `index()` (unfiltered, paginated,
  the vendor's own Reviews tab).
- **Routes**: `GET /vendors/me/leads`, `POST
  /vendors/me/leads/{lead}/request-review`, `GET /vendors/me/reviews`
  — all inside the existing `role:vendor` group, none throttled beyond
  the standard sanctum guard (none are money-bearing, matching
  `/vendors/me/services`/`/vendors/me/portfolio`'s existing shape).

### Flutter

- **New `lead_model.dart`/`vendor_review_model.dart`**.
- **`DataSource`** gains `vendorLeadsAPI()`, `requestReviewAPI()`,
  `vendorReviewsAPI()`, `replyToReviewAPI()` — the first two paginated
  GETs consumed by a vendor-flavor screen (task 5.3's
  `vendorSearchAPI()` `meta`-reading pattern was customer-flavor).
- **New `vendor_leads_module`/`vendor_reviews_module`** — each its own
  controller/fetch, matching Portfolio's "genuinely independent data"
  precedent rather than sharing the dashboard's single
  `GET /vendors/me` fetch. Leads tab shows a per-row "Request a
  review" button with three states (request / already requested /
  already reviewed). Reviews tab's reply sheet reuses task 5.5's
  "Write a review" bottom-sheet shape, pre-filled with any existing
  reply so replying again edits it rather than starting blank.
- **`vendor_dashboard_view.dart`**: `DefaultTabController(length: 5)`
  (was 3), two new tabs, `TabBar` made scrollable since five tabs no
  longer fit comfortably unscrolled on narrower screens.

### Verified

- Backend: new `VendorLeadEndpointTest` (11 cases — including the
  30-day window addition) and `VendorReviewIndexEndpointTest` (5
  cases). One boundary test (`exactly 30 days old`) was rewritten to
  "comfortably inside the window" after flaking — the test's
  `created_at` and the controller's own `now()->subDays(30)` are
  computed a request-roundtrip apart, so an exact-instant boundary can
  land on either side of a second purely from that elapsed time, not
  from the 30-day rule being wrong. Full suite: **539 passed** (up
  from 523).
- Flutter: `flutter analyze` clean. New `vendor_leads_controller_test.dart`
  (5 cases) and `vendor_reviews_controller_test.dart` (4 cases). Full
  suite: **123 passed** (up from 114).
- Live: seeded a vendor with two leads (one reviewable, one already
  reviewed with the review hidden by admin) — confirmed the leads list
  showed `has_review` correctly for both, confirmed the reviews list
  included the hidden review with `is_hidden: true`, confirmed
  `request-review` succeeded once then correctly rejected a second
  call (`ALREADY_REQUESTED`) and a call on the already-reviewed lead
  (`ALREADY_REVIEWED`), confirmed a reply landed and showed up in the
  vendor's own list immediately after. All seeded rows force-deleted
  afterward, confirmed zero remaining.

## 2026-08-24 — Favorites, share vendor profile, report vendor (minimal), account deletion

SPEC §4 item 10's five "Extras" (search, favorites, share vendor
profile, report vendor, account deletion), scoped per-item before
building: search needed nothing new (task 5.3's subcategory+location
endpoint already covers it — confirmed with you via `AskUserQuestion`);
report vendor build minimal now, not the full Support Tickets module
(also confirmed via `AskUserQuestion`); favorites and account deletion
scoped normally; share vendor profile confirmed as text-only, since no
deep-linking package exists anywhere in the app.

### Design decisions

- **Favorites**: `favorites` table (`customer_id`, `vendor_id`, unique
  pair) — a toggle endpoint, not separate favorite/unfavorite routes,
  since the caller only ever needs "is it favorited now" either way.
  `is_favorite` surfaces on both `VendorSearchResource` and
  `VendorDetailResource`, computed from the caller when a token is
  present — including on the two **public, unauthenticated** routes
  (`GET /vendors/search`, `GET /vendors/{vendor}/detail}`), which had
  to stay public. New `GET /customers/me/favorites` list, paginated.
- **Share vendor profile**: Flutter-only, no backend change — a plain
  text summary via the existing `share_plus` dependency (task 3.6's
  `Share.share()` precedent), not a link. Documented explicitly as a
  scope limit: real deep linking (URL scheme, universal links, a web
  fallback page) is a separate, much larger undertaking.
- **Report vendor**: new `reports` table (`customer_id`, `vendor_id`,
  `reason`, unique pair — no status/lifecycle) + one write endpoint +
  a minimal read-only Filament list, so submitted reports are visible
  to someone without building any resolve/assign workflow. That's
  explicitly Phase 6's Support Tickets module (SPEC §5.15).
- **Account deletion**: `DELETE /api/user`, scoped to
  `role:vendor,salesman,customer` — **deliberately excludes admin**,
  closed by decision rather than left open by omission, even though no
  admin self-delete path exists anywhere yet. Reuses
  `User::deleteWithTombstone()` (task 2.1) completely unchanged, behind
  a password-confirmation gate mirroring `ChangePasswordController`'s
  exact shape.

### A real bug the live-check caught

`is_favorite` came back `false` for every authenticated caller at
first, even immediately after favoriting. Root cause:
`$request->setUserResolver()` was being called on the **FormRequest**,
which is a *copy* Laravel makes of the original request at resolution
time (`Request::createFrom()` snapshots whatever resolver existed
then) — not the object the `request()` helper / `JsonResource::resolve()`
actually reads, which is the container's `'request'` singleton.
Rebinding that singleton (`app()->instance('request', $request)`)
looked like the fix, but Laravel's own `AuthServiceProvider` registers
a `rebinding('request', ...)` callback that immediately **overwrites**
the resolver back to "defer to the auth guard" on every rebind — so
the override silently undid itself. Fixed by setting the user directly
on the guard instead (`Auth::guard('sanctum')->setUser($user);
Auth::shouldUse('sanctum');`), which is what `Authenticate` middleware
itself does for a normal authenticated route — `$request->user()`
already deferred there by default, so priming the guard was the actual
fix, not fighting Laravel's request-rebind handler. Documented in
`ResolvesOptionalAuthUser`'s own docblock so this doesn't get
rediscovered.

### Backend

- New tables: `favorites`, `reports` (both `customer_id`/`vendor_id`
  unique pairs). New models `Favorite`, `Report`.
  `Customer::hasFavorited(int $vendorId)` — shared by both resources
  so "is this vendor favorited" is defined once.
- New `ResolvesOptionalAuthUser` trait
  (`app/Http/Controllers/Concerns/`) — establishes an "optional auth"
  pattern that didn't exist anywhere in this codebase before, used by
  `VendorSearchController`/`VendorDetailController` only.
- New `FavoriteController` (toggle + list), `ReportController`
  (store), `DeleteAccountController` (`__invoke`, mirrors
  `ChangePasswordController`). New `ReportVendorRequest`,
  `DeleteAccountRequest`.
- New minimal read-only Filament `ReportResource` (People nav group,
  no Create/Edit/Delete pages — same "the omission is the enforcement"
  shape as `MediaModerationResource`/`ReviewResource`), new
  `ReportPolicy`, new `Permission::ReportsViewAny`.
- New rate limiters `favorites` (30/min) and `reports` (10/min),
  keyed on the authenticated actor like `leads`/`reviews`.
- PROGRESS.md's Before Launch Checklist gained a new item: the
  `reports` unique pair means "one report ever," not "one open
  report" — Phase 6 needs to revisit once resolution status exists.

### Flutter

- `VendorSearchModel`/`VendorDetailModel` gained `isFavorite` (bool,
  default false). Heart-icon toggle button on the search-results card
  and the detail screen's app bar, both optimistic with revert-on-
  failure.
- New `customer_favorites_module` (list screen, same "Load more"
  pagination shape as leads/reviews) — reachable from the customer
  home app bar. Its own toggle removes the row rather than just
  flipping the icon, since this list *is* the favorite set.
- `VendorDetailController` gained `shareProfile()` (text summary via
  a swappable `shareFn` seam, same pattern as `launchUrlFn`) and a
  share button in the app bar.
- New "Report this vendor" bottom sheet (`_ReportVendorSheet`, private
  to `vendor_detail_view.dart`), same shape as task 5.5's
  `_WriteReviewSheet` — triggered from a new overflow menu in the app
  bar.
- New `delete_account_module` (a dedicated confirmation screen, not
  just a dialog, given how destructive this is) — shared across all
  three flavors via a `loginViewBuilder` constructor param, since each
  flavor navigates to a different login screen afterward. Wired into
  each flavor's home app bar next to Sign out.
- `VendorDetailController.subcategoryId` became nullable: the
  favorites list has no subcategory context to pass, unlike the
  search-results screen. `_recordLead()` now falls back to the
  vendor's own first listed service when absent, rather than blocking
  Call/WhatsApp entirely from that entry point.
- `DataSource` gained a `_delete()` helper (none existed before) plus
  `toggleFavoriteAPI`, `favoritesAPI`, `reportVendorAPI`,
  `deleteAccountAPI`.

### Verified

- Backend: new `FavoriteEndpointTest` (12 cases), `ReportEndpointTest`
  (4 cases), `DeleteAccountTest` (7 cases), `ReportResourceTest` (5
  cases). Full suite: **565 passed** (up from 539) — one unrelated,
  pre-existing flaky test (`SubscriptionAddOnEndpointTest`, a random
  fake-phone collision) confirmed passing standalone, not caused by
  this work.
- Flutter: `flutter analyze` clean. New/extended controller tests
  across `vendor_search_controller_test.dart`,
  `vendor_detail_controller_test.dart`,
  `customer_favorites_controller_test.dart` (new),
  `delete_account_controller_test.dart` (new). Full suite:
  **137 passed** (up from 123).
- Live: temp `php artisan serve`, seeded a customer + vendor pair
  outside the seeded Ahmedabad zone range. Confirmed the toggle
  flips `is_favorite` both ways; confirmed it's reflected correctly
  on search/detail for the owning customer's token and reads `false`
  for a guest and for a different customer's token (this is what
  caught the bug above); confirmed the favorites list shows only the
  caller's own favorites; confirmed a repeated report against the same
  vendor doesn't duplicate; confirmed account deletion rejects the
  wrong password (422, row untouched) and tombstones on the right one.
  All seeded rows force-deleted afterward, confirmed zero remaining.

## 2026-08-25 — Task: admin Dashboard (SPEC §5 item 1)

Eight real counts/sums against real tables — total
vendors/salesmen/customers/services/subservices, subscriptions
expiring in the next 30 days, revenue this month, pending
verification count, leads generated this week. Nothing existed
before this: no `app/Filament/Widgets/` directory, no custom
Dashboard page — the panel was rendering Filament's stock blank
dashboard with only the `AccountWidget`.

### Survey

- No widget/dashboard precedent existed anywhere to mirror.
- **Revenue clarified**: `SUM(payments.amount_paise)` for the
  calendar month, filtered on `created_at`, is the complete, correct
  figure with no adjustment — there is no refund/void/credit-note
  mechanism anywhere in the codebase (`payments.status`'s `refunded`
  value is schema-only, never set). Task 4.7's change-plan proration
  is already baked into the amount charged at write time, including
  the legitimate `amount_paise = 0` row when a downgrade credit fully
  covers the new plan's price — a plain sum handles that correctly
  with no special-casing.
- "Services"/"subservices" confirmed to mean `Category`/`Subcategory`
  via SPEC's own cross-reference (its "services/subservices" line and
  its "categories/subcategories" line describe the same screen).
- The `[status, end_date]` index on `subscriptions` was already built
  anticipating the 30-day-expiry query — its own migration comment
  says so. Confirmed a `superseded` row (task 4.7) can never appear in
  it: `changePlan()` atomically sets both `status => 'superseded'` and
  `end_date => today` in the same update, so the `status = 'active'`
  filter alone excludes it — no extra `!= 'superseded'` clause needed.
- Two judgment calls resolved via `AskUserQuestion`: the dashboard is
  visible to every admin-role user with no new permission (nothing in
  this app currently gates a Filament *Page*, only Resources via their
  model policies, and SPEC doesn't ask for a restriction here); "this
  week" means the calendar week (`startOfWeek()`/`endOfWeek()`), for
  consistency with "this month" already being a calendar-month
  boundary.

### Backend

- New `app/Filament/Widgets/DashboardStatsOverview.php` — extends
  Filament's stock `StatsOverviewWidget`, eight `Stat::make()` cards.
  Auto-discovered by the panel's existing
  `discoverWidgets(in: app_path('Filament/Widgets'), ...)` call — no
  edit to `AdminPanelProvider.php` needed, since that discovery was
  already wired up (only the directory didn't exist yet). Reuses
  `Vendor::pendingVerification()`, the exact scope
  `VendorVerificationResource`'s own queue already uses, so the two
  numbers can never drift apart. Reuses `PlanResource.php`'s own
  `Number::currency($state / 100, 'INR')` pattern for the revenue
  card.

### Verified

- New `tests/Feature/Admin/DashboardStatsOverviewTest.php` (8 cases)
  — calls `getStats()` directly via reflection (it's `protected`)
  rather than scraping rendered HTML, asserting on `Stat::getValue()`.
  Covers: exact vendor/salesman/customer counts, a soft-deleted vendor
  correctly excluded, services/subservices mapping to
  categories/subcategories, the 30-day-expiry query excluding a
  far-future active subscription, an expired one, and a superseded one
  (all four cases in one test), the pending-verification count matching
  `Vendor::pendingVerification()->count()` directly, leads-this-week
  excluding a lead from last week, and revenue summing correctly
  across a normal payment, a legitimate `amount_paise = 0` payment,
  and a payment from last month (excluded).
- Full suite: **573 passed** (up from 565).
- Live: temp `php artisan serve`, called the widget's `getStats()`
  against the real dev database via tinker — confirmed it matched
  direct queries exactly (12 services / 42 subservices from the seeded
  catalogue, zero everywhere else since dev has no live vendor/
  salesman/customer/subscription/payment/lead rows at rest). Seeded one
  vendor (`pending_verification`) with an active subscription expiring
  in 15 days and a ₹1,500 payment — confirmed all three numbers
  (Vendors: 1, Expiring in 30 days: 1, Pending verification: 1,
  Revenue this month: ₹1,500.00) updated correctly. All seeded rows
  force-deleted afterward, confirmed zero remaining.

**Phase 4 is closed.**

## 2026-08-25 — Fix: Add Vendor pipeline had no navigation entry point

The comprehensive Phase 0–5 audit (same date) found that tasks
3.2–3.6's entire Add Vendor pipeline — draft, KYC, plan selection,
services, subscribe, credential share — was fully built and
independently functional, but **unreachable from anywhere in the
app**: `AddVendorView` had zero call sites in `mobile/lib`. Unlike
every other gap the audit found, this one wasn't flagged in any
docblock or here in PROGRESS.md — it only surfaces by actually trying
to use the salesman app.

### Fix

- `salesman_home_view.dart`: new app-bar icon button
  (`Icons.person_add_alt_1`, teal, leftmost action) navigating to
  `AddVendorView`, visible from both the My Vendors and Earnings
  tabs. Refreshes `MyVendorsController` on return (if registered) so
  a just-added vendor shows immediately — same navigate-then-refetch
  shape `VendorDashboardView.addMoreServices()` already uses.
- `my_vendors_view.dart`: the empty state ("No vendors added yet")
  gained a matching call-to-action button, since that's exactly where
  a salesman with zero vendors would look for how to add one.
- No new `StringRes` key needed — reused the existing
  `addVendorTitle` ("Add vendor") for both the tooltip and the
  button label.

### Also cleaned up: four stale comments the same audit found

- `VendorSearchService.php`'s `MIN_REVIEWS_FOR_RATING_SORT` docblock
  called itself "currently inert for every vendor" — false since task
  5.5's `RecalculatesVendorRating` has kept `rating_avg`/`rating_count`
  live since that task landed. Corrected to say so.
- `vendor_dashboard_controller.dart` said "No leads, no rating...
  omitted entirely" — false since task 4.8 built working Leads and
  Reviews tabs. Corrected: only an aggregate rating figure is absent
  on this specific screen (correctly — no rating data existed until
  task 5.5, which this controller predates).
- Two leftover "documented stub" references to
  `CustomerSubcategoriesController.selectSubcategory()`
  (`VendorSearchController.php` and `customer_home_controller.dart`)
  predated task 5.3 and described a stub that's been a complete,
  working navigation for several sessions. Reworded to describe what
  the code actually does now.

### Verified

- Flutter: `flutter analyze` clean. Two new widget tests added to
  `salesman_home_tabs_test.dart` — the app-bar icon and the
  empty-state button both confirmed to navigate to `AddVendorView`.
  Full suite: **139 passed** (up from 137).
- Backend: the two docblock-only edits touch no logic —
  `VendorSearchEndpointTest` (13 cases) reconfirmed passing in
  isolation, full suite reconfirmed passing fresh afterward.

## 2026-08-25 — Task 6.2: Leads & Call Analytics (admin Filament page)

### Survey

- `Lead` has `vendor_id`/`subcategory_id`/`zone_id` (nullable) — no
  `category_id`. `Subcategory belongsTo Category`, so the category
  filter had to resolve via `whereHas('subcategory', fn ($q) =>
  $q->where('category_id', ...))`, not a direct-relation
  `SelectFilter`, per your note.
- No custom Filament Page existed anywhere before this — every prior
  screen was a Resource or the `DashboardStatsOverview` widget sitting
  on the stock Dashboard. This is the first one.
- Chart.js confirmed already vendored: `filament/widgets` (a
  transitive dependency of `filament/filament`) ships
  `ChartWidget`/`LineChartWidget` with its own Alpine component,
  already published to `public/js/filament/widgets/components/chart.js`
  from the existing `filament:assets` run. Zero new dependency, zero
  `AdminPanelProvider` changes — same "vendor locally" shape as
  Leaflet, confirmed exactly as you expected.
- **A real gap found in `PolicyCoverageTest` during survey**: its
  permission-to-policy matching loop only ever looked at
  `Filament::getPanel('admin')->getResources()` — it had no way to
  see a Page-gated permission, since Pages don't have a `getModel()`.
  Adding `Permission::LeadsViewAny` would have silently failed that
  test despite being wired correctly. Fixed by adding
  `additionalPolicySources()` (currently `[Lead::class]`) and folding
  it into the matching loop's search space — a real fix to the
  coverage test itself, not a workaround, and it now covers the next
  Page-gated permission too.

### Decisions

- **Permission gating** (your call, via `AskUserQuestion`): gated with
  a new `Permission::LeadsViewAny`, not left open like the Dashboard —
  this page is raw per-lead detail (which customer contacted which
  vendor, when), closer in sensitivity to the already-gated Resources.
- **Chart filter independence**: `LeadsOverTimeChart` uses
  `ChartWidget`'s own native filter dropdown (7/30/90 days) rather
  than sharing live state with the table's vendor/category/zone/date
  filters — wiring the two together needs custom Livewire plumbing
  SPEC doesn't ask for, and they answer different questions (the table
  finds specific leads; the chart shows overall volume trend).
- **New `'Analytics'` navigation group** — distinct from the existing
  `'Master Data'`/`'People'`, since this is reporting, not
  people-management or master data.

### Backend

- `app/Enums/Permission.php` — `LeadsViewAny` case.
- `app/Policies/LeadPolicy.php` (new) — `module()` returns `'leads'`,
  inherits `viewAny`/`view` from `AdminModulePolicy` with no
  overrides (leads are never created/edited/deleted here). Auto-wired
  via Laravel's `App\Models\X` ↔ `App\Policies\XPolicy` convention.
- `app/Filament/Pages/LeadsAnalytics.php` (new) — `implements HasTable`,
  `use InteractsWithTable`, `canAccess()` routes through
  `auth()->user()->can('viewAny', Lead::class)`. Table eager-loads
  `['vendor', 'customer.user', 'subcategory.category', 'zone']`,
  default sort `created_at` desc. Filters: vendor (`SelectFilter`
  `relationship()`), zone (same, nullable-safe), category (custom
  `SelectFilter` with the subcategory-chain `->query()` closure above),
  date range (`Filter` with two `DatePicker` fields + an
  `indicateUsing()` chip).
- `resources/views/filament/pages/leads-analytics.blade.php` (new) —
  `<x-filament-widgets::widgets>` above `{{ $this->table }}`, wrapped
  in `<x-filament-panels::page>`.
- `app/Filament/Widgets/LeadsOverTimeChart.php` (new) — `ChartWidget`,
  type `line`. Walks a `CarbonPeriod` over the selected window so a
  day with zero leads renders as `0`, not a skipped point.
- `tests/Feature/Admin/PolicyCoverageTest.php` — extended per above.

### Verified

- New `tests/Feature/Admin/LeadsAnalyticsTest.php` (8 cases): listing,
  vendor filter, zone filter (including a null-zone lead correctly
  still visible unfiltered and correctly excluded when filtering by a
  *different* zone), **category filter through the subcategory chain**
  (the specific case flagged in the task), date-range filter at both
  boundaries, sub-admin without `leads.viewAny` forbidden, sub-admin
  with it can access and see data.
- New `tests/Feature/Admin/LeadsOverTimeChartTest.php` (3 cases):
  a gap day renders as `0` not a skip, the 7/30-day filter actually
  changes the window queried, defaults to 30 days with no filter
  selected.
- `PolicyCoverageTest`'s three existing tests stay green after the
  extension.
- Full suite: **584 passed** (up from 573).
- Live: temp `php artisan serve`, seeded two categories/subcategories,
  a zone outside the seeded Ahmedabad range, one vendor, one customer,
  and two leads (one 2 days old with a zone, one 40 days old with a
  null zone) directly in the dev database. Ran the page's exact
  query logic and the chart widget's `getData()` via tinker against
  this real data — category filter correctly isolated 1 lead each way,
  zone filter correctly isolated the zoned lead while the null-zone
  lead remained visible unfiltered, the 10-day date-range filter
  correctly returned only the 2-day-old lead, and the chart's 7-day
  window correctly excluded the 40-day-old lead while the 90-day
  window included both. (Login via curl against the Filament panel
  itself was attempted but abandoned — Filament's login is a Livewire
  component, not a plain form POST, so a raw curl session dance
  doesn't apply here; PHPUnit's `$this->get(...)->assertOk()` already
  exercises the real HTTP+Livewire+auth pipeline for the access-gate
  tests, which is the more rigorous check anyway.) All seeded rows
  force-deleted afterward, confirmed zero remaining.

## 2026-08-25 — Task 6.3/5.9: Commission & Payouts, cash reconciliation

### Survey

- `Commission`/`Payment` have carried `payout_reference`/
  `admin_verified_at` since task 3.4, unread and unwritten until now —
  this task is the first thing that closes the loop `PaymentService`'s
  design was built anticipating.
- **A real Filament constraint found and confirmed by reading the
  actual Blade source**: the stock `ListRecords` page (what
  `extends Resource` uses) has no header-widget slot at all —
  `list-records.blade.php` renders only `{{ $this->table }}`. Your
  call: Commission Payouts is a custom Page (`LeadsAnalytics`'s exact
  shape), not a Resource, so the per-salesman totals widget can exist.
- **Correction mid-build**: initially planned a hand-rolled Blade
  table for the per-salesman totals widget, having missed that
  Filament ships a real `TableWidget` base class (`table()` +
  `InteractsWithTable`, same shape as every Resource). Switched to it
  before writing any Blade — no custom view needed at all, less code
  than planned.

### Backend

- `app/Enums/Permission.php` — `CommissionsViewAny`/`CommissionsMarkPaid`,
  `PaymentsViewAny`/`PaymentsVerify` (SPEC §5.16 itself names
  "payments" as a scoped-permission example).
- `app/Policies/CommissionPolicy.php`, `app/Policies/PaymentPolicy.php`
  (new) — `AdminModulePolicy` subclasses, `markPaid()`/`verify()`
  alongside the inherited `viewAny`, mirroring `VendorPolicy`/
  `ReviewPolicy` exactly.
- `app/Filament/Widgets/SalesmanCommissionTotals.php` (new) — a
  `TableWidget`, one row per salesman via
  `SUM(CASE WHEN status = 'pending' ...)` / `... 'paid' ...)` grouped
  by `salesman_id`. **A real bug caught during testing, not
  live-checking**: the grouped query never selected `id`, so every
  resulting row's primary key was null — Filament's row-identity
  mechanism (`$record->getKey()`) would have silently collapsed every
  salesman onto the same key. Fixed by selecting `salesman_id as id`
  alongside `salesman_id` itself (the latter still needed for the
  `salesman()` belongsTo relation to resolve). A salesman with zero
  commissions doesn't appear at all — no zero-padded row.
- `app/Filament/Pages/CommissionPayouts.php` (new) — table of every
  commission, salesman/status filters, `Number::currency` for amounts
  (`PlanResource`'s existing pattern). "Mark as paid" requires
  `payout_reference` (mirrors `ReviewResource::hideAction()`'s
  "reason required" shape), visible only while `status = pending`.
  **Second correction mid-build**: the salesman filter first tried
  `->relationship('salesman.user', 'name')` — a nested dotted
  relationship path, which Filament's `SelectFilter::relationship()`
  doesn't reliably support the way `TextColumn` does. Fixed to filter
  directly on `salesman` (Commission's real relation) with
  `getOptionLabelFromRecordUsing()` supplying the person's name as
  the label, since `Salesman` itself has no name column.
- `app/Filament/Resources/PaymentReconciliationResource.php` (new) —
  a genuine Resource (no aggregation need here), `getEloquentQuery()`
  scoped to `mode = cash AND admin_verified_at IS NULL`, same queue
  shape as `VendorVerificationResource`. "Mark verified" just confirms
  and sets `admin_verified_at = now()` — deliberately not coupled to
  the matching commission's own payout status, stated explicitly in
  the action's own modal copy so it doesn't read as doing more than
  it does.
- New navigation group `'Finance'` for both.
- `tests/Feature/Admin/PolicyCoverageTest.php` — `Commission::class`
  added to `additionalPolicySources()` (extended last session for
  `Lead::class`), same reason: a Page-gated permission has no
  Resource for the coverage test to discover it through otherwise.

### Verified

- New `tests/Feature/Admin/CommissionPayoutsTest.php` (10 cases):
  listing, salesman/status filters, mark-as-paid validation
  (`payout_reference` required), mark-as-paid sets
  `status`/`paid_at`/`payout_reference` correctly, **a real
  `AuditLog` row confirmed for the update** (not assumed), the action
  hidden once already paid, full permission-gate split (no access /
  view-only-cannot-act / can-act).
- New `tests/Feature/Admin/SalesmanCommissionTotalsTest.php` (3
  cases): pending/paid totals correct and isolated across two
  salesmen with mixed statuses, a zero-commission salesman doesn't
  appear, `cancelled` commissions excluded from both totals.
- New `tests/Feature/Admin/PaymentReconciliationResourceTest.php` (7
  cases): queue lists only unverified cash (excludes online, free,
  and already-verified cash in one test), mark-verified sets
  `admin_verified_at`, **a real `AuditLog` row confirmed**, a
  just-verified payment drops out of the queue immediately, full
  permission-gate split.
- `PolicyCoverageTest`'s three existing tests stay green.
- Full suite: **604 passed** (up from 584).
- Live: temp `php artisan serve`, seeded one salesman with a pending
  commission and one cash payment awaiting verification directly in
  the dev database. Confirmed the widget's exact aggregate query
  against real data, then exercised both `update()` calls the actions
  use and confirmed real `AuditLog` rows were written for each — not
  assumed from the trait's design, actually queried
  (`user_id` correctly attributed to the acting admin, `new_values`
  containing exactly the changed fields) — and confirmed the
  now-verified payment genuinely dropped out of the reconciliation
  queue. All seeded rows, including the audit log entries they
  produced, force-deleted afterward, confirmed zero remaining.

## 2026-08-25/26 — Fix: EditCategory's leaked DeleteAction (SPEC §10)

Found while surveying delete conventions for the Banner task below —
flagged to you directly rather than silently working around it.

`CategoryResource\Pages\EditCategory::getHeaderActions()` registered
a real `Actions\DeleteAction::make()`, reintroducing exactly the
hard-delete path SPEC §10 forbids for master data. `CategoryResource`'s
own table, `SubcategoriesRelationManager`, and `ZoneResource`'s
equivalents all correctly omit it (confirmed by reading each) — this
was the one place the rule leaked back in, apparently missed by the
original task 1.2 decision.

**Why it went undetected**: `CategoryResourceTest`'s existing "no
delete action" test only exercised `ListCategories` (the table). It
never tested `EditCategory` at all, so a header action — a
genuinely separate registration from table row actions — had no
coverage. `ZoneResourceTest` already had the equivalent Edit-page
test (`test_the_edit_page_offers_no_delete_either`), which is exactly
why `EditZone` never had this bug — confirming the fix needed is
about test coverage, not just the one line of code.

### Fix

- `EditCategory.php`: `getHeaderActions()` now returns `[]`, matching
  `EditZone.php`.
- `CategoryResourceTest.php`: new
  `test_no_delete_action_is_registered_on_the_category_edit_page()`,
  same shape as `ZoneResourceTest`'s existing one — asserts absence on
  the Edit page specifically, not just the table.
- `ZoneResourceTest.php`: left its existing Edit-page test in place;
  updated its docblock to explain why it's there and what it would
  have caught.

### Verified

- Full suite: 604 passed (unaffected count — a fix + one new test,
  net neutral against the last logged total).

## 2026-08-26 — Task 6.4: Banner Management

### Survey

- `banners` (Phase 1) already had `click_count` and a
  `banners_serving_index` explicitly comment-tagged "resolving which
  banners to show right now" — unused until this task.
- **Missed on first pass, found before writing the "add disk column"
  migration**: `banners.disk` already existed. A shared Phase 1
  migration (`add_disk_column_to_file_owning_tables.php`) had already
  added it to `categories`, `subcategories`, `vendors`, **and
  `banners`** in one batch, its own docblock explicitly anticipating
  this task ("vendors and banners are written in Phases 3 and 6, by
  which time the model default applies on create"). My initial survey
  only grepped migration *filenames* for "banner" and missed this
  generically-named one. Deleted the redundant migration I'd
  written before it ran against dev.
- SPEC.md mentions "banner" exactly once (§5 item 5) — zero mentions
  in the salesman/vendor/customer flow sections. Confirmed no spec
  exists anywhere for where/how a banner renders in any app — same
  shape as the `cms_pages` gap the Phase 0-5 audit found. Flutter
  display work explicitly out of scope, flagged rather than guessed.
- Hard-delete judgment: banners are NOT master data in SPEC §10's
  sense — that rule exists specifically because `subscription_items`
  references categories/subcategories/zones without a real foreign
  key, so a hard delete would orphan live subscriptions. Nothing
  references a banner by id anywhere. CLAUDE.md's `SoftDeletes` list
  (`users, vendors, subscriptions`) is a closed enumeration banners
  aren't on either. Real `DeleteAction`, no `SoftDeletes`.

### Backend

- `app/Models/Banner.php` (new) — `TracksFileDisk`
  (`fileDiskPathColumn() = 'image_path'`), `RecordsAuditLog` (matches
  every other admin-editable content model), a `scopeServing()`
  exercising the `banners_serving_index`.
- `app/Policies/BannerPolicy.php` (new) — the first policy in this
  codebase to add its own `delete()` beyond `AdminModulePolicy`'s
  base (master-data policies deliberately have none). 4 new
  `Permission` cases (`Banners{ViewAny,Create,Update,Delete}`).
- `app/Filament/Resources/BannerResource.php` (new) + full CRUD pages
  — the first resource in this app with a real, working
  `DeleteAction`. **Two real bugs caught by tests, not by
  inspection**:
  1. `EditBanner`'s `DeleteAction::make()` needed an explicit
     `->visible(fn ($record) => Auth::user()?->can('delete', $record))`
     — Filament's stock `DeleteAction` does **not** auto-authorize
     against the model policy just by being declared, unlike what I'd
     assumed from the (buggy) `EditCategory` precedent above. Every
     other gated action in this codebase (`VendorVerificationResource`,
     `ReviewResource`, `CommissionPayouts`) already does this
     explicitly — Banner just needed the same treatment.
  2. Testing the Edit page against a `Banner::create()` fixture whose
     `image_path` was a bare string (never actually uploaded) tripped
     the `FileUpload` field's `required()` validation on every
     edit/save test — the field re-validates that the file genuinely
     exists on hydration. Fixed by having the test fixture actually
     write the fake file to a faked disk, not just set a path string.
- `app/Http/Controllers/Api/BannerController.php` (new) — `index()`
  (public, unpaginated, same bounded-master-data exception as
  categories/zones/plans) and `click()` (atomic `increment()`, never
  read-modify-write). New `throttle:banner-click` limiter, per-IP
  like `public-read` since clicks are anonymous.
- `app/Http/Resources/BannerResource.php` (new, API shape) —
  deliberately excludes `click_count` from the response.

### Verified

- New `tests/Feature/Admin/BannerResourceTest.php` (9 cases) and
  `tests/Feature/Api/BannerEndpointTest.php` (9 cases): full CRUD
  including a genuinely working delete, `disk` stamped on create,
  `click_count` unreachable through the form, serving query correctly
  excludes wrong-app/not-started/ended/inactive banners in one test
  each, position filter optional, response unpaginated, clicks
  accumulate correctly and 404 on a nonexistent banner, full
  permission-gate split (including the update-vs-delete distinction
  the first gating bug above was actually about).
- Full suite: **623 passed** (up from 604 + 1 from the Category fix
  above = 605 baseline; +18 Banner tests).
- Live: temp `php artisan serve`, seeded five banners directly in the
  dev database (live-now, not-yet-started, already-ended, inactive,
  wrong-app). Confirmed the serving query returned exactly the
  live-now one via curl, three clicks brought `click_count` to
  exactly 3, and a real delete reduced the raw table row count by
  one (not soft-deleted — `Banner::find()` returned null and
  `DB::table('banners')->count()` actually dropped). All seeded rows
  force-deleted afterward, confirmed zero remaining.

## 2026-08-26 — Task 6.6: CMS Pages

### Survey

- `cms_pages` has existed since Phase 1 with exactly the right shape
  (`slug` unique, `title`, `body` longText, `target_app` nullable,
  `is_published`/`published_at`, `updated_by`) but no model, resource,
  or route had ever been built on top of it.
- Markdown rendering needed **zero new dependencies**:
  `league/commonmark` is already vendored transitively (via Filament),
  and `Illuminate\Support\Str::markdown()` wraps it directly.
- `routes/web.php` had only the default Laravel welcome route — this
  is the first genuine public Blade-view work in the app; app store
  submission needs a real URL a reviewer can open in a browser, not a
  JSON response, so this had to be `routes/web.php`, not `routes/api.php`.
- Resolved the "do the Flutter apps need in-app Terms/Privacy screens
  now" question from BUILD_PLAN's own task 8.4 ("Store prep"), which
  already scopes in-app reachability to Phase 8 — not a fresh gap,
  unlike the banners/settings display gaps found in the big audit.
  This task builds what 8.4 will link to.

### Decisions

- **No delete action** — same outcome as Category/Zone, but for a
  genuinely different reason. SPEC §10's referential-integrity
  argument doesn't apply (nothing references a `cms_page` by id); the
  real risk is that `slug` is a fixed URL an app store listing may
  already reference — deleting `privacy-policy` would 404 a URL
  Apple/Google's cached listing still points at. `is_published` is
  the safe takedown path instead.

### Backend

- `app/Models/CmsPage.php` (new) — `RecordsAuditLog`, `scopePublished()`.
- `app/Policies/CmsPagePolicy.php` (new) — module `pages`, deliberately
  no `delete()`. 3 new `Permission` cases
  (`Pages{ViewAny,Create,Update}` — no `PagesDelete`).
- `app/Filament/Resources/CmsPageResource.php` (new) + CRUD pages —
  `MarkdownEditor` for `body`, no `DeleteAction` anywhere (table or
  Edit header, the two-place check the Category bug taught us to
  make). `CreateCmsPage`/`EditCmsPage` stamp `updated_by` from the
  acting admin and set `published_at` the first time a page is
  published, without moving it on subsequent edits.
- `database/seeders/CmsPageSeeder.php` (new) — the 5 SPEC-listed
  slugs (`terms`, `privacy-policy`, `refund-policy`, `faq`, `about`)
  seeded unpublished, idempotent via slug, added to `DatabaseSeeder`.
- `app/Http/Controllers/PageController.php` (new, NOT under `Api`) —
  `index()`/`show($slug)`, both scoped to `CmsPage::published()`;
  unpublished or nonexistent slugs 404 via `firstOrFail()`.
- `resources/views/pages/{show,index}.blade.php` (new) — plain
  semantic HTML using the theme's dark tokens, no build step.
- `routes/web.php` — `GET /pages` and `GET /pages/{slug}`, registered
  before the wildcard route so `index` is never matched as a slug.

### Verified

- New `tests/Feature/Admin/CmsPageResourceTest.php` (10 cases) and
  `tests/Feature/Web/CmsPageEndpointTest.php` (5 cases, a new test
  namespace — first public web-page tests in the suite): CRUD,
  `updated_by`/`published_at` stamping, no-delete-action on both the
  table and the Edit page header, permission gate, markdown-to-HTML
  rendering, unpublished/nonexistent slugs 404, index lists only
  published pages, raw HTML in `body` is escaped not executed.
  Extended `SeederTest` with the expected 5-slug assertion.
- Full suite: **638 passed** (up from 623 + 15 new). One run hit an
  unrelated `salesmen_phone_unique` collision from a hardcoded phone
  literal shared across several pre-existing test files; a clean
  rerun passed all 638, confirming it was a pre-existing
  order-dependent flake, not caused by this task (nothing here
  touches Salesman).
- Live: temp `php artisan serve` against the real dev database, ran
  `CmsPageSeeder` (5 rows), confirmed `/pages/privacy-policy` 404s
  while unpublished, published one via tinker and confirmed
  `/pages/privacy-policy` rendered real HTML with markdown converted
  (`**your**` → `<strong>your</strong>`) and appeared in `/pages`,
  confirmed a real `AuditLog` row was written by the plain
  `->update()` call, then reverted the page to its seeded unpublished
  placeholder state. No force-delete cleanup needed — the 5 seeded
  slugs are meant to persist.

## 2026-08-26 — Task 6.7: Settings admin page

### Survey

- `settings` has existed since Phase 1, `Setting::get()` since task
  3.4, but nothing wrote to it except the seeder — this task is the
  missing write side for the 5 keys SPEC §5.17 names.
- **Consumed today**: `free_trial_max_days` and
  `free_grants_per_salesman_month`, both read by
  `FreeTrialValidator` (task 3.4/3.5); `free_trial_max_days` is also
  echoed through the public `GET /api/settings`.
- `grace_period_days`: unconsumed as expected, awaiting Phase 7's
  expiry job — confirmed via grep, only in seeder/migration.
- **`maintenance_mode`/`force_update_version`: confirmed genuinely
  unenforced anywhere**, not just unwired to Flutter — grepped the
  entire repo including `mobile/`: no middleware gate, no Flutter
  reference at all. `SettingController`'s and `SettingSeeder`'s own
  docblocks already said as much. This task makes them
  admin-editable, not functional — flagged in the form's own helper
  text (not just code comments), same treatment as the banners-
  display and cms_pages gaps.

### Backend

- `app/Filament/Pages/SettingsPage.php` (new) — a single custom
  Filament Page with a form, not a Resource: fixed keys, not a CRUD
  collection. The first form-only custom Page in this app
  (`Filament\Forms\Contracts\HasForms` +
  `Filament\Forms\Concerns\InteractsWithForms`) — `LeadsAnalytics`/
  `CommissionPayouts` are custom Pages too but both use `HasTable`
  instead, since this page has no table at all.
- `mount()` loads current values via `Setting::get()`;
  `save()` writes each key back through a real per-row
  `Setting::update()` call (never a query-builder mass update), so
  `RecordsAuditLog`'s hook actually fires, stringifying per the
  setting's declared `type` so `Setting::get()`'s existing cast logic
  reads it back correctly.
- **`Setting` model gets `RecordsAuditLog`** — it didn't have it
  before. Every other admin-editable model in this codebase does, and
  SPEC §5.14 calls out audit logging as "especially important since
  salesmen can grant free subscriptions" — exactly what
  `free_trial_max_days`/`free_grants_per_salesman_month` are.
- Two form `Section`s matching the seeder's own `group` values
  ("Subscriptions", "App"); the App section's two fields carry
  explicit helper text stating they aren't enforced yet.
  `force_update_version` validates against an `x.y.z` regex.
  Respects `is_editable` defensively on save (skips a key if it's
  ever flipped false, even though all 5 are `true` today).
- `app/Policies/SettingPolicy.php` (new) — module `settings`, no
  `create()`/`delete()`: the key set is fixed, nothing to add or
  remove through the panel. New `Permission::SettingsViewAny`/
  `SettingsUpdate`. `PolicyCoverageTest::additionalPolicySources()`
  extended with `Setting::class` — same Page-gated-permission reason
  as `Lead::class`/`Commission::class`.

### Verified

- New `tests/Feature/Admin/SettingsPageTest.php` (8 cases): renders
  with seeded values pre-filled, saving round-trips all 5 keys
  through their correct types, malformed `force_update_version`
  rejected, a real `AuditLog` entry written for a changed key and
  none written when nothing changed, full permission-gate split. The
  view-only-cannot-save case needed a different assertion shape than
  Banner/Commission's `assertActionHidden` precedent — Livewire
  absorbs the `abort_unless(403)` into its own response rather than
  letting it propagate as a catchable exception to the test, so the
  test asserts the value never actually moved instead of asserting an
  exception was thrown.
- `PolicyCoverageTest`'s three existing tests stay green.
- Full suite: **646 passed** (up from 638).
- Live: temp `php artisan serve`, confirmed `/admin/settings-page`
  redirects a guest to login (route genuinely registered and gated).
  Via tinker (matching the Leads/Commission live-check pattern for
  gated admin pages — exercise the same `update()` call the page
  itself makes, not a scripted Livewire/CSRF walkthrough): updated
  `free_trial_max_days` and `maintenance_mode` on real seeded rows,
  confirmed `Setting::get()` reflected both new values and a real
  `AuditLog` row existed with correct `user_id` attribution and
  `old_values`/`new_values`, then reverted both to their seeded
  defaults. Also found and killed a stray `artisan serve --port=8000`
  pair that had respawned since the last cleanup — confirmed zero
  `php.exe` processes remained afterward.

## 2026-08-26 — Task 7.1: Daily expiry job

### Survey

- **Enum values confirmed, no migration needed.** `subscriptions.status`
  already `active|grace|expired|cancelled|superseded` (the `superseded`
  case widened in a prior task via the raw `ALTER TABLE ... MODIFY`
  pattern); `vendors.status` already
  `draft|pending_payment|pending_verification|active|grace|expired|rejected`.
- **Real bug caught by the survey itself, before any code was
  written**: `VendorSearchService`'s query hardcoded
  `subscriptions.status = 'active' AND end_date >= now()`. Once this
  job existed and correctly flipped a subscription to `grace`, that
  literal predicate — plus `vendors.status = 'active'` — would exclude
  every grace vendor from search immediately, contradicting SPEC
  section 7's "Grace = still visible, still has a chance to renew"
  and BUILD_PLAN 7.1/8.2's own wording (only *Expired* should drop a
  vendor from search). The fix had to ship with the job itself, not
  after.
- **A second, less obvious consequence of the same root cause**:
  `Vendor::currentActiveSubscription()` (backing the vendor's own
  dashboard, the public detail page, and portfolio/add-items quota
  checks) had the identical flat bound. Fixing only the search query
  would have left a grace vendor appearing in search results but
  404ing the moment a customer tapped into their detail page.
- **A third consumer found mid-implementation, not in the original
  survey**: `Vendor::scopeActive()` (`status = 'active'`) gates
  `VendorDetailController::show()`'s *first* lookup (`Vendor::active()
  ->find($vendorId)`, before `currentActiveSubscription()` is ever
  reached) and `FavoriteController::index()`'s favorites list. Fixing
  only `currentActiveSubscription()` would have left both of these
  404ing/silently-dropping a grace vendor regardless of the other
  fixes. Widened to `whereIn('status', ['active', 'grace'])` too.
- **A fourth, `GET /api/vendors/me`'s `has_active_subscription`
  flag** (`VendorResource`) had its own independent ad-hoc query with
  the same flat bound — a vendor entering grace would have been
  bounced back to the "select a plan" screen on next login instead of
  landing on their dashboard to renew. Replaced with a call to the
  now-fixed `currentActiveSubscription()` instead of a fourth copy of
  the same logic.
- **A real bug found while tracing the cascade itself**:
  `VendorVerificationService::reject()` only ever sets
  `vendor.status = 'rejected'` — it never touches the vendor's
  subscription row, which was already created `active` by
  `SubscriptionService::subscribe()` *before* admin review (a
  self-registered vendor subscribes first, then gets approved or
  rejected). Without a guard, the expiry job would have silently
  resurrected a rejected vendor from `rejected` back to `grace` once
  their irrelevant, already-rejected subscription's `end_date` lapsed.
- **Renewal gap confirmed, flagged rather than built** (your call):
  `ChangeSubscriptionPlanRequest::validateSubscriptionIsActive()`
  hard-rejects anything but `status === 'active'` — *"Only an active
  subscription can change plans."* No other endpoint anywhere takes a
  `grace`/`expired` subscription back to `active`. SPEC section 7 says
  *"renewal restores to Active"* but never describes the mechanism,
  and it's absent from every Phase 4 upgrade/downgrade/add-on task.
  **Open gap for a future task.**
- **A separate, pre-existing, NOT fixed here**:
  `User::hasSalesmanAssignedActiveSubscription()` (gates whether a
  salesman-added vendor can skip email verification) checks
  `end_date >= now()` with no `status` check at all — already broken
  today independent of this job, for any salesman-added vendor whose
  subscription date has simply passed. This job doesn't make it worse
  (it doesn't touch email-verification logic), but it's a related,
  real, still-open issue worth a dedicated look.
- Notifications (expiry reminders T-15/T-7/T-1) confirmed out of
  scope — BUILD_PLAN 7.2, a separate later task.
  `SalesmanController::vendors()` confirmed to need no change — its
  own docblock already says "not filtered to active-only."

### Backend

- `app/Console/Commands/ProcessSubscriptionExpiry.php` (new — the
  first Artisan command/scheduled job in this app):
  `subscriptions:process-expiry`, two passes (Active→Grace,
  Grace→Expired), each scoped with `whereHas('vendor', fn ($q) =>
  $q->where('status', $fromStatus))` specifically to guard against the
  rejected-vendor bug above, `chunkById()` for scale, each row updated
  via a real Eloquent `update()` per model (never a mass query-builder
  update) so `RecordsAuditLog` — already on both `Subscription` and
  `Vendor` — fires normally.
- `routes/console.php` —
  `Schedule::command('subscriptions:process-expiry')->daily()`,
  Laravel 12's routes-based scheduling (no `app/Console/Kernel.php`
  in this version).
- `app/Services/VendorSearchService.php` — the vendor/subscription
  status and date bounds widened to the grace-aware conditional
  described above, reading `grace_period_days` via the existing
  `Setting::get()` helper (task 3.4 pattern) for the first time
  anywhere in the app.
- `app/Models/Vendor.php` — `currentActiveSubscription()` given the
  identical grace-aware bound; `scopeActive()` widened to
  `whereIn('status', ['active', 'grace'])`.
- `app/Http/Resources/VendorResource.php` — `has_active_subscription`
  now delegates to `currentActiveSubscription()` instead of its own
  ad-hoc query.

### Verified

- New `tests/Feature/Console/ProcessSubscriptionExpiryTest.php` (12
  cases, a new test namespace — first Artisan-command tests in the
  suite): both transition boundaries exactly (end_date = today stays
  active; one day past moves), the default 7-day grace period and a
  custom seeded value both respected, `cancelled`/`superseded`
  subscriptions never touched, **the rejected-vendor regression case
  confirmed** (a real test, not hypothetical), `is_suspended` left
  untouched by either transition, real `AuditLog` rows for both the
  subscription and the vendor, running twice in a row transitions
  exactly once.
- Extended `VendorSearchEndpointTest.php` (+3), `VendorDetailEndpointTest.php`
  (+2), `VendorMeEndpointTest.php` (+2): grace-within-window
  visible/reachable/dashboard-landing in every case, past-the-window
  and expired excluded in every case.
- Full suite: **665 passed** (up from 646).
- Live: temp `php artisan serve` against the real dev database,
  seeded 4 real scenarios (active past end_date, grace within window,
  grace past window, rejected vendor with a dangling active
  subscription) with full subcategory/zone coverage. Ran
  `php artisan subscriptions:process-expiry` directly — reported
  exactly "Active -> Grace: 1. Grace -> Expired: 1." Confirmed via
  tinker: the active vendor moved to grace, the already-in-window
  grace vendor stayed, the past-window grace vendor moved to expired,
  and **the rejected vendor's status never moved** — with real
  `AuditLog` rows for both genuine transitions. Confirmed through the
  actual running API, not just the database: `GET /api/vendors/search`
  returned both grace-window vendors, `GET /api/vendors/{id}/detail`
  returned 200 for the grace-window vendor and 404 for both the
  expired and rejected ones. All seeded rows and their audit log
  entries force-deleted afterward, confirmed zero remaining.
- Killed a stray `artisan serve --host=0.0.0.0 --port=8000` pair
  found running again — this is the third occurrence of this exact
  process across recent tasks. Worth a look at what keeps starting it
  outside these sessions (a VS Code task, another terminal, a watcher)
  rather than continuing to clean it up silently each time.

## 2026-08-27 — Task 7.2: FCM push notifications

### Survey

- `device_tokens` confirmed genuinely missing — already named in this
  checklist as a known gap. Genuinely new: table, model, registration
  endpoint.
- `PushNotificationService::notifyVendorApproved/notifyVendorRejected`
  (stubs since task 4.3) and `notifyReviewRequested` (stub since task
  4.8) confirmed still no-ops with real call sites already wired —
  this task gave them real bodies, the call sites didn't move.
- "Lead received" confirmed genuinely missing anywhere — new call
  site added in `LeadController::store()`.
- `User` already had `Notifiable` mixed in but **zero** real usage
  anywhere (`->notify(`/`Notification::` grepped clean across app and
  tests) — confirmed this is a green field, and that Laravel's own
  Notification system (not a bespoke service) is what BUILD_PLAN 7.2
  itself names ("Add Laravel Notifications for: ...").
- `notifications` table's existing campaign-log design confirmed to
  still hold — a single-recipient automated send is a campaign of one
  (`audience` holds `{"user_id": N}` instead of a broad filter). Its
  own docblock already named all 4 automated triggers as belonging
  here, so no schema change was needed.
- Expiry-reminder idempotency deliberately does NOT live in that
  table — matches the `leads.review_requested_at` idiom (task 4.8)
  instead: three nullable `reminder_sent_t{15,7,1}_at` columns
  directly on `subscriptions`, each set once.

### Backend

- `device_tokens` (new table + `DeviceToken` model, `User::deviceTokens()`)
  — `token` globally unique (a token identifies a physical install,
  not a permanent owner; re-registering under a different user
  reassigns it, tested explicitly).
- `POST`/`DELETE /api/device-tokens` (`DeviceTokenController`) — the
  unregister endpoint was your explicit addition beyond what was
  literally asked, so a logged-out device stops receiving push rather
  than relying on FCM to notice a stale token.
- `App\Services\Fcm\ServiceAccountJwt` — hand-rolled RS256 signing via
  `openssl_sign()` (your call over adding `firebase/php-jwt`), pure
  and deterministic, unit-tested against a static fixture keypair
  (this dev machine's `openssl_pkey_new()` can't find a valid
  `openssl.cnf` — confirmed via `openssl_error_string()` — so the
  fixture is generated via the `openssl` CLI instead of at PHP
  runtime; signing/verifying against an existing PEM, what production
  actually does, is unaffected by that gap).
- `App\Services\Fcm\FcmClient` — both HTTP calls (OAuth2 token
  exchange, `messages:send`) go through Laravel's `Http` facade, so
  `Http::fake()` covers the whole path with zero real credentials.
  Access token cached 55 min. Fails loudly (throws) on blank
  `FCM_*` config rather than silently no-oping.
- `App\Notifications\Channels\FcmChannel` (new) — the one place that
  talks to FCM. Sends to every one of a notifiable's device tokens,
  **never lets a send failure propagate** (a bad token or FCM outage
  must not break `LeadController::store()`), writes exactly one
  `App\Models\Notification` (new model for the existing table) row
  per notification fired. Naming collision with
  `Illuminate\Notifications\Notification` handled via import alias,
  same treatment `CategoryResource`/`BannerResource` established.
- 5 notification classes (`App\Notifications\`):
  `VendorApprovedNotification`, `VendorRejectedNotification`,
  `ReviewRequestedNotification` (sent to the customer, not the
  vendor — already documented), `LeadReceivedNotification` (new,
  sent to the vendor), `SubscriptionExpiringNotification`
  (parameterized by threshold).
- `PushNotificationService`'s existing methods became one-liners
  (`$vendor->user->notify(new X(...))`) — the seam itself and every
  call site are unchanged; `notifyLeadReceived()`/
  `notifySubscriptionExpiring()` are new methods on the same service.
- `app/Console/Commands/SendSubscriptionExpiryReminders.php` (new,
  distinct from `subscriptions:process-expiry`) — three passes
  (T-15/T-7/T-1), each gated on `status = 'active'` (Grace has its
  own, different messaging, out of scope) and its own
  `reminder_sent_tN_at IS NULL`. Scheduled `->daily()` in
  `routes/console.php` alongside the existing expiry job.
- `config/services.php`'s new `fcm` block + `FCM_PROJECT_ID`/
  `FCM_CLIENT_EMAIL`/`FCM_PRIVATE_KEY_BASE64` in `.env`/`.env.example`
  (blank — see the Before Launch Checklist), matching the discrete-
  env-var pattern `AWS_*` already uses for R2 rather than a single
  JSON blob.

### Verified

- New: `tests/Unit/Fcm/ServiceAccountJwtTest.php` (4 cases, pure
  crypto, no HTTP/app boot), `tests/Feature/Fcm/FcmClientTest.php` (6
  cases — exact FCM payload asserted, token-exchange JWT shape
  asserted, access-token caching confirmed via request count, a
  failed FCM response returns `false` not an exception, a blank
  config throws before any HTTP call), `tests/Feature/Api/DeviceTokenEndpointTest.php`
  (8 cases), `tests/Feature/Notifications/PushNotificationTriggersTest.php`
  (6 cases — exercises the REAL call sites, not `PushNotificationService`
  directly: `VendorVerificationService::approve/reject`,
  `POST /api/vendors/me/leads/{lead}/request-review`,
  `POST /api/leads`; confirms a device-token-less vendor still logs
  `sent_count = 0` cleanly, confirms a failed push never turns a
  successful lead-record response into a failure),
  `tests/Feature/Console/SendSubscriptionExpiryRemindersTest.php` (6
  cases — each threshold boundary, the intentional same-run catch-up
  behavior for a subscription never seen before, running twice
  doesn't resend, a `grace`-status subscription is skipped).
- **Real bug caught while writing tests, not by inspection**:
  `Http::fake()` MERGES repeated calls rather than replacing them —
  the first registered stub wins on a URL match. A test-local
  override of the shared `fcm.googleapis.com` success stub from
  `setUp()` silently never took effect. Fixed by keeping only the
  (universally-shared, never overridden) token-exchange fake in
  `setUp()` and declaring the FCM-send response explicitly per test.
- Full suite: **695 passed** (up from 665).
- Live: temp `php artisan serve` against the real dev database (no
  real FCM credentials — same constraint as R2). Registered a device
  token via the real API, recorded a real lead via the real API —
  confirmed the request still succeeded (201) with a real
  `notifications` row showing `sent_count = 0, failed_count = 1`,
  proving the fail-safe design live, not just in mocked tests.
  Unregistered the token, confirmed removal. Ran
  `subscriptions:send-expiry-reminders` against a seeded 15-day-out
  subscription, confirmed exactly `reminder_sent_t15_at` stamped
  (not t7/t1), confirmed a second run sent nothing further. All
  seeded rows force-deleted afterward, confirmed zero remaining.
  Temp server killed, confirmed zero `php.exe` processes remained.
