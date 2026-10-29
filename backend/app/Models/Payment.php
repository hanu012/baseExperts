<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A real payment row (SPEC section 6.5), not a boolean on the subscription.
 *
 * Shape designed to survive a real payment gateway arriving later: only
 * PaymentService's internals change, not this table or its callers.
 */
class Payment extends Model
{
    use HasFactory;
    use RecordsAuditLog;

    protected $fillable = [
        'subscription_id',
        'subscription_addon_id',
        'amount_paise',
        'currency',
        'mode',
        'gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'payment_link_url',
        'payment_link_expires_at',
        'status',
        'collected_by_salesman_id',
        'admin_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'admin_verified_at' => 'datetime',
            'payment_link_expires_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Set when this payment buys an add-on rather than the subscription. */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAddon::class, 'subscription_addon_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function collectedBySalesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'collected_by_salesman_id');
    }
}
