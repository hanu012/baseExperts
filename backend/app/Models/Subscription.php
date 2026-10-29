<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor's subscription (SPEC section 6), written by
 * SubscriptionService::subscribe() inside one transaction together with
 * subscription_items, a payment, and (when sold by a salesman) a commission.
 *
 * No auditSnapshotAttributes()/auditableTarget() override — unlike Plan,
 * every fact worth logging here is already a plain column, so the trait's
 * default diff-on-update/full-log-on-create is enough (same as User's plain
 * usage).
 */
class Subscription extends Model
{
    use HasFactory;
    use RecordsAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'vendor_id',
        'plan_id',
        'salesman_id',
        'source',
        'status',
        'start_date',
        'end_date',
        'price_paise',
        'duration_days',
        'free_trial_days',
        'previous_subscription_id',
        'is_renewal',
        'idempotency_key',
        'reminder_sent_t15_at',
        'reminder_sent_t7_at',
        'reminder_sent_t1_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'price_paise' => 'integer',
            'duration_days' => 'integer',
            'is_renewal' => 'boolean',
            'free_trial_days' => 'integer',
            'reminder_sent_t15_at' => 'datetime',
            'reminder_sent_t7_at' => 'datetime',
            'reminder_sent_t1_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }

    /**
     * The subscription this one replaced through a plan change (task
     * 4.7) — null for a fresh subscribe.
     */
    public function previousSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'previous_subscription_id');
    }

    /**
     * The subscription that replaced this one, if any (task 4.7).
     */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(Subscription::class, 'previous_subscription_id');
    }

    /**
     * Quota purchased on top of the base plan (SPEC section 3 item 6,
     * task 4.7) — scoped to this subscription, does not carry forward
     * across a plan change.
     */
    public function addons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class);
    }

    /**
     * The plan's bare limit for `$resource` plus whatever add-on
     * quantity has been purchased against this specific subscription
     * (task 4.7) — the number every quota check should actually compare
     * usage against, not `plan.quota.max_*` directly. `$resource` is
     * the plural form PlanQuota::addonPricePerUnit() also uses
     * (`categories`, `subcategories`, `zones`, `photos`, `videos`).
     */
    public function effectiveQuota(string $resource): int
    {
        $quota = $this->plan->quota;
        $baseMax = match ($resource) {
            'categories' => $quota->max_categories,
            'subcategories' => $quota->max_subcategories,
            'zones' => $quota->max_zones,
            'photos' => $quota->max_photos,
            'videos' => $quota->max_videos,
            default => throw new \InvalidArgumentException("Unknown quota resource: {$resource}"),
        };

        // Only paid add-ons: one bought online stays pending_payment until
        // Razorpay confirms it.
        $addonQuantity = $this->addons()
            ->where('resource', $resource)
            ->where('status', 'paid')
            ->sum('quantity');

        return $baseMax + (int) $addonQuantity;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
