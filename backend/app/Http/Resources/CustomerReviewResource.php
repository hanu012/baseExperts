<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the customer's Profile → My reviews. Callers eager-load
 * `vendor` and `lead.subcategory`.
 *
 * Unlike ReviewResource this is the author's own view, so a review an
 * admin hid is still listed — it would be confusing for it to vanish
 * from the customer's own history — but its hidden_reason is not sent:
 * that note is for the moderation team, not the reviewer.
 *
 * @mixin Review
 */
class CustomerReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->vendor?->business_name,
            'subcategory_id' => $this->lead?->subcategory_id,
            'subcategory_name' => $this->lead?->subcategory?->name,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'vendor_reply' => $this->vendor_reply,
            'replied_at' => $this->replied_at?->toIso8601String(),
            'is_hidden' => (bool) $this->is_hidden,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
