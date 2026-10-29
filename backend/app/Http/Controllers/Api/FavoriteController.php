<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorSearchResource;
use App\Http\Responses\ApiResponse;
use App\Models\Favorite;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Favorites (SPEC section 4 item 10) — resolves everything from the
 * caller's own token, same "resolve everything from the caller" shape
 * as /vendors/me/* and /customers/me/*.
 */
class FavoriteController extends Controller
{
    /**
     * Toggles rather than separate favorite/unfavorite endpoints: the
     * client only ever needs "is it favorited now," either way.
     */
    public function toggle(Vendor $vendor, Request $request): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer === null) {
            return ApiResponse::error('NOT_FOUND', 'No customer profile exists for this account.', 404);
        }

        $favorite = Favorite::where('customer_id', $customer->id)
            ->where('vendor_id', $vendor->id)
            ->first();

        if ($favorite !== null) {
            $favorite->delete();

            return ApiResponse::success(['is_favorite' => false]);
        }

        Favorite::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
        ]);

        return ApiResponse::success(['is_favorite' => true]);
    }

    /**
     * The customer's own favorited vendors, most recently favorited
     * first — joined on `favorites` rather than `whereIn` so the
     * ordering reflects when each was favorited, not vendor id.
     */
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer === null) {
            return ApiResponse::error('NOT_FOUND', 'No customer profile exists for this account.', 404);
        }

        $paginator = Vendor::active()
            ->join('favorites', 'favorites.vendor_id', '=', 'vendors.id')
            ->where('favorites.customer_id', $customer->id)
            ->orderByDesc('favorites.created_at')
            ->select('vendors.*')
            ->paginate((int) $request->input('per_page', 15));

        $paginator->getCollection()->transform(
            fn (Vendor $vendor) => (new VendorSearchResource($vendor))->resolve($request)
        );

        return ApiResponse::paginated($paginator);
    }
}
