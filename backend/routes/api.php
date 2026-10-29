<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ChangePasswordController;
use App\Http\Controllers\Api\CustomerAddressController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DeleteAccountController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RazorpayWebhookController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\PasswordResetRequestController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SalesmanController;
use App\Http\Controllers\Api\ServiceSearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\VendorDetailController;
use App\Http\Controllers\Api\VendorDocumentController;
use App\Http\Controllers\Api\VendorDraftController;
use App\Http\Controllers\Api\VendorLeadController;
use App\Http\Controllers\Api\VendorPortfolioController;
use App\Http\Controllers\Api\VendorReviewController;
use App\Http\Controllers\Api\VendorSearchController;
use App\Http\Controllers\Api\ZoneController;
use App\Http\Middleware\HandleIdempotentAddonPurchase;
use App\Http\Middleware\HandleIdempotentSubscription;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register');

    // No throttle middleware here on purpose: the limiter is applied inside
    // AuthController::login so that only *failed* attempts are counted.
    Route::post('/login', [AuthController::class, 'login']);

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');

    // Changing your own password while signed in. There is no emailed reset
    // flow and no email verification: the platform sends no mail at all, and
    // admin approval is the single gate on an account (SPEC section 3.1).
    //
    // A locked-out user is recovered by an admin issuing a fresh temporary
    // password from the panel, which sets must_change_password — this route
    // is then the way out of that, so RequirePasswordChange lets it through.
    Route::post('/change-password', ChangePasswordController::class)
        ->middleware(['auth:sanctum', 'throttle:change-password']);

    // "Forgot password?" — emails a reset link (PasswordResetService), or
    // refuses an email with no account. Public, since the caller cannot
    // sign in; rate limited, since it reveals whether an email exists.
    Route::post('/password-reset-requests', PasswordResetRequestController::class)
        ->middleware('throttle:password-reset-request');
});

// Razorpay → us when a payment link is paid, expires or is cancelled. No
// token: Razorpay signs the body instead (X-Razorpay-Signature), checked in
// the controller before anything is read. See RazorpayWebhookController.
Route::post('/webhooks/razorpay', RazorpayWebhookController::class)
    ->middleware('throttle:public-read');

// Public master data. No token: customers browse categories before signing
// in, and the vendor/salesman apps need the tree to render plan selection.
Route::get('/categories', [CategoryController::class, 'index'])
    ->middleware('throttle:public-read');

// "X vendors" per subcategory on the customer subcategories screen.
// Zone/customer-specific, unlike /categories above, so it is its own
// endpoint rather than a field on the cached tree.
Route::get('/categories/{category}/vendor-counts', [CategoryController::class, 'vendorCounts'])
    ->middleware('throttle:public-read');

// Free-text service lookup for the customer search bar. Separate from
// /categories above: that one returns the whole tree as cache-on-launch
// master data, this returns a short ranked slice for a query.
Route::get('/services/search', [ServiceSearchController::class, 'index'])
    ->middleware('throttle:public-read');

Route::get('/plans', [PlanController::class, 'index'])
    ->middleware('throttle:public-read');

Route::get('/zones', [ZoneController::class, 'index'])
    ->middleware('throttle:public-read');

Route::get('/settings', [SettingController::class, 'index'])
    ->middleware('throttle:public-read');

// Banner serving + click tracking (SPEC section 5 item 5). Public,
// same reasoning as the master-data reads above — no Flutter display
// work exists yet to consume this (SPEC never specifies where/how a
// banner renders in any app flow); this is the minimal API that makes
// click_count a real counter instead of a decorative admin field.
Route::get('/banners', [BannerController::class, 'index'])
    ->middleware('throttle:public-read');

Route::post('/banners/{banner}/click', [BannerController::class, 'click'])
    ->middleware('throttle:banner-click');

