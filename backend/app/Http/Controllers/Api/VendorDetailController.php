<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOptionalAuthUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\VendorDetailRequest;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\VendorDetailResource;
use App\Http\Responses\ApiResponse;
use App\Models\Media;
use App\Models\Review;
use App\Models\Subcategory;
use App\Models\SubscriptionItem;
use App\Models\Vendor;
use App\Support\GeoDistance;
use Illuminate\Http\JsonResponse;

/**
 * Public vendor detail page (SPEC section 4 item 6, task 5.4) — the
 * screen the search-results list (task 5.3) leads into. Reviews (SPEC
 * section 9, task 5.5) filter out anything an admin has hidden — a
 * hidden review is never in the public response, only in the Filament
 * admin list.
 */
class VendorDetailController extends Controller
{
    use ResolvesOptionalAuthUser;

    public function show(int $vendorId, VendorDetailRequest $request): JsonResponse
    {
        // Optional: unauthenticated detail views still work exactly as
        // before (task 5.4), this only adds is_favorite when a valid
        // customer token happens to be presented (favorites task).
        $this->resolveOptionalUser($request);

        $vendor = Vendor::active()->find($vendorId);

        if ($vendor === null) {
            return ApiResponse::error('NOT_FOUND', 'This vendor is not available.', 404);
        }

        // Same visibility floor as vendor search (task 5.3): a vendor
        // without an active, unexpired subscription isn't customer-
        // visible at all, not even to browse.
        $subscription = $vendor->currentActiveSubscription();

        if ($subscription === null) {
            return ApiResponse::error('NOT_FOUND', 'This vendor is not available.', 404);
        }

        $subcategoryIds = SubscriptionItem::where('subscription_id', $subscription->id)
            ->where('item_type', 'subcategory')
            ->pluck('item_id');

        // Customer-facing, so a deactivated subcategory (or one under a
        // deactivated category) is hidden here like everywhere else in the
        // apps. Unlike ActiveSubscriptionSummary, which deliberately keeps
        // them for the vendor's own view of what they paid for.
        $services = Subcategory::with('category')
            ->whereIn('id', $subcategoryIds)
            ->active()
            ->whereHas('category', fn ($category) => $category->active())
            ->get()
            ->map(fn (Subcategory $subcategory) => [
                'subcategory_id' => $subcategory->id,
                'name' => $subcategory->name,
                'category_id' => $subcategory->category_id,
                'category_name' => $subcategory->category?->name,
            ])
            ->all();

        // Each item carries the service it shows, so the app can group the
        // gallery by service ("AC Repair › Gas Filling") rather than show
        // one undifferentiated wall. Work for a service the vendor no
        // longer offers (or that is now inactive) is left out, matching
        // $services; media with no service at all is kept.
        $visibleSubcategoryIds = array_column($services, 'subcategory_id');
        $servicesById = array_column($services, null, 'subcategory_id');

        $media = $vendor->media()
            ->where('moderation_status', 'approved')
            ->where(fn ($query) => $query
                ->whereNull('subcategory_id')
                ->orWhereIn('subcategory_id', $visibleSubcategoryIds))
            ->latest('created_at')
            ->get()
            ->map(fn (Media $item) => [
                'id' => $item->id,
                'type' => $item->type,
                'url' => $item->fileUrl(),
                'subcategory_id' => $item->subcategory_id,
                'subcategory_name' => $servicesById[$item->subcategory_id]['name'] ?? null,
                'category_id' => $servicesById[$item->subcategory_id]['category_id'] ?? null,
                'category_name' => $servicesById[$item->subcategory_id]['category_name'] ?? null,
                'created_at' => $item->created_at?->toIso8601String(),
            ])
            ->all();

        $reviews = Review::where('vendor_id', $vendor->id)
            ->where('is_hidden', false)
            ->with('customer.user')
            ->latest('created_at')
            ->get()
            ->map(fn (Review $review) => (new ReviewResource($review))->resolve())
            ->all();

        $distanceKm = null;

        // A vendor with no saved location has no distance — casting the
        // missing coordinates to 0 measured from the Gulf of Guinea
        // ("8237 km away").
        if ($request->hasPoint() && $vendor->latitude !== null && $vendor->longitude !== null) {
            $distanceKm = GeoDistance::km(
                (float) $request->input('latitude'),
                (float) $request->input('longitude'),
                (float) $vendor->latitude,
                (float) $vendor->longitude,
            );
        }

        return ApiResponse::success(new VendorDetailResource($vendor, $services, $media, $reviews, $distanceKm));
    }
}
