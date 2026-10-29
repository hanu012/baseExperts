<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's favorited vendor (SPEC section 4 item 10). Existence of a
 * row is the only state — there is no boolean/status column to keep in
 * sync, so toggling is create-or-delete rather than an update.
 */
class Favorite extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'vendor_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