// The core customer vendor-matching query (SPEC section 4 item 4, task
// 5.3). Public, not scoped to a caller — location is always an explicit
// param, never resolved from a stored profile. MUST be registered before
// GET /vendors/{vendor} below, same wildcard-shadowing reason as
// /vendors/me.
Route::get('/vendors/search', [VendorSearchController::class, 'index'])
    ->middleware('throttle:public-read');

// Public vendor detail page (SPEC section 4 item 6, task 5.4) — same
// "explicit param, not self-resolved" location reasoning as
// /vendors/search above. No ordering hazard with /vendors/{vendor}
// below: different path depth, same as /vendors/{vendor}/kyc already
// coexisting with it.
Route::get('/vendors/{vendor}/detail', [VendorDetailController::class, 'show'])
    ->middleware('throttle:public-read');

// A vendor's own record (SPEC section 3.2) — MUST be registered before
// GET /vendors/{vendor} below, or "me" would be matched as a vendor id by
// that wildcard route instead of ever reaching this one.
Route::middleware(['auth:sanctum', 'role:vendor'])->group(function () {
    Route::get('/vendors/me', [VendorController::class, 'me']);

    // The vendor editing their own business profile (SPEC section 3.2).
    // No id in the path for the same reason as the GET: the vendor is
    // resolved from the token, so there is no other record to reach.
    Route::patch('/vendors/me', [VendorController::class, 'updateMe']);

    // Adding within remaining quota on the vendor's own active subscription
    // (SPEC section 3.3, task 4.4) — distinct from POST /subscriptions,
    // which only ever creates a fresh one for a `draft` vendor. No
    // Idempotency-Key: unlike subscribe, there's no payment/commission
    // side effect, and the diff-based insert is naturally idempotent.
    Route::post('/vendors/me/services', [VendorController::class, 'addServices']);

    // Portfolio media (SPEC section 3 item 5, task 4.5) — photos/videos of
    // completed work, quota-capped by the active subscription's plan, same
    // "resolve everything from the caller" shape as the two routes above.
    Route::get('/vendors/me/portfolio', [VendorPortfolioController::class, 'index']);
    Route::post('/vendors/me/portfolio', [VendorPortfolioController::class, 'store']);

    // A vendor's right of reply on a review of their own listing (SPEC
    // section 4 item 9, task 5.5), and the vendor's own unfiltered
    // Reviews tab (SPEC section 3 item 8, task 4.8).
    Route::get('/vendors/me/reviews', [VendorReviewController::class, 'index']);
    Route::post('/vendors/me/reviews/{review}/reply', [VendorReviewController::class, 'reply'])
        ->middleware('throttle:reviews');

    // The Leads tab (SPEC section 3 item 7) and "Request a review"
    // (SPEC section 3 item 8), task 4.8. Neither is money-bearing, so
    // no Idempotency-Key/throttle beyond the standard sanctum guard —
    // same shape as /vendors/me/services and /vendors/me/portfolio.
    // KYC documents (VendorKycService). Open while the account is pending
    // approval — see RequireApprovedAccount.
    Route::get('/vendors/me/documents', [VendorDocumentController::class, 'indexMine']);
    Route::post('/vendors/me/documents', [VendorDocumentController::class, 'storeMine'])
        ->middleware('throttle:kyc-upload');

    Route::get('/vendors/me/leads', [VendorLeadController::class, 'index']);
    // Counts for the vendor home "Recent calls" card.
    Route::get('/vendors/me/leads/summary', [VendorLeadController::class, 'summary']);
    Route::post('/vendors/me/leads/{lead}/request-review', [VendorLeadController::class, 'requestReview']);
});

