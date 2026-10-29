<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\Vendor;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;

/**
 * SPEC section 3 item 8 — fills
 * PushNotificationService::notifyReviewRequested (stub since task
 * 4.8, call site in VendorLeadController::requestReview()). Sent to
 * the CUSTOMER on the lead, not the vendor — the vendor is the one
 * asking, the customer is the one who'd act on it.
 */
class ReviewRequestedNotification extends Notification
{
    public function __construct(private readonly Vendor $vendor, private readonly Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'How was your experience?',
            'body' => "{$this->vendor->business_name} would love your feedback.",
            'type' => 'review_request',
            'target_app' => 'customer',
            'data' => ['lead_id' => $this->lead->id, 'vendor_id' => $this->vendor->id],
        ];
    }
}
