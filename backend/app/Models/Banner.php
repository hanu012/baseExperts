<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use App\Models\Concerns\TracksFileDisk;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * In-app banner (SPEC section 5 item 5) — targeted per flavour with a
 * scheduled window and click tracking.
 *
 * Deliberately NOT subject to SPEC section 10's no-hard-delete rule:
 * that rule exists specifically because subscription_items references
 * categories/subcategories/zones without a real foreign key (item_id
 * has no FK across the item_type enum), so deleting one would orphan
 * live subscriptions. Nothing anywhere references a banner by id —
 * it's ephemeral promotional content, not master data something else
 * depends on. No SoftDeletes either: CLAUDE.md's SoftDeletes list
 * (users, vendors, subscriptions) is a closed, deliberate enumeration
 * and banners aren't on it.
 */
class Banner extends Model
{
    use HasFactory;
    use HasTranslations;
    use RecordsAuditLog;
    use TracksFileDisk;

    /** Stored per language (en / hi / gu); reads return the request locale, falling back to English. */
    public array $translatable = ['title'];

    protected $fillable = [
        'target_app',
        'title',
        'position',
        'image_path',
        'disk',
        'link_url',
        'starts_at',
        'ends_at',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'click_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function fileDiskPathColumn(): string
    {
        return 'image_path';
    }

    /**
     * Currently live for the given app: active, within its scheduled
     * window (an unset starts_at/ends_at means no lower/upper bound),
     * optionally narrowed to one placement slot. Exercises the
     * `banners_serving_index` built for exactly this query.
     */
    public function scopeServing($query, string $targetApp, ?string $position = null)
    {
        return $query
            ->where('target_app', $targetApp)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->when($position !== null, fn ($q) => $q->where('position', $position))
            ->orderBy('sort_order');
    }
}
