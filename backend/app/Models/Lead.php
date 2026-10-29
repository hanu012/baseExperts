<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Written the instant a customer taps Call or WhatsApp on a vendor's
 * detail page (SPEC section 4 item 7, task 5.4) — *before* the dialer or
 * WhatsApp intent opens, never fire-and-forget. Reviews (task 5.5),
 * salesman renewal evidence, and zone-value reporting all read from this
 * table, so the write has to be durable and confirmed, not best-effort.
 *
 * Append-only by design: no SoftDeletes (not in the migration), no
 * RecordsAuditLog (this is a customer-generated event log, not
 * admin-edited master data).
 *
 * `subcategory_id`/`zone_id` are validated as real rows only, not
 * cross-checked against the vendor's actual coverage at write time — see
 * CreateLeadRequest's docblock and SPEC section 9's data-integrity note.
 */
class Lead extends Model
{
    protected $fillable = [
        'customer_id',
        'vendor_id',
        'subcategory_id',
        'zone_id',
        'channel',
        'review_requested_at',
    ];

    /** Longest call length accepted — the app caps at this too. */
    public const MAX_CALL_DURATION_SECONDS = 3600;

    /** How long after the lead the app may still report the call length. */
    public const DURATION_REPORT_WINDOW_HOURS = 3;

    protected function casts(): array
    {
        return [
            'review_requested_at' => 'datetime',
            'call_duration_seconds' => 'integer',
            'call_duration_recorded_at' => 'datetime',
        ];
    }

    /**
     * Records the approximate call length, once. Not fillable on purpose:
     * only LeadController::recordDuration() sets it, after its ownership
     * and time-window checks.
     */
    public function recordCallDuration(int $seconds): void
    {
        $this->forceFill([
            'call_duration_seconds' => min(max($seconds, 0), self::MAX_CALL_DURATION_SECONDS),
            'call_duration_recorded_at' => now(),
        ])->save();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * At most one — `reviews.lead_id` is unique (SPEC section 9, task
     * 5.5). Used by StoreReviewRequest's `whereDoesntHave('review')` to
     * find a customer's still-eligible leads with a vendor.
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }
}
