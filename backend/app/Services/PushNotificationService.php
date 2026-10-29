<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Subscription;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Notifications\LeadReceivedNotification;
use App\Notifications\ReviewRequestedNotification;
use App\Notifications\SubscriptionExpiringNotification;
use App\Notifications\VendorApprovedNotification;
use App\Notifications\VendorDocumentNotification;
use App\Notifications\VendorRejectedNotification;

/**
 * Push notification seam for vendor verification outcomes (SPEC section
 * 5.8), a vendor's "Request a review" action (SPEC section 3 item 8,
 * task 4.8), lead-received, and expiry reminders (SPEC section 5.12).
 *
 * Each method sends a real Laravel Notification through FcmChannel
 * (BUILD_PLAN 7.2) — the seam itself, and every call site, predate
 * this and are unchanged; only the bodies went from no-op to real.
 */
class PushNotificationService
{
    public function notifyVendorApproved(Vendor $vendor): void
    {
        $vendor->user->notify(new VendorApprovedNotification($vendor));
    }

    public function notifyVendorRejected(Vendor $vendor, string $reason): void
    {
        $vendor->user->notify(new VendorRejectedNotification($vendor, $reason));
    }

    /** An admin sent a KYC document back (VendorKycService::requestReupload). */
    public function notifyDocumentReuploadRequested(VendorDocument $document): void
    {
        $document->vendor?->user?->notify(new VendorDocumentNotification($document, 'reupload'));
    }

    /** A KYC document passed its expiry date (VendorKycService::expireDue). */
    public function notifyDocumentExpired(VendorDocument $document): void
    {
        $document->vendor?->user?->notify(new VendorDocumentNotification($document, 'expired'));
    }

    /**
     * Sent to the CUSTOMER on the lead, not the vendor — the vendor is
     * the one asking, the customer is the one who'd act on it.
     */
    public function notifyReviewRequested(Vendor $vendor, Lead $lead): void
    {
        $lead->customer->user->notify(new ReviewRequestedNotification($vendor, $lead));
    }

    /**
     * SPEC section 5.12's "lead received" trigger — sent to the
     * VENDOR the instant LeadController::store() records a lead.
     */
    public function notifyLeadReceived(Lead $lead): void
    {
        $lead->vendor?->user?->notify(new LeadReceivedNotification($lead));
    }

    /**
     * SPEC section 5.12's expiry reminders (T-15/T-7/T-1) — sent to
     * the VENDOR by SendSubscriptionExpiryReminders, once per
     * threshold per subscription (idempotency tracked on the
     * subscription row itself, not here).
     */
    public function notifySubscriptionExpiring(Subscription $subscription, int $daysRemaining): void
    {
        $subscription->vendor->user->notify(new SubscriptionExpiringNotification($subscription, $daysRemaining));
    }
}
