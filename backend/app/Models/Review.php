<?php

namespace App\Models;

use App\Models\Concerns\RecalculatesVendorRating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's review of a vendor (SPEC section 9, task 5.5) — always
 * attached to a specific `lead_id` (unique FK), never merely a
 * customer-vendor pair, so "one review per lead" is a DB constraint, not
 * app-level bookkeeping. `customer_id`/`vendor_id` are denormalized from
 * the lead for query convenience but are never the enforcement.
 *
 * `is_hidden`/`hidden_by`/`hidden_reason` are an admin flag, not a
 * delete — same "no hard delete on customer-generated content" stance
 * as Media (task 4.5). No 24-hour-edit column: that window is computed
 * from `created_at` at check time (ReviewController::update()), not
 * stored, so it can't drift from itself.
 */
class Review extends Model
{
    use RecalculatesVendorRating;

    protected $fillable = [
        'lead_id',
        'customer_id',
        'vendor_id',
        'rating',
        'comment',
        'is_hidden',
        'hidden_by',
        'hidden_reason',
        'vendor_reply',
        'replied_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_hidden' => 'boolean',
            'replied_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }
}