// A customer's own record (SPEC section 4.2, task 4.6) — location
// detection for the home screen header, GPS point or pincode fallback.
Route::middleware(['auth:sanctum', 'role:customer'])->group(function () {
    Route::post('/customers/me/location', [CustomerController::class, 'updateLocation']);

    // Saved addresses — the address book behind the location picker and
    // Profile → My addresses. Choosing one also goes through
    // customers/me/location above, which is what vendor matching reads.
    Route::get('/customers/me/addresses', [CustomerAddressController::class, 'index']);
    Route::post('/customers/me/addresses', [CustomerAddressController::class, 'store']);
    Route::patch('/customers/me/addresses/{address}', [CustomerAddressController::class, 'update'])
        ->whereNumber('address');
    Route::delete('/customers/me/addresses/{address}', [CustomerAddressController::class, 'destroy'])
        ->whereNumber('address');
    Route::post('/customers/me/addresses/{address}/default', [CustomerAddressController::class, 'makeDefault'])
        ->whereNumber('address');

    // SPEC section 4 item 7, task 5.4 — the write the vendor-detail
    // screen's Call/WhatsApp buttons must complete and confirm BEFORE
    // opening the dialer/WhatsApp intent.
    Route::post('/leads', [LeadController::class, 'store'])
        ->middleware('throttle:leads');

    // Approximate call length, reported by the customer app when the
    // customer returns from the dialer; and the customer's own call
    // history (Profile → My calls).
    Route::post('/leads/{lead}/duration', [LeadController::class, 'recordDuration'])
        ->whereNumber('lead')
        ->middleware('throttle:leads');
    Route::get('/customers/me/calls', [LeadController::class, 'myCalls']);

    // SPEC section 9, task 5.5 — write and 24-hour edit window. Both
    // routes resolve everything from the caller's token (never a
    // client-sent customer_id), same "resolve everything from the
    // caller" shape as /vendors/me/*.
    Route::post('/reviews', [ReviewController::class, 'store'])
        ->middleware('throttle:reviews');
    Route::patch('/reviews/{review}', [ReviewController::class, 'update'])
        ->middleware('throttle:reviews');
    // The customer's own reviews (Profile → My reviews).
    Route::get('/customers/me/reviews', [ReviewController::class, 'mine']);

    // Favorites (SPEC section 4 item 10). A toggle rather than separate
    // favorite/unfavorite routes — the caller only ever needs "is it
    // favorited now." is_favorite itself surfaces on the public
    // /vendors/search and /vendors/{vendor}/detail responses too, via
    // ResolvesOptionalAuthUser — those two stay outside this group since
    // they must keep working for a guest.
    Route::post('/vendors/{vendor}/favorite', [FavoriteController::class, 'toggle'])
        ->middleware('throttle:favorites');
    Route::get('/customers/me/favorites', [FavoriteController::class, 'index']);

    // Report vendor (SPEC section 4 item 10 / section 5.15) — minimal,
    // see ReportController's own docblock. Not the full Support Tickets
    // module (Phase 6).
    Route::post('/vendors/{vendor}/report', [ReportController::class, 'store'])
        ->middleware('throttle:reports');
});

// Salesman add-vendor flow (SPEC section 2.2). Admin included because
// User Management can create vendors too (SPEC section 5.2). Vendor
// deliberately NOT included here — these three routes act on a vendor
// someone else is managing, not the caller's own record.
Route::middleware(['auth:sanctum', 'role:salesman,admin'])->group(function () {
    Route::post('/vendors/draft', [VendorDraftController::class, 'store']);
    Route::post('/vendors/{vendor}/kyc', [VendorDraftController::class, 'storeKyc']);
    // KYC documents for a vendor this salesman onboarded.
    Route::get('/vendors/{vendor}/documents', [VendorDocumentController::class, 'index']);
    Route::post('/vendors/{vendor}/documents', [VendorDocumentController::class, 'store'])
        ->middleware('throttle:kyc-upload');
    // Re-sends the vendor's "set your password" email (Firebase sign-in).
    // Same limit as "Forgot password?" — it sends mail.
    Route::post('/vendors/{vendor}/password-link', [VendorDraftController::class, 'sendPasswordLink'])
        ->middleware('throttle:password-reset-request');
    Route::get('/vendors/{vendor}', [VendorDraftController::class, 'show']);
});

