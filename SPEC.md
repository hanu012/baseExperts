# SPEC — Service Marketplace Platform

Corrected business flow. This is the source of truth for business rules —
read this before implementing any feature that touches subscriptions,
verification, matching, or reviews.

---

## 1. Roles

`admin` | `salesman` | `vendor` | `customer`. All four log in with
email + password (no OTP). Admin creates salesman accounts directly — no
salesman self-registration. Vendor and customer can self-register or be
created by admin/salesman.

---

## 2. Salesman app flow

1. Login only — email/password created by admin, forced password change
   on first login. **This rule is platform-wide, not salesman-specific**
   — `UserResource` (§5.2) issues an admin-chosen temp password when
   creating any account (admin, salesman, vendor, or customer), so
   `must_change_password` lives on `users` and is enforced by
   server-side middleware on every authenticated route except
   change-password/logout, not a client-side redirect alone. A
   client-only check has no real effect, since the login response
   already carries a fully valid token regardless.
2. **Add Vendor**:
   - Enter business name, owner name, phone (required — used for the
     customer Call button), email (required — becomes vendor's login),
     address, GPS location.
   - Duplicate check on both email and phone before proceeding. Reject if
     either exists.
   - Save as `Draft` status immediately, before payment — salesmen work in
     the field on unreliable networks and must be able to resume.
   - Upload KYC: shop photo, owner Aadhaar/PAN.
   - Select a subscription plan → select categories → subcategories →
     zones, all capped by the plan's quota, with a live "X of Y selected"
     counter. Multiple category/subcategory/zone selection supported.
   - Review screen shows plan name, price, validity, and the services/
     subservices included.
   - Tap **Subscribe**: choose payment mode (Cash / Online / Cheque) →
     confirmation dialog → single API call (see Section 6). Vendor becomes
     **Active** immediately — salesman-added vendors skip KYC review since
     the salesman met them in person.
   - System generates a temp password for the vendor's login; salesman
     shares it via a WhatsApp share action.
   - A commission record is created as `pending`.
   - Alternative: **Join as Free** — same flow, but `mode = free`,
     `amount = 0`. Salesman selects duration, capped by an admin Settings
     value (default 15 days). Capped number of free grants per salesman
     per month. One free trial per phone number, ever. Same eligibility as
     a paid vendor. Expires the same way (Grace → Expired).
3. **My Vendors tab**: vendor name, plan, days to expiry, leads received
   this month.
4. **Earnings tab**: commission pending/paid, monthly target vs achieved,
   cash collected but not yet reconciled by admin.
5. **Profile tab**: own profile, change password.

---

## 3. Vendor app flow

1. Login + self-registration (email/password + email verification).
   Admin/salesman can also create the account.
2. On login, check for an existing active subscription linked to the
   account:
   - **Exists** (salesman-created) → skip plan selection entirely, land
     directly on the dashboard: plan name, quota used/total per resource,
     leads received, rating, days remaining.
   - **Doesn't exist** → show plan selection. Vendor subscribes via the
     same single-call endpoint with `mode = online`. Status starts as
     **Pending Verification**, not Active — self-registered vendors must
     be approved by admin (KYC check) before they're visible to customers.
3. **Services**: select categories/subcategories within remaining quota,
   enforced server-side.
4. **Zones**: navigate via main zone (e.g. Ahmedabad), then select
   specific leaf sub-zones (Gota, Ranip, Sola, Paldi, Ambawadi) — this is
   the actual availability declaration. Main zones are navigation only,
   never directly selectable as a coverage unit; only leaf-zone
   selections count against plan quota (see §8 for why).
5. **Portfolio**: under each subcategory, upload photos/videos of
   completed work. Quota-capped by plan, compressed client-side before
   upload, routed through an admin moderation queue before going live.
6. **Upgrade / downgrade / add-on**:
   - Upgrade mid-cycle credits unused days of the current plan against the
     new one.
   - Downgrade is blocked until the vendor deselects categories/zones down
     to the new plan's limit.
   - Add-ons (e.g. "+2 categories") increase quota without changing the
     base plan.
   - All routed through the same subscription endpoint / service method.
7. **Leads tab**: every customer who tapped Call, with date and service
   requested.
8. **Reviews tab**: view reviews, reply to them, and send a review request
   — scoped to a specific lead only, not to an arbitrary customer.
9. **My Subscription tab**: plan details, expiry, renewal.
10. **Profile tab**: manage profile.

---

## 4. Customer app flow

1. Login + self-registration (email/password). Admin can also create.
2. Header shows current location with a change-location option. Falls
   back to pincode entry if GPS is denied or the location matches no
   defined zone ("We're not in your area yet" + capture pincode for
   expansion planning).
3. Browse: main categories → subcategories.
4. **Vendor matching happens at the subcategory level, not category
   level** — a vendor who does AC gas filling may not do AC installation.
   Match query: `subcategory = X AND zone contains customer_location AND
   vendor.status = active AND subscription.end_date >= today`.
5. **Sort order**: plan priority tier first, then rating (with a minimum
   review-count threshold so one 5-star doesn't outrank a 4.6 with 80
   reviews), then recency.
6. Vendor detail page: services offered, reviews, portfolio photos/
   videos, distance, Call button, WhatsApp button.
7. **On Call or WhatsApp tap: write a lead record first** (customer_id,
   vendor_id, subcategory_id, zone_id, timestamp), *then* open the
   dialer/WhatsApp intent. This is required — it is what makes reviews,
   renewal evidence, and analytics possible.
8. Payment for the actual service happens offline between customer and
   vendor — out of scope for this platform.
9. **Reviews**: a review is only permitted if a lead record exists between
   this customer and this vendor within the last 30 days. One review per
   lead. Editable for 24 hours, then locked. Vendor gets a right of reply.
   Admin can hide.
10. Extras: search, favorites, share vendor profile, report vendor,
    account deletion (required for app store compliance).

---

## 5. Admin panel modules

1. **Dashboard** — total vendors/salesmen/customers/services/subservices,
   subscriptions expiring in the next 30 days, revenue this month,
   pending verification count, leads generated this week.
2. **User Management** — CRUD for all 4 roles. Creating a salesman
   generates a temp password shown once. Vendor rows have a Verify/Reject
   action.
3. **Location & Zone Management** — main zones and sub-zones via polygon
   drawing (e.g. Ahmedabad containing Sola, Ambawadi, Gota, Sarkhej,
   Thaltej, Adalaj). Store pincode alongside the polygon as a fallback.
4. **Subscription Management** — plans with quota fields: max categories,
   max subcategories, max zones, max photos, max videos, priority rank,
   duration, price.
5. **Banner Management** — target app (salesman/vendor/customer),
   position, image, start/end date, click tracking.
6. **Review Management** — hide/unhide reviews, vendor replies, a filter
   for reviews with no matching lead or a lead older than 30 days (fraud
   signal).
7. **Category & Subcategory Management** — the master table everything
   else depends on. Name, icon, sort order, active flag, parent category.
8. **Vendor Verification Queue** — pending self-registered vendors, KYC
   docs, Approve/Reject with reason.
9. **Salesman Commission & Payouts** — commission rate per plan, earned/
   pending/paid, targets, cash-collection reconciliation (payments with
   mode=cash and admin_verified_at null).
10. **Media Moderation Queue** — approve/reject vendor portfolio uploads.
11. **Leads & Call Analytics** — leads per vendor/category/zone, filterable
    by date range.
12. **Notification Management** — push composer (target flavor, audience
    filter) plus automated triggers: expiry reminders (T-15/T-7/T-1),
    verification approved, review request, lead received. The
    `notifications` table is a dispatch/campaign log (one row per
    composed or triggered push: title, body, target_app, audience_filter,
    scheduled_at, sent_at, sent_count) — not Laravel's per-user inbox
    shape. A separate per-user read-state table can be added later if an
    in-app inbox is needed; it's a different concept from this one.
13. **CMS Pages** — Terms, Privacy Policy, Refund Policy, FAQ, About —
    required for app store listing.
14. **Audit Log** — who changed what, when. Especially important since
    salesmen can grant free subscriptions.
15. **Support Tickets** — vendor complaints, customer reports of fake
    vendors.
16. **Admin Roles & Permissions** — sub-admins scoped to specific modules
    (e.g. can moderate reviews but not touch plans/payments).
    **Escalation guard, required**: only a super-admin may modify any
    admin account's permissions — including their own. Without this,
    every other scoped permission is advisory: a sub-admin with
    `users.update` could grant themselves broader access, mint a second
    super-admin, or demote/delete the one that scoped them. The
    permissions field must be hidden (not merely disabled) on the form
    for anyone who isn't a super-admin, since a disabled field still
    round-trips through the request and a crafted submission could set
    it regardless of what the UI shows.
17. **Settings** — force-update version, maintenance mode, grace period
    days, max free-trial days, max free grants per salesman/month.

---

## 6. Subscription & payment — single API call design

One endpoint handles subscribe, free-trial, upgrade, and add-on:

```
POST /api/subscriptions
Headers: Idempotency-Key: <uuid>
Body: {
  vendorId, planId,
  categoryIds: [], subcategoryIds: [], zoneIds: [],
  paymentMode: "cash" | "online" | "free",
  freeTrialDays: 15   // only when paymentMode = free
}
```

Rules:
1. **Server computes price and dates.** Never trust client-sent amount or
   end_date — read the plan, compute `end_date = now + plan.duration_days`.
2. **Server validates quotas.** If the plan allows 5 categories and the
   request has 6, reject with 422.
3. **Idempotency key required**, enforced via a unique column — prevents
   double-tap on bad networks from creating duplicate subscriptions and
   commissions.
4. **Everything in one DB transaction**: subscription, subscription_items,
   zones, payment record, commission record, vendor status change. Any
   failure rolls all of it back.
5. **A real payment row, not a boolean**:
   ```
   payments: id, subscription_id, amount, currency, mode (cash|online|free),
   gateway ("manual" for now), gateway_order_id, gateway_payment_id (null
   for now), status, collected_by_salesman_id, admin_verified_at
   ```
   This schema stays unchanged when a real payment gateway is added later
   — only the `PaymentService` internals change.

---

## 7. Vendor lifecycle state machine

`Draft` → `Pending Payment` → `Pending Verification` (self-registered only;
salesman-added skips this) → `Active` → `Grace` (post-expiry renewal
window) → `Expired` (removed from customer search, data preserved,
renewal restores to Active). `Suspended` is a separate admin flag,
independent of subscription dates, for policy violations.

---

## 8. Zone matching

Zones stored as MySQL/MariaDB `POLYGON` columns (SRID 4326) with a
`SPATIAL INDEX`, plus a `pincode` fallback column. Matching uses
`ST_Contains(zone.polygon, POINT(lng, lat))`. Fall back to pincode match
when GPS is unavailable or denied.

**Leaf-only matching, decided explicitly**: a zone is a "leaf" if no
other zone references it as a parent — i.e. it has no children,
regardless of whether it has a parent itself. A standalone top-level
zone with no sub-zones (e.g. a newly added city, not yet subdivided) is
a leaf and is matchable; a mid-tier zone that has its own children is
not, even though it has a parent. Only leaf zones participate in
matching or are selectable by vendors as a coverage unit. Parent zones
(anything with children) exist for navigation/grouping only — their
polygon is required (§11) but never matched against directly. This was
ambiguous between §3.4 and §5.3 until resolved here: matching a parent
zone would let one selection substitute for its children (breaking
quota fairness) and would return duplicate vendor matches (a point
inside a child is also inside its parent). Neither is acceptable, so
parents are structurally excluded.

**Effective active status**: a leaf zone is matchable only if
`zone.is_active AND (zone.parent_id IS NULL OR parent.is_active)` —
computed at match time, never physically cascaded to child rows. This
lets a new sub-zone be created and individually activated while its
parent city is still in draft (§11) without being blocked, but it won't
actually be matchable until the parent also goes active. Deactivating a
busy parent zone silently drops every descendant out of matching, so the
admin "in use" indicator on a parent zone must count subscriptions
referencing any descendant transitively, not just the parent's own row.

**Max hierarchy depth: 2 levels (city → sub-zone), enforced at
creation.** Nothing in this platform's design needs a third tier —
there's no state/region grouping anywhere in the vendor, salesman, or
customer flows. This is a deliberate constraint, not a temporary
limitation: at exactly two levels, "effective active checks one level
up" and "in use counts every descendant transitively" are the same
statement, so they can't diverge. A third level would silently break
that equivalence (a grandparent's deactivation would count in the badge
without actually affecting matching) — ruling it out removes the bug
class instead of managing around it. Enforce by rejecting any zone
creation where the selected parent already has a parent of its own.

---

## 9. Review rules

A review requires a matching lead record between that customer and vendor,
created within the last 30 days. One review per lead. Without this rule,
reviews can be fabricated with no proof of contact.

**Lead data-integrity note (decided in task 5.4):** `POST /api/leads`
validates that `vendor_id`/`subcategory_id`/`zone_id` are real rows, but
does NOT verify the vendor's active subscription actually covers that
subcategory in that zone at write time. This is deliberate — a lead is
an append-only evidence-of-contact-intent record, and a race where
coverage changed between search and tap shouldn't block recording that
the customer tried to reach the vendor. Consequence: a lead's
subcategory/zone aren't guaranteed to reflect genuine vendor coverage.
This doesn't weaken review-gating above (which only checks a
customer-vendor lead exists, not subcategory/zone accuracy), but **Phase
6's leads analytics (task 6.2) must account for this** — the data isn't
guaranteed internally consistent. Revisit adding a coverage check only
if analytics accuracy turns out to actually require it.

