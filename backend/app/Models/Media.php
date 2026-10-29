<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use App\Models\Concerns\TracksFileDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An uploaded file — vendor portfolio photos/videos in practice (SPEC
 * section 3 item 5, task 4.5), routed through the admin moderation queue
 * (section 5.10) before going live. Polymorphic (`mediable`) so KYC
 * documents or banner assets could share this table later without a new
 * one, though only Vendor uses it today.
 *
 * One file per row, unlike Vendor's shop-photo/id-proof pair — the
 * trait's default fileDiskPathColumns() (just [fileDiskPathColumn()]) is
 * already correct, no override needed.
 */
class Media extends Model
{
    use RecordsAuditLog;
    use TracksFileDisk;

    protected $fillable = [
        'mediable_type',
        'mediable_id',
        'subcategory_id',
        'disk',
        'path',
        'type',
        'size_bytes',
        'moderation_status',
        'moderated_by',
        'moderated_at',
        'rejection_reason',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'moderated_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function fileDiskPathColumn(): string
    {
        return 'path';
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * The admin moderation queue (SPEC section 5.10).
     */
    public function scopePending($query)
    {
        return $query->where('moderation_status', 'pending');
    }
}