// Subscribe (SPEC section 6) — salesman/admin-led (source=salesman/admin)
// AND vendor self-service (source=self, task 4.2). A vendor caller is
// restricted to payment_mode=online and can only subscribe their own
// vendor_id — both enforced in StoreSubscriptionRequest, not by the role
// gate here. HandleIdempotentSubscription runs before StoreSubscriptionRequest
// is ever resolved, so a replayed Idempotency-Key never touches validation
// rules that the first, successful call itself invalidated.
Route::middleware(['auth:sanctum', 'role:salesman,admin,vendor'])->group(function () {
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])
        ->middleware(['throttle:subscribe', HandleIdempotentSubscription::class]);

    // Renewal for a vendor who already had a plan (active early, grace, or
    // expired) — RenewSubscriptionRequest / SubscriptionService::renew().
    Route::post('/subscriptions/renew', [SubscriptionController::class, 'renew'])
        ->middleware(['throttle:subscribe', HandleIdempotentSubscription::class]);

    // Upgrade/downgrade and add-on (SPEC section 3 item 6 / section 6,
    // task 4.7) — same dual-path role gate as fresh subscribe, since
    // both are money-bearing (unlike task 4.4's free add-services,
    // which stays vendor-only). Ownership/payment-mode restrictions are
    // enforced in the request classes, not this gate, same shape as
    // StoreSubscriptionRequest.
    Route::post('/subscriptions/{subscription}/change-plan', [SubscriptionController::class, 'changePlan'])
        ->middleware(['throttle:subscribe', HandleIdempotentSubscription::class]);
    Route::post('/subscriptions/{subscription}/add-ons', [SubscriptionController::class, 'addOns'])
        ->middleware(['throttle:subscribe', HandleIdempotentAddonPurchase::class]);
});

// A salesman's own records (SPEC sections 2.3, 2.4) — never admin, since
// there is no "own vendors" concept for an admin here.
Route::middleware(['auth:sanctum', 'role:salesman'])->group(function () {
    Route::get('/salesmen/me', [SalesmanController::class, 'me']);
    Route::patch('/salesmen/me', [SalesmanController::class, 'updateMe']);
    Route::get('/salesmen/me/vendors', [SalesmanController::class, 'vendors']);
    Route::get('/salesmen/me/commissions', [SalesmanController::class, 'commissions']);
});

Route::get('/user', function (Request $request) {
    return ApiResponse::success(new UserResource($request->user()));
})->middleware('auth:sanctum');

// Language and the notification mute (SPEC section 2.5's profile screen).
// Role-agnostic: all three apps show the same two switches. Admin is
// excluded for the same reason as account deletion below — the panel is
// not one of these apps.
Route::patch('/user/preferences', [PreferenceController::class, 'update'])
    ->middleware(['auth:sanctum', 'role:vendor,salesman,customer']);

// Personal details and profile photo, edited from every app's profile
// screen. Same role list: the panel edits users in Filament.
Route::middleware(['auth:sanctum', 'role:vendor,salesman,customer'])->group(function () {
    Route::patch('/user/profile', [ProfileController::class, 'update']);
    Route::post('/user/avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('/user/avatar', [ProfileController::class, 'destroyAvatar']);
});

// Self-service account deletion (SPEC section 4 item 10, "required for
// app store compliance"). Deliberately excludes admin — closed by
// decision, not by omission, even though no admin self-delete path
// exists today.
Route::delete('/user', DeleteAccountController::class)
    ->middleware(['auth:sanctum', 'role:vendor,salesman,customer']);

// FCM device registration (BUILD_PLAN 7.2) — same role list as
// account deletion above; admin uses the Filament panel, not one of
// the 3 Flutter apps, so it has no device to register push against.
Route::middleware(['auth:sanctum', 'role:vendor,salesman,customer'])->group(function () {
    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);
});
