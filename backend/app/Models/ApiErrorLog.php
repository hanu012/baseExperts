<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One failed API call, recorded by the LogApiErrors middleware while admin
 * Settings → Error log is on. Shown on System → API error log.
 *
 * Kept 30 days (model:prune, scheduled daily in routes/console.php).
 * Written once, never edited.
 */
class ApiErrorLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Days a row is kept. */
    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'status',
        'method',
        'path',
        'error_code',
        'message',
        'exception_class',
        'exception_location',
        'trace',
        'user_id',
        'ip_address',
        'user_agent',
        'request_payload',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'request_payload' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }
}
