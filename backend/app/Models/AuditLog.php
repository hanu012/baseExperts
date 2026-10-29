<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Who changed what, when (SPEC section 5.14).
 *
 * Write-once: there is no updated_at, and nothing should ever update or
 * delete a row here. An audit trail that can be edited is not one.
 *
 * Deliberately NOT audited itself — it carries no auditing trait, which also
 * stops an infinite loop of logs about logs.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** The actor. Null for system changes — seeders, console commands, jobs. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeFor($query, Model $model)
    {
        return $query
            ->where('auditable_type', $model->getMorphClass())
            ->where('auditable_id', $model->getKey());
    }
}
