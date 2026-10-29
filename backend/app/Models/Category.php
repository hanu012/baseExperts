<?php

namespace App\Models;

use App\Models\Concerns\PreventsDeletionWhileSubscribed;
use App\Models\Concerns\TracksFileDisk;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;
    use HasTranslations;
    use PreventsDeletionWhileSubscribed;
    use TracksFileDisk;

    /** Stored per language (en / hi / gu); reads return the request locale, falling back to English. */
    public array $translatable = ['name'];

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'disk',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(Subcategory::class);
    }

    public function subscriptionItemType(): string
    {
        return 'category';
    }

    /**
     * Subcategories cascade at the database level when a category is deleted,
     * which never fires their own model events. So a category is also blocked
     * when any of its subcategories are subscribed to — otherwise deleting
     * the parent is a way around the child's guard.
     *
     * @return array<int, array{type: string, ids: array<int, int>}>
     */
    protected function additionalSubscriptionReferences(): array
    {
        return [[
            'type' => 'subcategory',
            'ids' => $this->subcategories()->pluck('id')->all(),
        ]];
    }

    /**
     * Why this cannot be deleted right now, or null when it can.
     *
     * Subcategories cascade at the database level, so a category that still
     * has any is refused outright rather than silently taking them with it —
     * the admin deletes (or moves) those first, each through its own checks.
     */
    public function deletionBlocker(): ?string
    {
        $children = $this->subcategories()->count();

        if ($children > 0) {
            return "Has {$children} ".Str::plural('subcategory', $children).'. Delete or move them first.';
        }

        $subscriptions = $this->countSubscriptionReferences();

        if ($subscriptions > 0) {
            return "Used by {$subscriptions} ".Str::plural('subscription', $subscriptions).'. Deactivate it instead.';
        }

        return null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
