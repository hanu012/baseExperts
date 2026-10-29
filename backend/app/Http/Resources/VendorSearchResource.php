<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vendor as it appears in customer search results (SPEC section 4 item
 * 4, task 5.3). Deliberately narrower than VendorResource, which is
 * shaped for the salesman/self "my draft" case and leaks KYC/id-proof
 * fields that must never reach a public search response.
 */
class VendorSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'shop_photo_url' => $this->fileUrl($this->shop_photo_path),
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'is_favorite' => $this->isFavorite($request),
            // Only present when the query selected it (a lat/lng search),
            // same as VendorDetailResource's own distance_km.
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            // Attached by the controller as a plain array, not a
            // relation — see VendorSearchController::attachServices().
            // Round-trips through JsonResource untouched since it is
            // already the shape the app wants.
            'services' => $this->services ?? [],
        ];
    }

    /**
     * False for a guest or a non-customer caller — favoriting is a
     * customer-only concept. $request->user() only resolves here when
     * the route itself authenticated the caller (favorites list) or
     * ResolvesOptionalAuthUser did so manually (public search/detail).
     */
    private function isFavorite(Request $request): bool
    {
        return $request->user()?->customer?->hasFavorited($this->id) ?? false;
    }
}
