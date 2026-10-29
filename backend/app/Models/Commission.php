<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Salesman commission on a sale (SPEC section 12).
 *
 * Generated only on paid subscriptions sold by a salesman — never on free
 * trials or vendor self-service purchases. Starts pending, moves to paid
 * once an admin verifies the underlying payment was reconciled.
 *
 * SPEC section 5.14 calls this the highest-scrutiny audit case: a salesman
 * granting themselves commission is exactly the abuse this log has to catch.
 */
class Commission extends Model
{
    use HasFactory;
    use RecordsAuditLog;

    protected $fillable = [
        'subscription_id',
        'salesman_id',
        'amount_paise',
        'rate_bps',
        'status',
        'paid_at',
        'payout_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'rate_bps' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }
}
