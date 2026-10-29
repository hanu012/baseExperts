<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the customer's Profile → My calls. Callers eager-load
 * `vendor` and `subcategory`.
 *
 * @mixin Lead
 */
class CustomerCallResource extends JsonResource
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
            'subcategory_id' => $this->subcategory_id,
            'subcategory_name' => $this->subcategory?->name,
            'channel' => $this->channel,
            // Approximate (tap Call → back in the app). Null for WhatsApp,
            // or when the app never reported it.
            'call_duration_seconds' => $this->call_duration_seconds,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
