<?php

namespace App\Enums;

/**
 * Whether an admin has let this account into the product yet.
 *
 * This replaced email verification as the single gate (SPEC section 3.1):
 * confirming an address proved only that the address existed, which is not
 * the question the business actually wanted answered. An admin looking at
 * the account is.
 *
 * Applies to every self-registered role, not just vendors — a salesman or
 * customer who signs themselves up is as unvetted as a vendor is.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Label for the admin panel and for the app's pending screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending verification',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Filament badge colour. Deliberately avoids the panel's reserved red for
     * anything but an actual rejection.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
