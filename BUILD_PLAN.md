# Service Marketplace Platform — Build Plan (Laravel + MySQL + GetX)

Stack: Laravel 12 + MySQL 8 (spatial columns for zones), Filament v3 for admin,
Flutter + GetX for the 3 app flavors. Email/password auth via Sanctum.
Manual payment (single API call, gateway-ready). The Flutter app is
deliberately flat — no repository pattern, no interfaces-for-flexibility,
matching the existing demo-app pattern exactly. The Laravel backend is
NOT restricted this way — use standard Laravel patterns (services,
repositories, DTOs) wherever they help. See `CLAUDE.md` below for the
full split.

Work through this one phase at a time, one Claude Code session per numbered task.

---

## 0. Before you write any code

### 0.1 Repo structure

One Laravel app serves both the API (for the 3 Flutter flavors) and the
admin panel (Filament, mounted inside the same app). One Flutter app with
3 flavors. Two codebases total.

```
service-marketplace/
├── CLAUDE.md
├── SPEC.md
├── PROGRESS.md
├── backend/                      # Laravel (API + Filament admin)
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/  # AuthController, VendorController,
│   │   │   │                     # SubscriptionController, LeadController,
│   │   │   │                     # ReviewController, ZoneController, ...
│   │   │   └── Requests/         # Form Request validation classes
│   │   ├── Models/                # Eloquent models, thin, with scopes
│   │   ├── Services/              # ONLY multi-step transactional logic:
│   │   │                          # SubscriptionService, LeadService,
│   │   │                          # ZoneMatcher
│   │   ├── Filament/Resources/    # admin CRUD screens (auto-generated)
│   │   ├── Notifications/         # push + email
│   │   └── Console/Commands/      # expiry cron, commission calc
│   ├── database/migrations/
│   └── routes/api.php
└── mobile/                        # Flutter, GetX, 3 flavors
    └── lib/
        ├── constants/
        │   ├── app.export.dart     # barrel file — exports colors, strings,
        │   │                       # utils, injector, base widgets, models
        │   │                       # so feature files need 1–2 imports
        │   ├── constant.dart
        │   ├── color_res.dart
        │   ├── string_res.dart
        │   └── pref_keys.dart
        ├── common_model/           # shared models: user_model.dart,
        │   │                       # common_response.dart, etc.
        │   └── ...
        ├── network/
        │   └── data_source.dart    # DataSource.instance — one named
        │                           # method per API endpoint
        ├── utils/
        │   ├── injector.dart       # static: prefs, accessToken, user data
        │   └── utils.dart          # static: loaders, navigation, layouts
        ├── widgets/                # Base* shared widgets: BaseTextField,
        │   │                       # BaseRaisedButton, BaseTextDMSans, ...
        │   └── ...
        └── screens/
            ├── auth_flow/
            │   └── create_account_module/
            │       ├── create_account_view.dart
            │       └── create_account_controller.dart
            ├── salesman_flow/
            │   ├── add_vendor_module/{view, controller}.dart
            │   ├── my_vendors_module/{view, controller}.dart
            │   └── earnings_module/{view, controller}.dart
            ├── vendor_flow/
            │   ├── vendor_dashboard_module/{view, controller}.dart
            │   ├── services_module/{view, controller}.dart
            │   ├── leads_module/{view, controller}.dart
            │   └── reviews_module/{view, controller}.dart
            └── customer_flow/
                ├── customer_bottom_navigation_screen_module/...
                ├── browse_module/{view, controller}.dart
                ├── vendor_detail_module/{view, controller}.dart
                └── review_module/{view, controller}.dart
```

**This structure is taken directly from your existing `demo-app`, not a
generic GetX template.** Every module is a `<name>_module/` folder holding
exactly two files:

- `<name>_controller.dart` — extends `GetxController`. Plain fields (not
  `.obs`), a `GlobalKey<FormState>` where a form is involved, one API
  method per screen action, calls `update()` after any state change.
- `<name>_view.dart` — a `StatelessWidget` whose `build()` returns
  `GetBuilder<XController>(init: XController(), dispose: (_) =>
  Get.delete<XController>(), builder: (_) => Scaffold(...))`. The
  controller is instantiated right there in the view — no bindings class,
  no route-level dependency injection.

