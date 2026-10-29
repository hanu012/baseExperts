<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vendor as the salesman app sees it while building a draft.
 *
 * `id` is the important field: the app stores it so a dropped connection
 * resumes the flow rather than restarting it (SPEC section 2.2).
 */
class VendorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'owner_name' => $this->owner_name,
            'phone' => $this->phone,
            'email' => $this->whenLoaded('user', fn () => $this->user->email),
            // The account's own profile photo — not the shop photo, which is
            // KYC and is what customers see.
            'avatar_url' => $this->whenLoaded('user', fn () => $this->user->avatarUrl()),
            'address' => $this->address,
            'city' => $this->city,
            'about' => $this->about,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,

            // Resolved through the row's own disk, so files already moved to
            // R2 and files still local both return a working URL.
            'shop_photo_url' => $this->fileUrl($this->shop_photo_path),
            'id_proof_url' => $this->fileUrl($this->id_proof_path),
            'id_proof_type' => $this->id_proof_type,

            // Lets the app show which step still needs work when resuming.
            'has_kyc' => $this->shop_photo_path !== null && $this->id_proof_path !== null,

            // SPEC section 3.2: what GET /api/vendors/me's caller (task 4.2)
            // branches on — skip plan selection when this is true. Reuses
            // Vendor::currentActiveSubscription() (widened in task 7.1 to
            // include grace) rather than a separate query, so a vendor
            // whose subscription just entered its grace window still lands
            // on their dashboard to renew, not back on a fresh plan-
            // selection screen that would push them toward accidentally
            // resubscribing from scratch instead.
            'has_active_subscription' => $this->currentActiveSubscription() !== null,

            // A sale waiting on its Razorpay payment link (only while
            // Razorpay is switched on). The vendor app shows a "waiting for
            // payment" screen instead of plan selection. The link itself is
            // not sent: it reached the vendor by SMS, and the app does not
            // take the payment itself.
            'pending_payment' => $this->pendingPaymentSummary(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{plan_name: ?string, amount_paise: int, expires_at: ?string}|null
     */
    private function pendingPaymentSummary(): ?array
    {
        $payment = Payment::query()
            ->where('status', 'pending')
            ->whereNull('subscription_addon_id')
            ->whereHas('subscription', fn ($query) => $query
                ->where('vendor_id', $this->id)
                ->where('status', 'pending_payment'))
            ->with('subscription.plan')
            ->latest()
            ->first();

        if ($payment === null) {
            return null;
        }

        return [
            'plan_name' => $payment->subscription?->plan?->name,
            'amount_paise' => $payment->amount_paise,
            'expires_at' => $payment->payment_link_expires_at?->toIso8601String(),
        ];
    }
}
