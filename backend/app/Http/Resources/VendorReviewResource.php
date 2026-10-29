<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as the vendor's OWN Reviews tab sees it (SPEC section 3 item
 * 8, task 4.8) — deliberately different from the customer-facing
 * `ReviewResource`, which excludes `is_hidden` because hidden reviews
 * are filtered out before that resource is ever built. Here, a vendor
 * should see ALL their reviews, hidden or not — a review silently
 * disappearing from their own management view with no explanation is
 * worse than a small "hidden by admin" indicator.
 */
class VendorReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'customer_name' => $this->customer?->user?->name,
            'vendor_reply' => $this->vendor_reply,
            'replied_at' => $this->replied_at?->toIso8601String(),
            'is_hidden' => $this->is_hidden,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
