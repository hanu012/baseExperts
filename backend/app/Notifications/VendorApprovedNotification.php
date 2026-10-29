<?php

namespace App\Notifications;

use App\Models\Vendor;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;

/**
 * SPEC section 5.8 — fills PushNotificationService::notifyVendorApproved
 * (stub since task 4.3, call site in VendorVerificationService::approve()).
 */
class VendorApprovedNotification extends Notification
{
    public function __construct(private readonly Vendor $vendor) {}

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
            'title' => 'Verification approved',
            'body' => "{$this->vendor->business_name} is now live and visible to customers.",
            'type' => 'verification_approved',
            'target_app' => 'vendor',
            'data' => ['vendor_id' => $this->vendor->id],
        ];
    }
}
