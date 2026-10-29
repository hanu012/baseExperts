<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-plan limits (SPEC section 5.4), one row per plan.
 *
 * These are the numbers SubscriptionService validates every subscribe,
 * upgrade and add-on against (SPEC section 6.2) — six categories requested
 * against a five-category plan is a 422, not a silent truncation.
 *
 * Managed inside the plan's own form rather than as a separate resource:
 * a quota has no meaning apart from its plan.
 */
class PlanQuota extends Model
{
    use HasFactory;
    use RecordsAuditLog;

    protected $fillable = [
        'plan_id',
        'max_categories',
        'max_subcategories',
        'max_zones',
        'max_photos',
        'max_videos',
        'priority_rank',
        'addon_price_per_category_paise',
        'addon_price_per_subcategory_paise',
        'addon_price_per_zone_paise',
        'addon_price_per_photo_paise',
        'addon_price_per_video_paise',
    ];

    protected function casts(): array
    {
        return [
            'max_categories' => 'integer',
            'max_subcategories' => 'integer',
            'max_zones' => 'integer',
            'max_photos' => 'integer',
            'max_videos' => 'integer',
            'priority_rank' => 'integer',
            'addon_price_per_category_paise' => 'integer',
            'addon_price_per_subcategory_paise' => 'integer',
            'addon_price_per_zone_paise' => 'integer',
            'addon_price_per_photo_paise' => 'integer',
            'addon_price_per_video_paise' => 'integer',
        ];
    }

    /**
     * The per-unit add-on price for a resource (SPEC section 3 item 6 /
     * section 6, task 4.7). `$resource` is `subscription_addons.resource`'s
     * plural form (`categories`, `subcategories`, `zones`, `photos`,
     * `videos`) — mapped explicitly to each column rather than a
     * rtrim('s')-style guess, since "categories" -> "category" isn't a
     * trailing-character trim.
     */
    public function addonPricePerUnit(string $resource): int
    {
        return match ($resource) {
            'categories' => $this->addon_price_per_category_paise,
            'subcategories' => $this->addon_price_per_subcategory_paise,
            'zones' => $this->addon_price_per_zone_paise,
            'photos' => $this->addon_price_per_photo_paise,
            'videos' => $this->addon_price_per_video_paise,
            default => throw new \InvalidArgumentException("Unknown add-on resource: {$resource}"),
        };
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Quota changes are filed against the Plan, not this row.
     *
     * An admin edits "the Gold plan" — the quota is a section of the plan's
     * own form, not something they navigate to separately. Filing both here
     * would split a plan's history across two entity types and force anyone
     * reading it to join them back together.
     */
    public function auditableTarget(): ?Model
    {
        return $this->relationLoaded('plan') ? $this->plan : $this->plan()->first();
    }

    /**
     * Same reasoning as Plan::auditSnapshotAttributes() — a quota edit is
     * exactly the change that nothing else records, so each entry carries the
     * whole set rather than the one field that moved.
     *
     * @return array<string, mixed>
     */
    protected function auditSnapshotAttributes(): array
    {
        return [
            'max_categories' => $this->max_categories,
            'max_subcategories' => $this->max_subcategories,
            'max_zones' => $this->max_zones,
            'max_photos' => $this->max_photos,
            'max_videos' => $this->max_videos,
            'priority_rank' => $this->priority_rank,
            'addon_price_per_category_paise' => $this->addon_price_per_category_paise,
            'addon_price_per_subcategory_paise' => $this->addon_price_per_subcategory_paise,
            'addon_price_per_zone_paise' => $this->addon_price_per_zone_paise,
            'addon_price_per_photo_paise' => $this->addon_price_per_photo_paise,
            'addon_price_per_video_paise' => $this->addon_price_per_video_paise,
        ];
    }

    /**
     * The quota fields as label => value, for the summary card in the plans
     * table. Priority rank is excluded: it is a sort tier, not a limit.
     *
     * @return array<string, int>
     */
    public function limits(): array
    {
        return [
            'Categories' => $this->max_categories,
            'Subcategories' => $this->max_subcategories,
            'Zones' => $this->max_zones,
            'Photos' => $this->max_photos,
            'Videos' => $this->max_videos,
        ];
    }
}