No `.obs`/reactive streams anywhere. No repository/use-case layers between
the controller and `DataSource`. Navigation is direct widget pushes via
`Utils.transitionWithOffAll(NextPage())`, not a named-route table. This is
intentionally coupled and simple — match it exactly rather than
"improving" it with a more layered pattern.

### 0.2 Write `CLAUDE.md` first

```markdown
# Project: [Your Platform Name]

## What this is
A service marketplace connecting vendors (AC repair, plumbing, electrical,
etc.) to customers, sold primarily through a salesman-led channel, with
vendor self-signup as a secondary path. Admin manages master data and
approvals.

## Stack
- Backend: Laravel 12, PHP 8.3, MySQL 8 (spatial columns for zones)
- Admin: Filament v3, dark mode on, primary color set in the panel
  provider — no separate frontend app
- Mobile: Flutter 3.x, GetX — but specifically the pattern in
  `mobile/lib/screens/**`, not generic GetX. See "Flutter conventions"
  below before writing any screen.
- Auth: Laravel Sanctum, one personal access token per device
- File storage: Laravel Filesystem, S3 driver → Cloudflare R2
- Queue: Laravel Queue (database driver is fine at this scale) +
  Laravel Scheduler for the daily expiry job
- Push: Firebase Cloud Messaging via HTTP v1 API
- Email: Laravel Mail + Resend

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

## Conventions — API (Laravel)
- API response envelope: { success, data, error: { code, message } }
- Pagination via Laravel's built-in paginator on every list endpoint
- Money stored as integer paise, never float
- Dates in UTC, ISO 8601
- Server always recalculates price, quota usage, and expiry dates —
  never trusts client-sent values
- Subscription-creating endpoints require an `Idempotency-Key` header,
  enforced with a unique column
- Soft deletes (`SoftDeletes` trait) on: users, vendors, subscriptions

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
- **View** (`<name>_view.dart`): a `StatelessWidget`. `build()` returns
  `GetBuilder<XController>(init: XController(), dispose: (_) =>
  Get.delete<XController>(), builder: (_) => Scaffold(...))`. Read/write
  controller state as `_.fieldName` inside the builder. Use the shared
  `Base*` widgets (`BaseTextField`, `BaseRaisedButton`, `BaseTextDMSans`,
  ...) instead of raw Material widgets wherever one exists.
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

## Roles
admin | salesman | vendor | customer — enforced with Laravel Policies +
Sanctum token abilities. No custom guard system.

## Domain entities
See SPEC.md for full field list: users, vendors, salesmen, customers,
categories, subcategories, zones, plans, plan_quotas, subscriptions,
subscription_items, payments, commissions, leads, reviews, media,
banners, notifications, audit_logs.

## Current phase
[update this line every session]

## Do not
- Do not add a payment gateway SDK yet. Keep a single `PaymentService`
  with `payViaCash()` / `payOnline()` methods — real gateway wiring later
  changes the internals, not the callers.
- Do not use OTP/phone auth — email + password only.
- **(Flutter only)** Do not introduce repository interfaces, DTO layers,
  bindings classes, or "for future flexibility" abstractions in the
  mobile app — match the existing demo-app pattern exactly. This does
  NOT apply to the Laravel backend, which is free to use repositories,
  services, or DTOs where useful.
```

### 0.3 Write `SPEC.md`

Paste the full corrected flow (salesman / vendor / customer / admin) into
`SPEC.md`. Claude Code reads this before building any feature — it's the
source of truth for quota enforcement, lead-gated reviews, grace period
rules, etc.

### 0.4 Local environment (XAMPP)

No Docker needed — XAMPP already provides MySQL/MariaDB, PHP, and phpMyAdmin.

1. Start **MySQL** from the XAMPP Control Panel (Apache is not required —
   Laravel serves itself via `php artisan serve`).
2. Open `http://localhost/phpmyadmin`, create a database named
   `marketplace`.
3. Confirm versions before Phase 1: `php -v` must be **8.2+**;
   `mysql -u root -V` (or the phpMyAdmin footer) must be **MySQL 8+ or
   MariaDB 10.2+** — both are required for the `ST_Contains` spatial
   queries used in zone matching.
4. `backend/.env`:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=marketplace
   DB_USERNAME=root
   DB_PASSWORD=            # XAMPP default is blank unless you've set one
   ```
5. Run the app locally with `php artisan serve` from `backend/`, not
   through XAMPP's Apache.

Also install **Composer** separately (XAMPP doesn't bundle it) —
required before task 0.1.

---

## Phase 0 — Foundation (Week 1)

| # | Task | Claude Code session prompt |
|---|---|---|
| 0.1 | Init Laravel app | "Read CLAUDE.md. Initialize a Laravel 11 app in backend/ with the folder structure and architecture rules from CLAUDE.md. Set up the MySQL connection to point at the local XAMPP MySQL instance (127.0.0.1:3306, database `marketplace`). Install Sanctum." *(Historical — this task actually resulted in Laravel 12 due to unpatched Laravel 11 security advisories at the time; CLAUDE.md was corrected to reflect this. Left as originally written rather than rewritten after the fact.)* |
| 0.2 | Auth (Sanctum) | "Build auth: register/login/logout with email+password, bcrypt hashing, Sanctum token issuance per device, a simple RolesMiddleware reading a `role` column on users. Add rate limiting on login (5 attempts / 15 min) via Laravel's built-in throttle middleware." |
| 0.3 | Password reset + email | "Add Laravel's built-in password reset flow wired to Resend, plus email verification for self-registered users." |
| 0.4 | Filament install | "Install Filament v3, create the admin panel with dark mode default and the theme color from CLAUDE.md. Build the admin login screen restricted to users with role=admin." |
| 0.5a | Port shared foundation | "I'm uploading create_account_view.dart and create_account_controller.dart from an existing app (demo-app) as the reference pattern. Read the 'Flutter conventions' section in CLAUDE.md. Recreate the shared foundation these files depend on: constants/app.export.dart (barrel export), constants/color_res.dart, constants/string_res.dart, constants/pref_keys.dart, network/data_source.dart (DataSource singleton + CommonResponse model), utils/injector.dart, utils/utils.dart (showCircularProgressLottie, transitionWithOffAll, authLayout), and the Base* widgets (BaseTextField, BaseRaisedButton, BaseTextDMSans) — matching their method signatures and usage as shown in the two sample files, styled with the dark theme from CLAUDE.md." |
| 0.5b | Flutter flavors on top | "Initialize 3 build flavors (salesman, vendor, customer) with main_salesman.dart / main_vendor.dart / main_customer.dart entry points, each a GetMaterialApp pointing to that flavor's first screen. Add easy_localization setup. Confirm the ported create_account_module (view + controller) compiles and runs under each flavor as a smoke test." |

**Checkpoint:** admin panel loads with dark mode; the ported `create_account_module` runs under all 3 flavors, proving the shared foundation (DataSource, Injector, Utils, Base widgets) works before any new screens are built on top of it.

---

## Phase 1 — Master Data (Week 2)

| # | Task | Prompt |
|---|---|---|
| 1.1 | Full schema (migrations) | "Read SPEC.md. Write Laravel migrations for every entity in CLAUDE.md. plan_quotas as its own table (plan_id, max_categories, max_subcategories, max_zones, max_photos, max_videos, priority_rank). Zones table: `polygon` column as MySQL `POLYGON` type with SRID 4326 and a `SPATIAL INDEX`, plus a `pincode` column." |
| 1.2 | Category management | "Build category & subcategory CRUD as a Filament Resource with a relation manager for subcategories, drag-to-reorder, active toggle. No separate API controller needed here — Filament handles the admin side; add a public GET /api/categories for the apps." |
| 1.3 | Zone management | "Build the Zone Filament Resource with a custom form field for drawing a polygon (Leaflet + Leaflet.draw embedded via a Filament custom field), saved through a raw insert helper since Eloquent doesn't cast spatial types. A static helper on the Zone model is enough for this one case; use a repository only if the spatial logic grows more complex." |
| 1.4 | Plan management | "Build the Plan Filament Resource with quota fields and a nested quota sub-form. Show a plan-card preview of the quota summary in the resource's table view." |
| 1.5 | Seed script | "Write a Laravel seeder: 10 categories, ~40 subcategories, Ahmedabad as main zone with 15 sub-zone polygons (approximate real coordinates), 3 plans (Silver/Gold/Platinum) with distinct quotas, one admin user." |

**Checkpoint:** `php artisan migrate:fresh --seed` gives a fully browsable admin with real data.

---

## Phase 2 — User Management & Roles (Week 3)

| # | Task | Prompt |
|---|---|---|
| 2.1 | User management (Filament) | "Build a User Filament Resource with role-based forms: creating a salesman generates a temp password shown once to the admin; creating/editing vendors and customers reuses the same resource with conditional fields by role." |
| 2.2 | Audit log | "Add an audit_logs table (actor_id, action, entity_type, entity_id, before, after, created_at). Log writes via a simple Eloquent `saving`/`saved` model event on the models that need it — not a global interceptor abstraction." |
| 2.3 | Admin sub-roles | "Add a `permissions` json column on users for sub-admins (e.g. can moderate reviews but not edit plans). Enforce with Laravel Policies checked in each Filament Resource's `canEdit`/`canDelete`." |

**Checkpoint:** admin creates a salesman account and can see the generated password once.

---

## Phase 3 — Salesman Flow (Weeks 4–5)

| # | Task | Prompt |
|---|---|---|
| 3.1 | Salesman login (app) | "Build the salesman flavor login screen with GetX — email/password only, no registration. Force a password-change screen on first login (`must_change_password` flag on user)." |
| 3.2 | Add-vendor: draft + duplicate check | "Build POST /api/vendors/draft — validates via a Form Request, checks email+phone uniqueness first, creates the vendor in Draft status, returns its ID so the app can resume. Build the GetX add-vendor controller/view, step 1 (business info + KYC upload to R2 via a presigned URL endpoint)." |
| 3.3 | Plan/quota selection UI | "Build step 2 of add-vendor: plan selection, then category/subcategory/zone multi-select with a live 'X of Y selected' counter driven by the plan's quota. This is UX only — server is the real enforcer." |
| 3.4 | Subscription endpoint | "A minimal Subscription model already exists (created in task 1.4 as a placeholder for Plan's in-use badge — fillable, casts, plan(), scopeActive(), SoftDeletes only). Extend it, don't recreate it. Apply the audit logging pattern from task 2.2 to Subscription, Payment, and Commission — SPEC §5.14 specifically calls out salesman-granted free subscriptions as the highest-scrutiny case. Build POST /api/subscriptions per SPEC.md, inside a `SubscriptionService::subscribe()` method: server computes price and end_date from the plan, validates quota counts, requires an Idempotency-Key header (unique column check), wraps subscription + subscription_items + payment + commission + vendor status update in one DB::transaction(). payment_mode: cash/online/free. Salesman-added vendors go straight to Active." |
| 3.5 | Free trial rules | "Already fully built as part of task 3.4 — StoreSubscriptionRequest enforces the max-days cap, per-salesman-month limit, and one-per-phone-ever check. Nothing left to do." |
| 3.6 | Credential handoff | "Backend done in task 3.4 — subscribe() already returns the temp password once. Remaining: Flutter only — after a successful subscribe, show a 'Share via WhatsApp' screen with the credentials pre-filled in a share intent." |
| 3.6b | Join as Free UI | "Backend free-trial logic is fully built (3.4/3.5 — max days cap, per-salesman-month limit, one-per-phone-ever, all enforced server-side). No Flutter UI exists yet. Add a 'Join as Free' option alongside Cash/Online in the payment-mode step: a duration picker capped by the live free_trial_max_days Setting (fetch it, don't hardcode 15), calling the same subscribeAPI() with payment_mode=free and free_trial_days. Reuse the confirmation screen from 3.6 — the flow converges after subscribe succeeds regardless of payment mode." |
| 3.7 | My Vendors + Earnings tabs | "Build GetX controllers/views for My Vendors (vendor, plan, days to expiry, leads this month) and Earnings (pending/paid commission, monthly target), backed by simple GET endpoints." |

**Checkpoint:** salesman adds a vendor end to end; kill the app mid-flow and confirm the draft resumes.

---

## Phase 4 — Vendor Flow (Weeks 6–7)

| # | Task | Prompt |
|---|---|---|
| 4.1 | Vendor login/register | "Build the vendor flavor: login and self-registration (email/password + verification email) using GetX controllers. IMPORTANT — task 3.4 found that self-registration currently only creates a User row, not a Vendor profile row, so self-service subscribe has nothing to attach to. This task must create a minimal Vendor profile at registration time (or immediately before first subscribe attempt) before task 4.2 can work. On login, GET /api/vendors/me checks for an existing active subscription." |
| 4.1b | User Management vendor-creation gap | "Task 4.1 found that GET /api/vendors/me must 404-guard against a vendor-role User with no paired Vendor row — plausible because task 2.1's UserResource (built before VendorDraftService/business_name+phone existed) almost certainly lets an admin create role=vendor directly with no Vendor row created alongside it, same bug self-registration just had. Confirm whether this is real, and if so fix it: either UserResource creates a paired minimal Vendor row when role=vendor is selected (mirroring AuthController::register()'s new transaction-wrapped approach), or UserResource is restricted from creating vendor-role users at all, forcing admin through the vendor-draft flow instead. Pick whichever fits the existing UserResource form better." |
| 4.2 | Dashboard branch logic | "If an active subscription exists, skip plan selection and show the dashboard (plan, quota used/total, leads, rating, days remaining). If none, show plan selection through the same subscription endpoint with mode=online, status starting Pending Verification." |
| 4.3 | Vendor verification (Filament) | "Build a Filament Resource action: Vendors filtered to Pending Verification, showing KYC docs, with Approve/Reject buttons. Approve sets status Active and dispatches a push notification job." |
| 4.4 | Services & quota | "Build the vendor Services screen: select categories/subcategories within remaining quota, enforced server-side on every request via a scope like `Vendor::remainingQuota('categories')`." |
| 4.5 | Portfolio media | "Build photo/video upload to R2 with client-side compression before upload, quota-enforced count, `status: pending` on each item. Add a Filament Media Moderation Resource (grid view, approve/reject)." |
| 4.6 | Zones | "Build vendor zone selection reusing the public zones list, quota-capped, with a simple map preview." |
| 4.7 | Upgrade/downgrade/add-on | "Add PATCH /api/subscriptions/{id}/upgrade (pro-rates unused days against the new plan), block downgrade if current usage exceeds new quota, and a separate add-on endpoint that raises quota without changing the base plan. All inside SubscriptionService, not new service classes." |
| 4.8 | Leads + Reviews tabs | "Build the vendor Leads tab (GET /api/vendors/me/leads) and Reviews tab with a 'Request review' action scoped to one lead at a time." |

**Checkpoint:** a self-registered vendor cannot appear in customer search until admin approves them.

---

## Phase 5 — Customer Flow (Weeks 8–9)

| # | Task | Prompt |
|---|---|---|
| 5.1 | Customer login/register | "Build the customer flavor: register/login, location permission request, header showing detected location with change-location screen. Fall back to pincode entry if GPS is denied or no zone matches." |
| 5.2 | Category browse | "Build home screen category grid → subcategory list, GetX-controlled." |
| 5.3 | Vendor matching & sort | "Build GET /api/vendors/search?subcategory_id&lat&lng inside `ZoneMatcher::vendorsFor()` — uses `ST_Contains(polygon, POINT(lng, lat))` via whereRaw, falls back to pincode match, filters active status and unexpired subscription, sorts by plan priority_rank, then rating (min review threshold), then recency." |
| 5.4 | Vendor detail + lead capture | "Build the vendor detail screen (services, photos, videos, reviews, distance) with Call and WhatsApp buttons. Both call POST /api/leads first (customer, vendor, subcategory, zone, timestamp) before opening the dialer/WhatsApp intent." |
| 5.5 | Reviews | "Build POST /api/reviews — reject unless a lead exists between this customer and vendor within 30 days, one review per lead, 24-hour edit window. Add vendor reply and admin hide via Filament." |
| 5.6 | Extras | "Add search, favorites, share vendor profile, report vendor, and account deletion (required for store compliance). The email tombstone rename mechanism should already exist from task 2.1's User Resource — reuse it, just expose it via a customer-facing self-service delete flow rather than rebuilding the rename logic." |

**Checkpoint:** full customer journey — browse, call, review request, review posted — works against seeded data.

---

## Phase 6 — Remaining Admin Modules (Week 10)

| # | Task | Prompt |
|---|---|---|
| 6.1 | Dashboard | "Build a Filament dashboard page with widgets: total counts, subscriptions expiring in 30 days, revenue this month, pending verification count, leads this week — real queries." |
| 6.2 | Leads & analytics | "Build a Filament page: leads filterable by vendor/category/zone/date range with a leads-over-time chart widget." |
| 6.3 | Commission & payouts | "Build a Commission Filament Resource: pending/paid per salesman, mark-as-paid action, cash-reconciliation view (payments with mode=cash and admin_verified_at null)." |
| 6.4 | Banner management | "Build a Banner Filament Resource: target app, position, image, start/end date, click counter." |
| 6.5 | Review management | "Add a filter to the Review Resource for reviews with no matching lead or a lead older than 30 days (fraud signal)." |
| 6.6 | CMS pages | "The cms_pages table already exists (added in Phase 1). Build a CMS Filament Resource for it — Terms/Privacy/Refund/FAQ as markdown pages served at public routes." |
| 6.7 | Settings | "The settings table already exists (added in Phase 1, used since Phase 3). Build a single Filament settings page backed by it: force-update version, maintenance mode, grace period days, max free-trial days, max free grants per salesman/month." |

---

## Phase 7 — Notifications & Background Jobs (Week 11)

| # | Task | Prompt |
|---|---|---|
| 7.1 | Expiry job | "Add an Artisan command `subscriptions:process-expiry` run daily via the Scheduler: Active → Grace at end_date, Grace → Expired after the settings grace period, removing expired vendors from search." |
| 7.2 | Push notifications | "Integrate FCM via HTTP v1. Add Laravel Notifications for: expiry reminders (T-15/T-7/T-1), verification approved, review request, lead received." |
| 7.3 | Notification admin | "Add a Filament page to compose a push: target flavor, audience filter, send now." |

---

## Phase 8 — Polish, Testing, Deployment (Weeks 12–13)

| # | Task | Prompt |
|---|---|---|
| 8.1 | Design pass | "Read PROGRESS.md's 'Before Launch Checklist' section first and address every item on it. Then audit every screen in Filament and all 3 app flavors against the dark theme tokens in CLAUDE.md. Fix contrast against WCAG AA, inconsistent spacing, hardcoded colors." |
| 8.2 | E2E test pass | "Write Laravel feature tests for: salesman adds vendor → vendor sees active plan on login; customer search → call → lead recorded → review request → review posted; expiry command moves a vendor through Grace to Expired and it disappears from search." |
| 8.3 | Load check | "Seed 5,000 vendors across zones. Confirm the spatial search query stays fast with the SPATIAL INDEX in place — check the query plan with EXPLAIN." |
| 8.4 | Store prep | "Confirm privacy policy, terms, and account deletion are reachable from both apps. Set up Play Store internal testing for the salesman flavor (never public) and public listings for vendor/customer." |
| 8.5 | Deploy | "Deploy Laravel (API + Filament admin, one app) to a VPS via Laravel Forge or plain Docker, MySQL 8 managed or on the same VPS, queue worker + scheduler running via Supervisor. Confirm the `intl` PHP extension is enabled on the server before going live — Number::currency()/format() calls will throw without it." |

---

## Phase 9 — Deferred (post-launch)

- Razorpay integration inside the existing `PaymentService` — add a `payOnline()` implementation that talks to the gateway, callers don't change
- Customer↔vendor in-app chat (Laravel Reverb, if it's ever needed)
- Salesman live location tracking
- Vendor online/offline toggle

---

## Timeline summary

| Phase | Weeks | Focus |
|---|---|---|
| 0 | 1 | Laravel skeleton, Sanctum auth, Filament install |
| 1 | 2 | Categories, zones, plans, seed data |
| 2 | 3 | User management, audit log |
| 3 | 4–5 | Salesman vendor-onboarding flow |
| 4 | 6–7 | Vendor self-service flow |
| 5 | 8–9 | Customer search & lead flow |
| 6 | 10 | Remaining admin modules |
| 7 | 11 | Notifications, expiry job |
| 8 | 12–13 | Polish, testing, deployment |

~13 weeks solo, one codebase for backend+admin and one for the 3 app flavors — fewer moving parts than the earlier TypeScript version, which is the point of this stack swap.

---

## How to run each session

1. Paste the task's prompt into Claude Code.
2. For anything touching more than 2 files, ask for a plan first — read it, correct it, approve it. Repositories/DTOs/interfaces are fine on the backend now — no need to challenge Claude Code for using them in Laravel.
2a. For every new Flutter screen, check the diff has exactly a `<name>_view.dart` + `<name>_controller.dart` pair, `GetBuilder` (not `Obx`), plain fields with `update()` (not `.obs`), and calls into the existing `DataSource`/`Injector`/`Utils`/`Base*` widgets rather than new equivalents. Claude Code will drift toward "cleaner" reactive GetX or a bindings layer if not checked against the ported sample.
3. Update the "Current phase" line in `CLAUDE.md` and add a line to `PROGRESS.md` after each session.
4. Commit per task, minimum.
5. Start a fresh session per task where practical.
