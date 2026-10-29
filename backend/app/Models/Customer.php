<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Customer profile, 1:1 with a user carrying role = customer.
 *
 * Every field is optional: SPEC section 4.1 asks only for email and password
 * to sign up, and location is captured later from GPS or a pincode fallback
 * (section 4.2).
 */
class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'phone',
        'latitude',
        'longitude',
        'pincode',
    ];

    protected function casts(): array
    {
        return [
            // float, not decimal:7 — Laravel's decimal cast returns a STRING to
            // preserve precision, so the API emitted "23.0300000" and the apps
            // crashed casting it to num. A double holds 10 significant digits
            // exactly, which is all a lat/lng of this scale needs.
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The address book — see CustomerAddress. */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Shared by VendorSearchResource and VendorDetailResource so
     * "is this vendor favorited" is defined once, not duplicated per
     * resource.
     */
    public function hasFavorited(int $vendorId): bool
    {
        return $this->favorites()->where('vendor_id', $vendorId)->exists();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
