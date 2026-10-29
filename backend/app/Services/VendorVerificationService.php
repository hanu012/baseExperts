<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Approve/Reject transitions for the vendor verification queue (SPEC
 * section 5.8) — pulled out of the Filament actions so the state transition
 * lives in one place regardless of which Action class triggers it (table
 * row vs page header).
 *
 * Each transition moves TWO states: the vendor's own lifecycle status, and
 * the account-level approval gate on the owning user (SPEC section 3.1).
 * They are deliberately driven from one place rather than left for an admin
 * to set twice — a vendor marked active whose user is still Pending can log
 * in and reach nothing, which reads as a broken app rather than a missed
 * click. Wrapped in a transaction for the same reason: half of that pair
 * applied is precisely the inconsistent state being avoided.
 */
class VendorVerificationService
{
    public function __construct(private PushNotificationService $pushNotifications)
    {
    }

    public function approve(Vendor $vendor, User $admin): void
    {
        DB::transaction(function () use ($vendor, $admin) {
            $vendor->update([
                // Only a vendor actually awaiting verification is promoted.
                //
                // Approving used to force 'active' whatever the vendor's
                // state, which quietly broke the self-signup flow: an
                // admin approving the ACCOUNT (so the person can get into
                // the app at all — SPEC section 3.1) also marked a vendor
                // who had never paid as active. Subscribe then refused
                // them, because StoreSubscriptionRequest only accepts a
                // vendor still in 'draft'. The result was a vendor stuck
                // active with no subscription and no way to buy one.
                //
                // A draft vendor keeps its status and just gets its
                // account let in; verification happens later, after they
                // subscribe and land in pending_verification.
                'status' => $this->statusAfterApproval($vendor),
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'rejection_reason' => null,
            ]);

            $vendor->user?->recordApprovalDecision(ApprovalStatus::Approved, $admin);
        });

        // Sent after the commit so a rolled-back approval cannot leave the
        // vendor holding a push that says they were approved.
        $this->pushNotifications->notifyVendorApproved($vendor);
    }

    /**
     * Reversing a rejection must not leave the vendor 'rejected' while the
     * account reads approved — that pair is invisible to customers with no
     * hint why. A rejected vendor returns to active only if they hold a
     * current paid subscription, otherwise to draft so they can still buy one.
     */
    private function statusAfterApproval(Vendor $vendor): string
    {
        return match ($vendor->status) {
            'pending_verification' => 'active',
            'rejected' => $vendor->currentActiveSubscription() !== null ? 'active' : 'draft',
            default => $vendor->status,
        };
    }

    public function reject(Vendor $vendor, User $admin, string $reason): void
    {
        DB::transaction(function () use ($vendor, $admin, $reason) {
            $vendor->update([
                'status' => 'rejected',
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'rejection_reason' => $reason,
            ]);

            $vendor->user?->recordApprovalDecision(
                ApprovalStatus::Rejected,
                $admin,
                $reason,
            );
        });

        $this->pushNotifications->notifyVendorRejected($vendor, $reason);
    }
}
