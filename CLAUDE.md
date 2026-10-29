# Project: Service Marketplace Platform

## What this is
A service marketplace connecting vendors (AC repair, plumbing, electrical,
etc.) to customers, sold primarily through a salesman-led channel, with
vendor self-signup as a secondary path. Admin manages master data and
approvals.

## Stack
- Backend: Laravel 12, PHP 8.3, MySQL/MariaDB (spatial columns for zones —
  local dev runs on XAMPP's MariaDB 10.4)
- Admin: Filament v3, dark mode on, primary color set in the panel
  provider — no separate frontend app. **Two panels** (decided
  2026-10-27): `/admin` (`AdminPanelProvider`, teal, `app/Filament/{Resources,Pages,Widgets}`)
  for daily work by every admin, and `/super-admin`
  (`SuperAdminPanelProvider`, blue, `app/Filament/SuperAdmin/...`) for
  super-admins only (`User::canAccessPanel`). Same login and database.
  `/super-admin` holds Admins (`AdminResource` — admin accounts and
  permissions; the `/admin` Users screen no longer shows or creates
  admins), System settings (`SystemSettingsPage` — the keys, sign-in and
  error-log sections; `/admin` Settings keeps only the business ones, and
  each page saves only its own keys), API error log, Activity log
  (`audit_logs`, read-only), and System health (`App\Support\SystemHealth`;
  the scheduler heartbeat is in `routes/console.php`). It requires
  two-step login (`RequireSuperAdminTwoFactor`, `TwoFactorService`, our
  own RFC 6238 `App\Support\Totp` — no package, no QR code): set up on
  first visit, a code per session, again after 30 idle minutes; recovery
  codes; "Reset two-step login" on Admins or
  `php artisan admin:reset-two-factor {email}`. `AdminAccountService`
  guarantees at least one super-admin and that nobody edits or deletes
  their own admin account. Tests for it use `Tests\Concerns\ActsAsSuperAdmin`
  (sets the current panel and a passed two-step session).
- Mobile: Flutter 3.x, GetX — but specifically the pattern in
  `mobile/lib/screens/**`, not generic GetX. See "Flutter conventions"
  below before writing any screen.
- Auth: Laravel Sanctum, one personal access token per device
- File storage: Laravel Filesystem, S3 driver → Cloudflare R2
- Queue: Laravel Queue (database driver is fine at this scale) +
  Laravel Scheduler for the daily expiry job
- Push: Firebase Cloud Messaging via HTTP v1 API
- Email: **only password links.** (1) The "Forgot password?" reset link —
  admin Settings → Password reset picks who sends it
  (`App\Services\PasswordResetService`): `laravel` (default — Laravel's
  broker; needs real `MAIL_*` in .env) or `firebase`. (2) With Firebase
  sign-in on (below), a "set your password" link to accounts someone else
  created (`App\Services\AccountInviteService` — decided 2026-10-27).
  (3) **Self-registration takes no password** (decided 2026-10-28, vendor
  and customer apps): `auth/register` creates the account with a random
  password and `must_change_password` set, then emails the same link via
  `PasswordResetService::sendLink()` **inside the transaction** — if the
  email cannot go, the account is rolled back and the app gets 503
  `PASSWORD_LINK_NOT_SENT`. No token is issued; the app shows
  `CheckEmailView` and the first sign-in comes after the link. The link's
  pages say "Set your password" / "Password set" while
  `must_change_password` is true (Laravel's email is worded the same way —
  `AppServiceProvider::configurePasswordLinkEmail`), and "Password set"
  has an "Open the app" button: `sm{vendor,customer,salesman}://login`
  (`PasswordResetWebController::appLinkFor`, Android flavour
  `appLinkScheme`; Flutter deep-link routing is off — it only launches
  the app, which starts on login). iOS has no flavours yet, so no scheme
  there.
  Nothing else sends mail — no welcome, verification or notification
  `mail` channel; do not add one without that being an explicit decision.
  Account approval stays the only sign-up gate.
- Sign-in: admin Settings → Sign-in (`auth_provider`, `App\Services\AuthProvider`).
  `laravel` (default) changes nothing. `firebase`: `auth/login`,
  `auth/change-password` and the reset pages check / copy passwords with
  Firebase over REST (`FirebaseAuthClient`, no SDK in Laravel or Flutter —
  the apps are unchanged and still get the same Sanctum token). **The
  `users` row stays the record of every account**; `users.firebase_uid`
  links its Firebase twin, kept in step by `User::booted()` (email change,
  delete). The local hash is updated on every password change this server
  makes, so: Firebase refuses but the local hash matches → accepted and
  pushed to Firebase (accounts move over at their next sign-in;
  `php artisan firebase:sync-users` is optional); Firebase unreachable →
  local hash decides. Forgot password is forced to Firebase's email.
  Salesman-sold vendors (at Subscribe) and admin-created accounts get a
  "set your password" email instead of a temporary password
  (`password_link_sent_to` in the subscribe response; resend via
  `POST vendors/{vendor}/password-link` and the Users list "Send password
  link"), only while `must_change_password` is true. If the email fails,
  the temporary password is shown as before.

## Architecture — Laravel backend
No restriction here on repositories, interfaces, or DTOs — use standard
Laravel patterns wherever they genuinely help. The "keep it flat, no
abstractions" rule further down applies to the **Flutter app only**, not
this backend.

- Controllers can call Eloquent models directly for simple CRUD, or go
  through a repository/service if that's clearer for the feature —
  your call.
- Multi-table transactional logic (e.g. `SubscriptionService::subscribe()`,
  `ZoneMatcher::vendorsFor($lat, $lng, $subcategoryId)`) belongs in
  `app/Services` regardless of whether repositories are used elsewhere.
- Validation lives in Form Requests, not inline in controllers.
- Reusable query logic can live in Eloquent scopes/accessors
  (`Vendor::active()`, `Subscription::expiringWithin(30)`) for simple
  cases, or a repository for more complex data access — either is fine.
- Prefer a well-named Artisan command over a custom Laravel Event +
  Listener class pair (with EventServiceProvider registration) for a
  one-off, single-consumer action — that's unnecessary ceremony for
  something that's really just a direct method call or a scheduled job.
  This does NOT apply to Eloquent model lifecycle events
  (`saving`/`saved`/`deleting`) used for cross-cutting concerns like
  audit logging (task 2.2) or the deletion guards (SPEC §10) — that's
  standard, idiomatic Laravel and stays.

## Conventions — API (Laravel)
- API response envelope: { success, data, error: { code, message } }
- Pagination via Laravel's built-in paginator on every list endpoint,
  EXCEPT bounded master-data endpoints consumed to populate app selection
  UI (categories, zones, plans) — these return the full result unpaginated
  since apps need the whole set in one request to render a browse grid or
  quota-selection screen. Endpoints backed by unbounded/growing
  collections (leads, reviews, users, vendors) still paginate normally.
- Money stored as integer paise, never float
- Dates in UTC, ISO 8601
- Server always recalculates price, quota usage, and expiry dates —
  never trusts client-sent values
- Subscription-creating endpoints require an `Idempotency-Key` header,
  enforced with a unique column
- Soft deletes (`SoftDeletes` trait) on: users, vendors, subscriptions
- **Passwords are reset only by the user, from the app — never by an
  admin.** The panel has no "Reset password" action and no Reset requests
  page; do not add either back. (Admins and salesmen still give a
  *temporary* password to accounts they *create* — that is account
  creation, with `must_change_password` set.)
- No email-verification endpoints exist. The apps' "Forgot password?"
  calls `POST auth/password-reset-requests`, which emails a reset link. An
  email with no account (or a deleted one) is refused with a 422 field
  error "No account found with this email", shown under the app's email
  field — a deliberate product choice; the `password-reset-request` rate
  limit (5/hour per IP, refused guesses included) is what stops it being
  used to test addresses in bulk. `method` depends only on config. The link opens a page on this server
  (`PasswordResetWebController`, routes `password.reset` /
  `password.firebase.show`) where the user sets a new password via
  `User::resetPasswordFromEmailLink()` — clears `must_change_password`,
  signs out every device. Firebase, when chosen, sends the email and
  vouches for the code (`FirebaseAuthClient`, reusing the FCM service
  account); the password is always saved in Laravel too. With Laravel
  sign-in, sign-in never touches Firebase; with Firebase sign-in see
  "Sign-in" under Stack. Firebase chosen but not set up → the Laravel email is used; a
  failed send is logged. The old `password_reset_requests` table is kept
  as history only — nothing writes to it. Signed-in password changes go
  only through `auth/change-password`.
- Every app's own name, email, phone and photo go through
  `ProfileController`: `PATCH user/profile` and `POST`/`DELETE
  user/avatar` (multipart field `photo`), each answering with the updated
  `UserResource` (which carries `phone` and `avatar_url`). The phone lives
  on the role's own row (`User::profilePhone()`); it is required and
  unique for vendors and salesmen, optional for customers. **Changing the
  email needs `current_password`** — it is the sign-in name and where a
  reset link goes. Business fields stay on `PATCH vendors/me`. In Flutter,
  customers and salesmen edit through the shared `PersonalDetailsView`;
  vendors on `VendorBusinessDetailsView`. The photo flow
  (`changeProfilePhoto()`) and `ProfilePhoto` live in
  `widgets/base_profile.dart`.
- Widening an existing enum column (adding a new status value) needs a
  raw `DB::statement("ALTER TABLE ... MODIFY ... ENUM(...)")` migration
  — Laravel's `->change()` isn't reliably supported on enum columns via
  Doctrine DBAL. This will recur any time a lifecycle gets a new state
  (e.g. vendors.status gaining 'rejected' in task 4.3).

## Filament testing conventions
Two gotchas that will recur on every resource with a reorder or inline
toggle column — write tests the correct way from the start rather than
rediscovering these:
- Drag-to-reorder is NOT a chainable Livewire test helper — call the
  reorder method directly with `->call(...)`.
- `ToggleColumn` is not an action — testing it via
  `callTableColumnAction` silently no-ops and the test passes regardless
  of whether the toggle works. Use `updateTableColumnState` and read the
  resulting value back from the database directly, never trust the
  response alone.
- For "this action must not exist" requirements (e.g. no delete on
  plans), assert the action is absent from the resource, not that it's
  present-but-disabled — this fails loudly if a later edit re-adds it,
  rather than silently reintroducing a live path.
- **Master data delete (decided 2026-10-27, overrides SPEC §10's "never
  delete"):** categories, subcategories, zones and sub-zones have a Delete
  button, but only ever through `App\Filament\Support\SafeDelete`, never a
  bare `DeleteAction` — on the table AND the edit page header. Each model's
  `deletionBlocker()` refuses it while anything depends on the record
  (subscriptions; children — subcategories / sub-zones; leads for a
  subcategory). No bulk delete. Permissions `categories.delete` (covers
  subcategories) and `zones.delete`. Plans still have no delete.
- The admin shows one table as two screens in two places: `CategoryResource`
  / `SubcategoryResource` (separate tables) and `ZoneResource` /
  `SubZoneResource` (same `zones` table — `SubZoneResource` extends
  `ZoneResource`, and `isSubZoneScreen()` / `scopeToScreen()` switch the
  parent field, columns and list scope). Link to a sub-zone through
  `SubZoneResource::getUrl()` — a sub-zone's URL under `ZoneResource` 404s.
- Before naming a method on a class that extends a framework base class
  (Filament components, Laravel's `TestCase`, anything similar), check
  the base class doesn't already declare it with a different signature.
  Confirmed twice now: `getDefaultView()` collided with Filament's
  `ViewComponent`, and `withToken()` collided with Laravel's own
  `TestCase` (already public there, redeclaring it private was the
  break). A collision is a fatal error, not a warning, and takes down
  the whole test run — check the base class first, in any framework.
- No third-party CDN scripts in the admin panel, ever — vendor JS
  dependencies locally via npm + `filament:assets` instead. The panel
  edits payments, subscriptions, and vendor verification; a compromised
  or MITM'd CDN script would run with full admin session privileges.
  This applies to future chart/rich-text/widget libraries too.
  **One deliberate exception:** Google Maps JavaScript on the zone form
  (`ZoneMap` field), loaded from maps.googleapis.com — Google does not
  allow self-hosting it, and the admins wanted Google's map and satellite
  imagery. It uses its own browser key, `GOOGLE_MAPS_BROWSER_KEY`, which
  must stay referrer-restricted to the admin domain and limited to the
  Maps JavaScript API. The Places key (`GOOGLE_MAPS_API_KEY`) remains
  server-side only. Do not widen this exception to other scripts.
  Both keys can also be set in admin Settings → Maps, which wins over
  .env — always read them through `App\Support\MapKeys`, never
  `config('map.*')` directly. The Places key is stored encrypted
  (setting type `encrypted`) and is write-only on the Settings page.
- A field with `dehydrated(false)` is also excluded from
  `mutateFormDataBefore*` hooks, not just from the saved model — if a
  hook needs to read that field to convert it (e.g. rupees entered,
  paise stored), keep the field dehydrated and `unset()` it inside the
  hook instead, or the hook receives nothing and the conversion silently
  no-ops (every value saves as the default/zero).
- A `Fieldset` using `->relationship('quota')` implicitly calls
  `statePath('quota')` — nested field state lives at `quota.max_zones`,
  not a flat `max_zones`. A test calling `fillForm()` with flat keys
  silently leaves the defaults in place rather than erroring, so the
  resulting test failure looks like an arbitrary wrong number instead of
  an obviously unfilled field. Nest test data to match the relationship
  path.
- For any field whose editability depends on the current user's role
  (not just the admin-permissions field), use `->hidden()`, never
  `->disabled()`. A disabled field's state still round-trips through the
  request — Filament doesn't stop a crafted submission from setting it
  regardless of what the rendered form shows. Hidden means it was never
  part of the form's state to begin with.
- Eloquent auto-calls `boot{TraitName}()` for traits (and `booted()` on
  the model) — this is a naming *convention*, not a hook you register.
  A plausible-looking `bootSomething()` that doesn't exactly match the
  trait's name is simply never called, with no error — the opposite
  failure mode from the collision above (that one crashes loudly; this
  one does nothing silently, e.g. a tracked column staying null
  forever). Get the trait name exactly right.

## Flutter conventions — follow the existing demo-app pattern exactly
Every screen is a `<name>_module/` folder with exactly two files. Do not
introduce bindings classes, repositories, use-cases, or `.obs`/reactive
streams — this app does not use them anywhere and new screens must match
the old ones, not "improve" on the pattern.

- **Controller** (`<name>_controller.dart`): extends `GetxController`.
  Plain (non-reactive) fields — booleans, strings, lists — mutated
  directly, followed by a call to `update()`. Forms use a
  `GlobalKey<FormState> formKey`. One method per screen action (e.g.
  `addUserNameAPI()`), calling `DataSource.instance.xxxAPI(body: body)`,
  wrapped in try/catch, toggling `Utils.showCircularProgressLottie()`
  around the call. Parse the response with `Model.fromJson()`, persist via
  `Injector.setUserData(...)` when relevant, navigate with
  `Utils.transitionWithOffAll(NextScreen())`.
- **Never `transitionWithOffAll` / `Get.offAll` to a screen that is still
  mounted lower in the stack.** Use `Get.back()` or
  `Get.until((r) => r.isFirst)` to unwind to it instead. This has caused
  the same crash twice — "A TextEditingController was used after being
  disposed", surfacing as a red box inside the My Vendors tab.
  `GetBuilder(init: X())` reuses an already-registered controller rather
  than creating its own, so the rebuilt screen shares the live one's
  controller; the outgoing route is its creator, and GetBuilder's
  `autoRemove` then deletes it on the way out, disposing a
  `TextEditingController` the new screen is mid-render on. Removing the
  explicit `dispose:` callback does NOT fix this — the creator's own
  auto-removal still fires. Only not building the duplicate does.
  Offenders so far: finishing a profile-initiated password change, and
  `SubscriptionConfirmationController.done()`. `offAll` remains correct
  where the destination is genuinely absent (login → home, logout → login).
- **View** (`<name>_view.dart`): a `StatelessWidget`. `build()` returns
  `GetBuilder<XController>(init: XController(), dispose: (_) =>
  Get.delete<XController>(), builder: (controller) => Scaffold(...))`.
  Read/write controller state off the builder argument as
  `controller.fieldName`. **Do not name the builder parameter `_`** —
  Dart 3.7+ treats `_` as a non-binding wildcard, so `_.field` is a hard
  compile error on this SDK, even though it worked in the original
  demo-app on an older Dart version. Use the `<name>_view.dart` /
  `<name>_controller.dart` shared `Base*` widgets (`BaseTextField`,
  `BaseRaisedButton`, `BaseTextDMSans`, ...) instead of raw Material
  widgets wherever one exists.
- **API layer**: add one named method per endpoint to
  `network/data_source.dart` (`DataSource.instance.xxxAPI(...)`). Do not
  create per-feature API classes or a generic HTTP repository.
- **Response shape**: every API method returns a `CommonResponse`
  (status/data/message from `common_model/`); parse `.data` into a typed
  model with a `fromJson` factory.
- **Shared state**: anything that needs to persist or be read across
  screens (access token, user profile, prefs) goes through the static
  `Injector` class, not a new state-management layer.
- **Strings**: add to `StringRes`, wrap with `easy_localization`'s `.tr()`.
- **Colors**: from `ColorRes` only — no inline hex values in a screen.
- **Spacing/sizing**: use the existing `.getSize` / `.heightSpacer`
  extensions for anything that should scale with screen size.
- **Imports**: start every new screen file with
  `import '../../../constants/app.export.dart';` (adjust relative depth)
  rather than importing individual constant/util files piecemeal, matching
  the existing files.

## Theme
**Light, teal, and the same family across both products.** The apps used
to be a dark violet scheme and the admin panel teal; that split is over.
A brand change now moves one direction, not two.

**Flutter apps — light canvas, deep teal accent:**
- Primary accent: teal-700 `#0F766E`
- Bright accent: teal-500 `#14B8A6`
- Page background: `#F6F7F9` — deliberately not pure white
- Cards / nav bar: `#FFFFFF`
- Border: `#E4E7EC`
- Primary text: `#101828`; muted text: `#5B6475`
- Inactive nav: `#667085`; chevrons and placeholder glyphs: `#98A2B3`
- Accent tile fill `rgba(15,118,110,0.10)`, border `rgba(15,118,110,0.20)`
- Header gradient: `#0E8F83` → `#0F766E` → `#134E4A`
- Card shadow `0 8px 24px rgba(16,24,40,0.08)`; search bar
  `0 12px 30px rgba(16,24,40,0.14)`

There are **two** palette files and they must agree:
`mobile/lib/constants/color_res.dart` (`ColorRes`, used by the auth and
older screens) and `mobile/lib/widgets/base_services.dart`
(`ServiceTokens`, used by every redesigned screen across all three
flavours). `widget_test.dart` asserts both, and asserts they match each
other, so they move together or the suite fails.

**Filament admin — teal.** Verified values from the panel:
- Primary accent: teal-400 `#2dd4bf`
- Surface: slate-900 `#0f172a`
- Page background: slate-950 `#020617`
The admin stays dark; only the accent family is shared. Teal specifically
avoids colliding with Filament's reserved red/amber for
destructive/warning states — don't "simplify" to amber later, it breaks
status legibility. No custom Tailwind build for the admin — Filament's
panel-provider color config is sufficient, no npm build step needed.

Contrast rules that are load-bearing in the light palette, and that
invert what the old dark one required:
- Button labels are **white on teal-700** (5.4:1). Body text is dark ink
  on white, not the other way round — a hardcoded `Colors.white` on a
  card is now invisible, which is the single most common way to break
  this theme.
- `Colors.white` is still correct **inside the header gradient**, the one
  dark surface left. That is the distinction to check before changing any
  white: which surface is it actually sitting on?
- teal-500 is decorative only — 2.3:1 against white both as a fill behind
  white text and as text on a card. Anything that must be read uses
  teal-700.
- Success is `#12B76A`, kept clear of the accent so "approved" does not
  read as just another branded element. Error `#D92D20`, warning
  `#DC6803` — the lighter dark-theme variants fail on white.

## Account approval — the single gate
Admin approval replaced email verification entirely. `users.approval_status`
(`pending` | `approved` | `rejected`, `App\Enums\ApprovalStatus`) is the
only thing deciding whether an account can use the product, and it applies
to **all** self-registered roles — not vendors only, the way the email
check did.

The shape is deliberate and reads backwards at first glance:

- Login **issues a working token** even when the account is unapproved.
  That is required, not an oversight — the app has to sign in far enough
  to show "Your account is in verification" and to offer a way out.
  Withholding the token leaves the user at a login screen with no
  explanation. (Registration issues none since 2026-10-28 — the password
  is set from an emailed link first; see Stack → Email.)
- The restriction lives in `RequireApprovedAccount`, appended to the whole
  `api` group (like `RequirePasswordChange`), not tagged per route. Five
  things stay reachable while unapproved: `POST auth/logout`,
  `GET /user`, `POST broadcasting/auth`, device-token register/delete, and
  `DELETE /user`. The allowlist matches on **method and path** — a bare
  `api/user` entry would silently open `api/user/preferences` too.
- **Realtime is Laravel Reverb** (`composer require laravel/reverb`,
  `php artisan reverb:install`, then keep `php artisan reverb:start`
  running; in production behind nginx on wss/443). Each user has one
  private channel, `App.Models.User.{id}` (`routes/channels.php`),
  authorized at `POST /api/broadcasting/auth` with the Sanctum token
  (registered via `withBroadcasting` in `bootstrap/app.php`). Events are
  `ShouldBroadcastNow` (no queue worker assumed) and dispatched
  `DB::afterCommit` inside `rescue()` — an app that re-reads state on the
  push must see it saved, and Reverb being down must never fail the
  action. Events: `AccountApprovalDecided` → `account.approval`, fired
  from `User::recordApprovalDecision()`; and `VendorDocumentUpdated` →
  `kyc.document` (payload is a hint — the app re-reads its documents),
  fired from `VendorDocument::booted()` on any real status change (not the
  blank pending_upload rows `syncTypes` creates). The apps learn where to
  connect from `GET /api/settings` → `realtime` (null = off; the public
  key/host/port come from `broadcasting.connections.reverb.public`, the
  secret never leaves the server). In Flutter, `utils/socket_service.dart`
  (modelled on the ehhs patient app's SocketService) speaks the Pusher
  protocol over `web_socket_channel`: `on(event, listenerId, cb)` / `off`,
  `eventStream`, `appResumedStream`; it connects at launch when signed in
  and disconnects in `Injector.clearUserData()`. Screens that react to a
  push also re-check on resume, since pushes sent while the app was away
  are lost.
- Error codes are distinct per state: `ACCOUNT_PENDING_APPROVAL` and
  `ACCOUNT_REJECTED` (the latter returns the admin's `approval_note` as the
  message), so the app routes to the right screen instead of guessing from
  a generic 403.
- Order matters: `RequirePasswordChange` runs **before**
  `RequireApprovedAccount`, and the Flutter controllers branch in the same
  order. A user with both problems must be sent to change-password first,
  or they land on a pending screen whose own calls the password gate then
  refuses.

Accounts created by an admin (Filament) are approved at creation — that
person is the vetting — **except vendors**: see "Vendor KYC" below.
`approval_status` is **not** in `User::$fillable`: set it via
`User::recordApprovalDecision()` or an explicit `forceFill`, never through
mass assignment, which drops unfillable keys in silence.

`User` also carries `protected $attributes` defaulting `approval_status` to
pending. The column default alone is not enough — it is applied by the
INSERT and never read back, so the model returned straight out of
`User::create()` would have a null status.

## Vendor KYC documents (decided 2026-10-28)
`App\Services\VendorKycService`. Admin → Document Types (`DocumentType`,
Master Data) lists the documents and ticks which are **compulsory**; each
says whether it needs a number (`mask_number` = Aadhaar: only the last 4
digits are ever collected or stored — UIDAI), an expiry date, a back
photo. Every vendor has one `VendorDocument` per active type (rows synced
up front, so "Pending upload" is a real row); statuses `pending_upload →
uploaded → in_verification → verified`, plus `reupload` (admin's reason,
pushed to the vendor) and `expired` (daily `kyc:expire-documents`).
- **The rule:** a vendor account is approved only when every compulsory,
  active type is Verified. Enforced in `User::recordApprovalDecision()`
  (throws `KycIncompleteException`) so every path — Users list/bulk,
  Verification section, `VendorVerificationService`, CreateUser — obeys
  it; the panel greys Approve out with the reason. Applies to
  self-registered, **salesman-sold** (`VendorDraftService` now creates them
  Pending — replaces SPEC §3.1/§7 "salesman-added skip verification") and
  admin-created vendors (left Pending, redirected to their Documents tab).
  After payment a vendor is `active` only if approved AND KYC complete,
  else `pending_verification` (`SubscriptionService::vendorStatusAfterPayment`).
- Uploads: admin (Documents tab, `VendorDocumentsRelationManager`), salesman
  (`GET/POST vendors/{vendor}/documents`, own vendors only) and vendor
  (`GET/POST vendors/me/documents` — allowlisted in `RequireApprovedAccount`
  so a pending vendor can upload). Admin-only: verify / re-upload request.
- Files on the **private** `filesystems.kyc_disk` (`KYC_FILESYSTEM_DISK`,
  default `local`); only `VendorDocument::fileUrl()` signed 30-minute links
  leave the server. `vendors.shop_photo_path` is NOT KYC — it stays the
  public profile photo. `id_proof_path/id_proof_type` are legacy, copied in
  by `DocumentTypeSeeder` (which also creates the default types: Aadhaar and
  PAN compulsory) and no longer read.
- Types are switched off, never deleted. Permissions ride on
  `vendors.viewAny` / `vendors.verify` (`DocumentTypePolicy`).

## API error log
Super Admin → System settings → Error log (off by default) turns on `LogApiErrors`, a
global middleware that records failed `api/*` calls (5xx only, or 4xx too)
into `api_error_logs` — plus, at either level, any JSON reply whose
envelope says `"success": false` whatever its HTTP status (the apps treat
that as an error, so it must be logged as one), shown on Super Admin → API error log — a page that only
exists while the setting is on. Super-admins only (the old `logs.*`
permissions were removed).
Request data is stored through `LogApiErrors::redact()`, which blanks any
field whose name contains password, token, secret, otp, api_key, card or
cvv; the Authorization header is never stored. If you add a request field
carrying a secret under some other name, add that word to `REDACT`.
Rows are pruned after 30 days (`model:prune`, scheduled daily). Recording
must never break a request — every failure inside it is caught.

## Roles
admin | salesman | vendor | customer — enforced with Laravel Policies +
Sanctum token abilities. No custom guard system. An admin with the
wildcard permission `*` is a super-admin (`User::isSuperAdmin()`); other
admins are sub-admins with ticked `App\Enums\Permission` values, managed in
Super Admin → Admins.

"Admin Settings → Maps / Payments / Sign-in / Password reset" elsewhere in
this file now means **Super Admin → System settings** — those sections
moved there; reads still go through the same `Setting` rows.

## Domain entities
See SPEC.md for full field list: users, vendors, salesmen, customers,
categories, subcategories, zones, plans, plan_quotas, subscriptions,
subscription_items, payments, commissions, leads, reviews, media,
banners, notifications, audit_logs, settings, cms_pages.

## Local environment
XAMPP provides MariaDB 10.4 (used by the app) and Apache/phpMyAdmin (NOT
used to run the app — Apache is unused; `php artisan serve` runs the API
instead). The `php`/`composer`/`artisan` CLI on this machine resolves to
a **separate winget-installed PHP 8.3**, not XAMPP's bundled PHP — two
distinct PHP runtimes exist here. The `intl` extension must be enabled in
BOTH php.ini files (winget's, which is what actually runs the app, and
XAMPP's, kept in sync to avoid surprises if Apache/phpMyAdmin is ever
used) — Laravel's `Number::currency()`/`Number::format()` helpers require
it, and this app formats money constantly given SPEC stores everything
as integer paise. Confirm `intl` is enabled on the production server too
in Phase 8 — that will be a single, normal PHP install with no such split.

Ad-hoc verification zones created via tinker/live-check (not through the
seeder) should use coordinates outside the seeded Ahmedabad range — e.g.
`-33.0, -70.0` — rather than `ZoneFactory`'s default square, which lands
inside real seeded coordinate space and can collide with a genuine
seeded zone (happened in tasks 4.6 and 5.3: a point intended for the
ad-hoc zone matched a real seeded zone instead, since both geometrically
contained it). Not a bug — `ZoneMatcher` picked the correct leaf zone
for the point it was given — just a live-check hygiene rule not worth
re-deriving each time.

Live-check cleanup must force-delete, not soft-delete. A soft-deleted
row still occupies unique indexes (email, phone), so a leftover row
from a prior session's live-check can silently break a later session's
reseed with a duplicate-entry error (happened in task 5.4: task 5.3's
cleanup used `User::where(...)->delete()`, which soft-deletes since
`User` uses `SoftDeletes`, and the trashed row's email blocked task
5.4's reseed). Use `withTrashed()->forceDelete()` for every model in a
live-check cleanup script, not `delete()`.

## Current phase
Phase 0 — foundation setup

## Do not
- Do not add a payment gateway SDK (PHP or Flutter). Keep a single
  `PaymentService` with `payViaCash()` / `payOnline()` / `recordFreeTrial()`.
  `payOnline()` uses Razorpay **Payment Links** over plain HTTPS
  (`RazorpayClient`) when admin Settings → "Online payments via Razorpay"
  is on: the sale is held as `pending_payment` and activated by
  `POST webhooks/razorpay` (`SubscriptionService::completeOnlinePayment()`).
  Off (default) keeps the original placeholder (captured immediately).
  The three credentials can be set in admin Settings → Payments (wins over
  `.env`); read them only through `App\Support\RazorpayCredentials`, never
  `config('services.razorpay.*')`. The key secret and webhook secret are
  stored encrypted and are write-only on the page. The
  apps never take the payment themselves — the link is paid in the browser
  from SMS, which keeps app-store billing rules out of it.
- Do not use OTP/phone auth — email + password only.
- **(Flutter only)** Do not introduce repository interfaces, DTO layers,
  bindings classes, or "for future flexibility" abstractions in the
  mobile app — match the existing demo-app pattern exactly. This does
  NOT apply to the Laravel backend, which is free to use repositories,
  services, or DTOs where useful.
