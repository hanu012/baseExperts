<?php

namespace App\Models\Concerns;

use App\Models\Vendor;

/**
 * Keeps `vendors.rating_avg`/`rating_count` in lockstep with a vendor's
 * non-hidden reviews (SPEC section 9, task 5.5) — the columns
 * `VendorSearchService`'s sort tier (task 5.3) has been reading since
 * before anything ever wrote to them.
 *
 * `saved` covers every write path that matters: a customer creating a
 * review, a customer editing one within the 24h window, and an admin
 * hiding/unhiding one — all three go through Review::create()/update(),
 * so one hook is enough. `deleted` covers a direct Review::delete() call
 * (none exists yet, but nothing forbids one later).
 *
 * KNOWN GAP: a Lead's cascadeOnDelete() removes its Review at the DB
 * layer, which does not fire Eloquent events — a hard-deleted lead would
 * leave the vendor's aggregate stale until the next review write. No
 * lead-delete feature exists today. Flagged in PROGRESS.md for whoever
 * builds customer account deletion (task 5.6), since that flow could
 * plausibly cascade through leads/reviews depending on how it's
 * implemented.
 */
trait RecalculatesVendorRating
{
    public static function bootRecalculatesVendorRating(): void
    {
        static::saved(function (self $review) {
            $review->recalculateVendorRating();
        });

        static::deleted(function (self $review) {
            $review->recalculateVendorRating();
        });
    }

    public function recalculateVendorRating(): void
    {
        $stats = static::query()
            ->where('vendor_id', $this->vendor_id)
            ->where('is_hidden', false)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as review_count')
            ->first();

        // Query builder update, not the Eloquent model: rating_avg/
        // rating_count are deliberately absent from Vendor's $fillable —
        // nothing should be able to set them via mass assignment.
        Vendor::where('id', $this->vendor_id)->update([
            'rating_avg' => round((float) ($stats->avg_rating ?? 0), 2),
            'rating_count' => (int) ($stats->review_count ?? 0),
        ]);
    }
}
