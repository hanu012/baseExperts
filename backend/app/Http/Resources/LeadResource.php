<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lead as the vendor Leads tab sees it (SPEC section 3 item 7, task
 * 4.8). Customer name only, not phone — SPEC's field list is "every
 * customer who tapped Call, with date and service requested," and
 * Customer.phone isn't exposed to a vendor anywhere else in this app.
 *
 * Every call site eager-loads `customer.user`/`subcategory`/`zone`/
 * `review` before building this resource.
 */
class LeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_name' => $this->customer?->user?->name,
            'subcategory_name' => $this->subcategory?->name,
            'zone_name' => $this->zone?->name,
            'channel' => $this->channel,
            // Approximate call length in seconds; null for WhatsApp or when
            // the customer app did not report one.
            'call_duration_seconds' => $this->call_duration_seconds,
            'created_at' => $this->created_at?->toIso8601String(),
            'review_requested_at' => $this->review_requested_at?->toIso8601String(),
            'has_review' => $this->relationLoaded('review') ? $this->review !== null : $this->review()->exists(),
        ];
    }
}
