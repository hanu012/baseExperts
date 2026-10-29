<?php

namespace App\Services;

use App\Models\Review;
use App\Models\User;

/**
 * Hide/unhide transitions for the admin Review Management module (SPEC
 * section 5 item 6, task 5.5) — pulled out of the Filament actions so
 * the state transition lives in one place, same shape as
 * MediaModerationService. Both go through Review::update(), which fires
 * RecalculatesVendorRating's bootRecalculatesVendorRating() hook, so
 * vendors.rating_avg/rating_count stay correct without either method
 * doing anything about it explicitly.
 */
class ReviewModerationService
{
    public function hide(Review $review, User $admin, string $reason): void
    {
        $review->update([
            'is_hidden' => true,
            'hidden_by' => $admin->id,
            'hidden_reason' => $reason,
        ]);
    }

    public function unhide(Review $review): void
    {
        $review->update([
            'is_hidden' => false,
            'hidden_by' => null,
            'hidden_reason' => null,
        ]);
    }
}
