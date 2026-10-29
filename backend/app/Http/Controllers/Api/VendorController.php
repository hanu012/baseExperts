<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\AddSubscriptionItemsRequest;
use App\Http\Requests\Vendor\UpdateVendorMeRequest;
use App\Http\Resources\VendorResource;
use App\Http\Responses\ApiResponse;
use App\Services\SubscriptionService;
use App\Support\ActiveSubscriptionSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A vendor's own record (SPEC section 3.2) — the first stop for the vendor
 * app on login: has_active_subscription decides plan-selection vs
 * dashboard, and when there is one, the dashboard needs its plan name,
 * quota used/total, and days remaining. Also owns addServices() (task 4.4)
 * — filling remaining quota on the same active subscription later.
 */
class VendorController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    /**
     * 404 rather than an empty success: a vendor-role User with no Vendor
     * row is a real, if rare, possibility (e.g. one created outside this
     * flow, such as via User Management) — not a state the caller should
     * be able to mistake for "no subscription yet."
     */
    public function me(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if ($vendor === null) {
            return ApiResponse::error('NOT_FOUND', 'No vendor profile exists for this account.', 404);
        }

        return ApiResponse::success([
            'vendor' => new VendorResource($vendor->load('user')),
            'active_subscription' => ActiveSubscriptionSummary::for($vendor),
        ]);
    }

    /**
     * The vendor editing their own business profile (SPEC section 3.2).
     *
     * The vendor is resolved from the token, exactly as me() does — the
     * request carries no id, so there is no other vendor this can touch
     * and nothing to authorize beyond the route's role check.
     *
     * Returns the same payload as me() so the app can replace its whole
     * cached vendor in one step rather than patching fields locally and
     * drifting from the server.
     */
    public function updateMe(UpdateVendorMeRequest $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if ($vendor === null) {
            return ApiResponse::error('NOT_FOUND', 'No vendor profile exists for this account.', 404);
        }

        $vendor->update($request->updates());

        return ApiResponse::success([
            'vendor' => new VendorResource($vendor->fresh()->load('user')),
            'active_subscription' => ActiveSubscriptionSummary::for($vendor),
        ]);
    }

    /**
     * Adds categories/subcategories/zones to the caller's own active
     * subscription, within whatever quota is still unused (SPEC section
     * 3.3, task 4.4) — distinct from POST /subscriptions, which only ever
     * creates a fresh subscription for a `draft` vendor. With `replace:
     * true` the submission is the vendor's whole new selection and anything
     * left out is removed too; without it nothing is ever removed (see
     * AddSubscriptionItemsRequest).
     */
    public function addServices(AddSubscriptionItemsRequest $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        $this->subscriptions->addItems(
            $request->subscription(),
            $request->newCategoryIds(),
            $request->newSubcategoryIds(),
            $request->newZoneIds(),
            $request->removedCategoryIds(),
            $request->removedSubcategoryIds(),
            $request->removedZoneIds(),
        );

        return ApiResponse::success([
            'active_subscription' => ActiveSubscriptionSummary::for($vendor->fresh()),
        ]);
    }

}