---

## 10. Data integrity — no hard deletes on selectable master data

Categories, subcategories, and zones must never be hard-deletable through
the admin panel — only deactivatable via their `active` flag. This is
required, not optional: `subscription_items` stores selections as a
single table with an `item_type` enum rather than three separate
foreign-keyed pivot tables, which means `item_id` cannot carry a real
database-level foreign key. Hard-deleting a category/subcategory/zone
that's referenced by any subscription would leave an orphaned row. Every
Filament resource for these three models must disable the delete action
entirely (deactivate is the only removal path).

## 11. Zones — draft workflow

`zones.polygon` is NOT NULL with a SPATIAL INDEX always — never nullable,
even to support a "pincode-only, polygon added later" workflow, since
MySQL/MariaDB cannot index a nullable spatial column and every match
would become a full table scan as zones grow. Instead, zones support a
draft workflow via `is_active`: an admin can save a zone with a rough/
approximate polygon immediately, then refine it before setting
`is_active = true`. Only active zones participate in customer vendor
matching (Section 8).

## 12. Commission rules

Commission is generated only on paid subscriptions sold by a salesman
(`source = salesman`), not on free trials or vendor self-service
purchases. Starts `pending`, moves to `paid` once admin verifies the
underlying payment (especially cash) was reconciled.

## 13. Upgrade/downgrade proration (decided in task 4.7)

Changing plans credits the unused value of the old subscription
(`old.price_paise * unused_days / old.duration_days`) against the new
plan's price — a monetary credit, not a duration extension. The new
subscription always runs the new plan's standard `duration_days`
starting today. A downgrade can produce a credit larger than the new
plan's price; the amount charged floors at 0, and **the excess is never
refunded** — there's no refund mechanism without a real payment gateway
(no gateway SDK yet, per CLAUDE.md). This is a deliberate default, not
an oversight: revisit whether downgrades should trigger a real partial
refund once Phase 9 wires up an actual payment gateway. Commission is
computed off the discounted amount actually charged, never the plan's
list price — a salesman doesn't earn commission twice on money already
collected in the prior sale.
