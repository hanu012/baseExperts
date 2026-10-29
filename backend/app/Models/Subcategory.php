<?php

namespace App\Models;

use App\Models\Concerns\PreventsDeletionWhileSubscribed;
use App\Models\Concerns\TracksFileDisk;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The unit vendor matching actually happens on (SPEC section 4.4) — a vendor
 * who does AC gas filling may not do AC installation.
 */
class Subcategory extends Model
{
    use HasFactory;
    use HasTranslations;
    use PreventsDeletionWhileSubscribed;
    use TracksFileDisk;

    /** Stored per language (en / hi / gu); reads return the request locale, falling back to English. */
    public array $translatable = ['name'];

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'icon',
        'disk',
        'sort_order',
        'is_active',
        'service_type',
        'is_popular',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'is_popular' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subscriptionItemType(): string
    {
        return 'subcategory';
    }

    /**
     * Why this cannot be deleted right now, or null when it can. A lead's
     * subcategory_id is ON DELETE RESTRICT, so leads block it as well as
     * subscriptions.
     */
    public function deletionBlocker(): ?string
    {
        $subscriptions = $this->countSubscriptionReferences();

        if ($subscriptions > 0) {
            return "Used by {$subscriptions} ".Str::plural('subscription', $subscriptions).'. Deactivate it instead.';
        }

        $leads = DB::table('leads')->where('subcategory_id', $this->getKey())->count();

        if ($leads > 0) {
            return "Has {$leads} customer ".Str::plural('lead', $leads).'. Deactivate it instead.';
        }

        return null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
