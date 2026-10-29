<?php

namespace App\Http\Resources;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vendor's public detail page (SPEC section 4 item 6, task 5.4) —
 * services offered, approved media, reviews, distance, everything the
 * Call/WhatsApp buttons need.
 *
 * `services`/`media`/`reviews`/`distanceKm` aren't columns on Vendor, so
 * they're passed in explicitly rather than derived inside toArray() —
 * the controller already did the (subscription-scoped, moderation-
 * filtered, hidden-filtered) querying, this class only shapes the
 * response.
 */
class VendorDetailResource extends JsonResource
{
    /**
     * @param  array<int, array<string, mixed>>  $services
     * @param  array<int, array<string, mixed>>  $media
     * @param  array<int, array<string, mixed>>  $reviews
     */
    public function __construct(
        Vendor $resource,
        private readonly array $services,
        private readonly array $media,
        private readonly array $reviews,
        private readonly ?float $distanceKm,
    ) {
        parent::__construct($resource);
    }

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
            'phone' => $this->phone,
            'shop_photo_url' => $this->fileUrl($this->shop_photo_path),
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'distance_km' => $this->distanceKm,
            'services' => $this->services,
            'media' => $this->media,
            'reviews' => $this->reviews,
            'is_favorite' => $request->user()?->customer?->hasFavorited($this->id) ?? false,
        ];
    }
}
