<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as customers/vendors see it (SPEC section 9, task 5.5) —
 * used both for the write/update/reply endpoints' own responses and,
 * mapped to a plain array, for the list VendorDetailController injects
 * into VendorDetailResource. Every call site eager-loads `customer.user`
 * before building this resource, so `customer_name` is always present.
 * `is_hidden`/`hidden_by`/`hidden_reason` never appear here: hidden
 * reviews are filtered out before this resource is ever built for the
 * public detail response, and the write/update/reply responses don't
 * need them either.
 */
class ReviewResource extends JsonResource
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
