<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API transformer for a subscription — what the salesman/admin app shows
 * after a successful Subscribe (SPEC section 6).
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'plan_id' => $this->plan_id,
            'plan_name' => $this->whenLoaded('plan', fn () => $this->plan->name),
            'source' => $this->source,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'price_paise' => $this->price_paise,
            'duration_days' => $this->duration_days,
            'free_trial_days' => $this->free_trial_days,
            'category_ids' => $this->whenLoaded('items', fn () => $this->itemIds('category')),
            'subcategory_ids' => $this->whenLoaded('items', fn () => $this->itemIds('subcategory')),
            'zone_ids' => $this->whenLoaded('items', fn () => $this->itemIds('zone')),
            'payment' => $this->whenLoaded('payments', function () {
                $payment = $this->payments->first();

                return $payment === null ? null : [
                    'mode' => $payment->mode,
                    'amount_paise' => $payment->amount_paise,
                    'status' => $payment->status,
                    // Set only while Razorpay is on. For a pending payment
                    // the app shows and shares this link.
                    'payment_link_url' => $payment->payment_link_url,
                    'payment_link_expires_at' => $payment->payment_link_expires_at?->toIso8601String(),
                ];
            }),
            'commission' => $this->whenLoaded('commission', fn () => $this->commission === null ? null : [
                'amount_paise' => $this->commission->amount_paise,
                'status' => $this->commission->status,
            ]),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function itemIds(string $type): array
    {
        return $this->items
            ->where('item_type', $type)
            ->pluck('item_id')
            ->values()
            ->all();
    }
}
