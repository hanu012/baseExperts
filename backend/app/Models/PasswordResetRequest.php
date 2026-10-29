<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user asking for their password to be reset, from an app's "Forgot
 * password?" screen. See the migration for why this is a queue for an admin
 * rather than an emailed link.
 */
class PasswordResetRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'user_id',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Closes the request without issuing a password (spam, duplicate, ...). */
    public function dismiss(?User $by): void
    {
        $this->forceFill([
            'status' => self::STATUS_DISMISSED,
            'resolved_by' => $by?->getKey(),
            'resolved_at' => now(),
        ])->save();
    }
}
