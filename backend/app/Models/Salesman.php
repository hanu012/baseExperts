<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Salesman profile, 1:1 with a user carrying role = salesman.
 *
 * SPEC section 1: salesmen never self-register — an admin creates the account
 * and a temp password is shown once. `must_change_password` backs SPEC
 * section 2.1's forced change on first login.
 */
class Salesman extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'employee_code',
        'phone',
        'region',
        'monthly_target_paise',
        'commission_rate_bps',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_target_paise' => 'integer',
            'commission_rate_bps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Vendors this salesman onboarded. */
    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class, 'created_by_salesman_id');
    }

    /** Commissions earned on sales this salesman made. */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    /** Basis points: 1200 = 12.00%. Integer, for the same reason money is. */
    public function commissionRatePercent(): string
    {
        return number_format($this->commission_rate_bps / 100, 2, '.', '');
    }
}
