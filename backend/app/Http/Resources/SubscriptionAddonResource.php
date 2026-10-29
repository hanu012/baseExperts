<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API transformer for a purchased add-on (SPEC section 3 item 6 /
 * section 6, task 4.7).
 */
class SubscriptionAddonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            // NOT $this->resource — that name collides with JsonResource's
            // own `$resource` property (the wrapped model itself), so it
            // never reaches the underlying attribute via __get() magic.
            // getAttribute() reads the column directly instead.
            'resource' => $this->getAttribute('resource'),
            'quantity' => $this->quantity,
            'price_paise' => $this->price_paise,
            // paid | pending_payment | cancelled
            'status' => $this->status,
            'payment_link_url' => $this->payment?->payment_link_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
