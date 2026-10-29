<?php

namespace App\Notifications;

use App\Models\Vendor;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;

/**
 * SPEC section 5.8 — fills PushNotificationService::notifyVendorRejected
 * (stub since task 4.3, call site in VendorVerificationService::reject()).
 */
class VendorRejectedNotification extends Notification
{
    public function __construct(private readonly Vendor $vendor, private readonly string $reason) {}

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
            'title' => 'Verification not approved',
            'body' => $this->reason,
            'type' => 'verification_rejected',
            'target_app' => 'vendor',
            'data' => ['vendor_id' => $this->vendor->id],
        ];
    }
}
